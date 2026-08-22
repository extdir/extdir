<?php

declare(strict_types=1);

namespace App\Signals\Command;

use App\Catalog\Repository\ExtensionRepository;
use App\Signals\PackagistEnricher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Refreshes Packagist install counts for the boards.
 *
 * Weekly rather than nightly, and separate from app:signals:refresh rather than folded
 * into it. Two reasons, both deliberate: these numbers barely move from one day to the
 * next, so a nightly sweep would spend several hundred requests against a tightly rate
 * limited service to change almost nothing; and keeping it apart means a Packagist
 * outage cannot take the GitHub signals down with it, or the reverse.
 *
 * Nothing this command writes affects ranking. The counts it stores are shown on
 * /boards and read by no scoring code anywhere — see RankingScore, which does not
 * accept them as an argument, and cannot be given them without changing its signature.
 */
#[AsCommand(
    name: 'app:signals:packagist',
    description: 'Refresh Packagist install counts (shown on the boards, never ranked)',
)]
final class RefreshPackagistStatsCommand extends Command
{
    public function __construct(
        private readonly ExtensionRepository $extensions,
        private readonly PackagistEnricher $enricher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            'l',
            InputOption::VALUE_REQUIRED,
            'Only fetch this many packages — use it to try a slice before a full sweep',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = $input->getOption('limit');
        $limit = null !== $limit ? max(1, (int) $limit) : null;

        $extensions = $this->extensions->findPubliclyVisible();

        $io->text(\sprintf('%d extensions indexed. Asking Packagist which of them it carries…', \count($extensions)));

        $result = $this->enricher->enrich($extensions, $limit);

        $io->success(\sprintf('%d updated from Packagist.', $result['updated']));

        // Reported rather than hidden, because it is the number the boards depend on
        // being honest about: an extension absent from Packagist has no install count,
        // which is not the same as having none.
        $io->text(\sprintf(
            '%d not on Packagist (no request spent on them), %d listed but unreadable.',
            $result['absent'],
            $result['unreadable'],
        ));

        if ($result['rateLimited']) {
            $io->warning('Packagist rate limited the sweep, so it stopped early. The rest keep last week\'s numbers.');
        }

        return Command::SUCCESS;
    }
}
