<?php

declare(strict_types=1);

namespace App\Signals\Command;

use App\Catalog\Repository\ExtensionRepository;
use App\Signals\PlumbEnricher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Refreshes Plumb's supply-chain security findings.
 *
 * Weekly, and separate from app:signals:refresh rather than folded into it. Plumb
 * rescans on a cadence of 168 hours, so asking more often returns the same answer and
 * spends somebody else's bandwidth to learn nothing. Keeping it apart also means a
 * Plumb outage cannot take the GitHub signals down with it, or the reverse.
 *
 * Nothing this command writes affects ranking. These are a third party's findings,
 * produced by an algorithm this project does not control and cannot publish as its
 * own, and the conflict-of-interest rule requires the ranking algorithm to be public.
 * They are shown on the extension page and read by no scoring code anywhere. See
 * RankingScore, which does not accept them as an argument and cannot be given them
 * without changing its signature, and the test that fails the build if it ever does.
 */
#[AsCommand(
    name: 'app:signals:plumb',
    description: 'Refresh Plumb security findings (shown on extension pages, never ranked)',
)]
final class RefreshPlumbCommand extends Command
{
    public function __construct(
        private readonly ExtensionRepository $extensions,
        private readonly PlumbEnricher $enricher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            'l',
            InputOption::VALUE_REQUIRED,
            'Only fetch this many packages, use it to try a slice before a full sweep',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = $input->getOption('limit');
        $limit = null !== $limit ? max(1, (int) $limit) : null;

        $extensions = $this->extensions->findPubliclyVisible();

        $io->text(\sprintf('%d extensions indexed. Asking Plumb about the ones on Packagist...', \count($extensions)));

        $result = $this->enricher->enrich($extensions, $limit);

        $io->success(\sprintf('%d scored by Plumb.', $result['scanned']));

        // Reported rather than hidden, because these two numbers are the honesty of
        // the whole feature. An extension Plumb has not reached has no score, which
        // is not the same as scoring badly, and one that is not on Packagist will
        // never have a score at all.
        $io->text(\sprintf(
            '%d on Packagist but not yet scanned by Plumb, %d not on Packagist (no request spent on them), %d unreadable.',
            $result['unscanned'],
            $result['absent'],
            $result['failed'],
        ));

        if ($result['stopped']) {
            $io->warning('Plumb stopped answering, so the sweep ended early. The rest keep last week\'s findings.');
        }

        return Command::SUCCESS;
    }
}
