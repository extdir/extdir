<?php

declare(strict_types=1);

namespace App\Distribution\Command;

use App\Catalog\Enum\SourceHost;
use App\Catalog\Repository\ExtensionRepository;
use App\Distribution\DownloadResolver;
use App\Ingestion\GitHub\GitHubClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Works out where every stable release can be downloaded from.
 *
 * The summary it prints is the check on whether §4.3 is actually holding: if the
 * share of releases resolving to a maintainer's own archive or a tag zipball ever
 * collapses, the storage projection collapses with it and building stops being a
 * rare fallback.
 */
#[AsCommand(
    name: 'app:distribution:resolve',
    description: 'Resolve download locations for stable releases, preferring links over builds',
)]
final class ResolveDownloadsCommand extends Command
{
    public function __construct(
        private readonly ExtensionRepository $extensions,
        private readonly DownloadResolver $resolver,
        private readonly GitHubClient $github,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Only process this many extensions')
            ->addOption('package', 'p', InputOption::VALUE_REQUIRED, 'Only process one package');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->github->isAuthorised()) {
            $io->error('GitHub is not authorised. Run app:github:authorize first.');

            return Command::FAILURE;
        }

        // Only ids are held across the loop. The entity manager is cleared
        // periodically to keep memory flat over a few hundred extensions, and a
        // cleared manager detaches everything it had loaded — so keeping entity
        // references here would leave the resolver writing through objects
        // Doctrine no longer tracks, which surfaces as "a new entity was found
        // through the relationship" rather than as anything resembling the cause.
        $package = $input->getOption('package');
        if (\is_string($package) && '' !== $package) {
            $extension = $this->extensions->findOneByPackageName($package);
            $ids = null === $extension ? [] : [(int) $extension->getId()];
        } else {
            $ids = array_map(
                static fn ($e): int => (int) $e->getId(),
                array_filter(
                    $this->extensions->findBy([]),
                    static fn ($e): bool => SourceHost::GitHub === $e->getSourceHost(),
                ),
            );
            $ids = array_values($ids);
        }

        $limit = $input->getOption('limit');
        if (\is_string($limit) && ctype_digit($limit)) {
            $ids = \array_slice($ids, 0, (int) $limit);
        }

        if ([] === $ids) {
            $io->warning('Nothing to resolve.');

            return Command::SUCCESS;
        }

        $this->em->clear();
        $io->progressStart(\count($ids));

        $totals = [];
        $resolved = 0;
        $withoutAnyLink = [];

        foreach ($ids as $index => $id) {
            $extension = $this->extensions->find($id);

            if (null === $extension) {
                $io->progressAdvance();
                continue;
            }

            $result = $this->resolver->resolveExtension($extension);
            $resolved += $result['resolved'];

            foreach ($result['bySource'] as $source => $count) {
                $totals[$source] = ($totals[$source] ?? 0) + $count;
            }

            if (0 === $result['resolved']) {
                $withoutAnyLink[] = $extension->getPackageName();
            }

            if (0 === ($index + 1) % 50) {
                $this->em->clear();
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        $rows = [];
        foreach ($totals as $source => $count) {
            $rows[] = [
                $source,
                $count,
                $resolved > 0 ? \sprintf('%.1f%%', $count / $resolved * 100) : '—',
            ];
        }
        $io->table(['Source', 'Releases', 'Share'], $rows);

        $io->success(\sprintf(
            '%d releases resolved across %d extensions. %d extensions have no downloadable release.',
            $resolved,
            \count($ids),
            \count($withoutAnyLink),
        ));

        if ([] !== $withoutAnyLink && $output->isVerbose()) {
            $io->section('No downloadable release — build candidates');
            $io->listing(\array_slice($withoutAnyLink, 0, 40));
        }

        return Command::SUCCESS;
    }
}
