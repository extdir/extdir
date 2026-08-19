<?php

declare(strict_types=1);

namespace App\Ui\Command;

use App\Catalog\Repository\ExtensionRepository;
use App\Http\SafeFetcher;
use App\Ui\Media\IconUrl;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Confirms that each extension's icon actually exists where its metadata says.
 *
 * Only about one icon path in ten is declared. The rest are the convention
 * `src/Resources/config/plugin.png`, filled in by the metadata extractor when
 * composer.json says nothing — a reasonable default for a Shopware plugin and a
 * guess all the same. Probing a sample of the catalogue found roughly a third of
 * those paths pointing at nothing.
 *
 * That matters more here than a broken image usually would. Icons load only for
 * readers who have explicitly allowed remote media, and the thing they allowed is a
 * request to somebody else's server carrying their IP address. Spending a few
 * hundred of those on URLs known to 404 would be taking the cost and returning none
 * of the benefit, so an icon is offered only once it has been seen to exist.
 *
 * Runs nightly after enrichment, from cron, never from a web request.
 */
#[AsCommand(
    name: 'app:ui:verify-icons',
    description: 'Check that each extension icon exists, so only real ones are offered to browsers',
)]
final class VerifyIconsCommand extends Command
{
    /**
     * Re-check an icon roughly monthly. Repositories get renamed and files get
     * moved, but not often enough to justify several hundred requests every night.
     */
    private const int RECHECK_AFTER_DAYS = 30;

    public function __construct(
        private readonly ExtensionRepository $extensions,
        private readonly SafeFetcher $fetcher,
        private readonly IconUrl $icons,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Re-check every icon, ignoring how recently it was confirmed')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many checks');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $all = true === $input->getOption('all');
        $limitOption = $input->getOption('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : null;

        $cutoff = new \DateTimeImmutable(\sprintf('-%d days', self::RECHECK_AFTER_DAYS));
        $now = new \DateTimeImmutable();

        $found = 0;
        $lost = 0;
        $checked = 0;
        $skipped = 0;

        foreach ($this->extensions->findBy([]) as $extension) {
            if (null !== $limit && $checked >= $limit) {
                break;
            }

            $path = $extension->getIconPath();

            if (null === $path || '' === trim($path)) {
                continue;
            }

            $verifiedAt = $extension->getIconVerifiedAt();

            if (!$all && null !== $verifiedAt && $verifiedAt > $cutoff) {
                ++$skipped;
                continue;
            }

            $url = $this->icons->candidateFor($extension);

            if (null === $url) {
                continue;
            }

            ++$checked;

            if ($this->fetcher->exists($url)) {
                if (null === $verifiedAt) {
                    ++$found;
                }
                $extension->setIconVerifiedAt($now);
                continue;
            }

            // Clearing rather than leaving the old timestamp: a file that has moved
            // should stop being offered, not keep being offered because it was there
            // last month.
            if (null !== $verifiedAt) {
                ++$lost;
            }

            $extension->setIconVerifiedAt(null);
        }

        $this->em->flush();

        $io->success(\sprintf(
            '%d checked, %d newly confirmed, %d no longer present, %d still fresh.',
            $checked,
            $found,
            $lost,
            $skipped,
        ));

        return Command::SUCCESS;
    }
}
