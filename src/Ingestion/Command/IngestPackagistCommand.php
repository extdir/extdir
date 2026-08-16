<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\PackageIngestor;
use App\Ingestion\Packagist\PackagistClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Discovers Shopware plugins on Packagist and ingests their metadata.
 *
 * Runs synchronously. At 455 packages and one HTTP request each, a full crawl is a
 * few minutes of work — small enough that the queue would add moving parts without
 * buying anything. When ingestion grows to cover GitLab and Gitea namespaces this
 * becomes a producer that dispatches per-package messages instead.
 */
#[AsCommand(
    name: 'app:ingest:packagist',
    description: 'Discover and ingest Shopware extensions from Packagist',
)]
final class IngestPackagistCommand extends Command
{
    public function __construct(
        private readonly PackagistClient $packagist,
        private readonly PackageIngestor $ingestor,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Stop after this many packages')
            ->addOption('package', 'p', InputOption::VALUE_REQUIRED, 'Ingest a single package instead of crawling');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $single = $input->getOption('package');
        if (\is_string($single) && '' !== $single) {
            return $this->ingestOne($io, $single);
        }

        $io->title('Ingesting Shopware extensions from Packagist');

        $packages = $this->packagist->listPackageNames();
        if ([] === $packages) {
            $io->error('Packagist returned an empty package list.');

            return Command::FAILURE;
        }

        $limit = $input->getOption('limit');
        if (\is_string($limit) && ctype_digit($limit)) {
            $packages = \array_slice($packages, 0, (int) $limit);
        }

        $io->progressStart(\count($packages));

        $ingested = 0;
        $skipped = 0;
        $failed = [];

        foreach ($packages as $index => $packageName) {
            try {
                $versions = $this->packagist->fetchVersions($packageName);
                $extension = $this->ingestor->ingest($packageName, $versions);

                null === $extension ? ++$skipped : ++$ingested;
            } catch (\Throwable $e) {
                // One malformed package must not abort a crawl of 455. The failures
                // are reported at the end so they can be investigated rather than
                // scrolling past in a progress bar.
                $failed[$packageName] = $e->getMessage();
            }

            // Doctrine's identity map holds every entity touched so far; without
            // periodic clearing a full crawl grows until it hits the memory limit.
            if (0 === ($index + 1) % 50) {
                $this->em->clear();
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        $io->success(\sprintf(
            '%d ingested, %d skipped (no usable versions), %d failed.',
            $ingested,
            $skipped,
            \count($failed),
        ));

        if ([] !== $failed) {
            $io->section('Failures');
            foreach ($failed as $package => $message) {
                $io->writeln(\sprintf(' <comment>%s</comment>: %s', $package, $message));
            }
        }

        return Command::SUCCESS;
    }

    private function ingestOne(SymfonyStyle $io, string $packageName): int
    {
        $versions = $this->packagist->fetchVersions($packageName);

        if ([] === $versions) {
            $io->error(\sprintf('Packagist returned no versions for "%s".', $packageName));

            return Command::FAILURE;
        }

        $extension = $this->ingestor->ingest($packageName, $versions);

        if (null === $extension) {
            $io->warning(\sprintf('Nothing ingestable in "%s".', $packageName));

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Package' => $extension->getPackageName()],
            ['Label' => $extension->getLabel()],
            ['Slug' => $extension->getSlug()],
            ['License' => ($extension->getLicenseSpdx() ?? '—').' ('.$extension->getLicenseStatus()->value.')'],
            ['Index status' => $extension->getIndexStatus()->value],
            ['Technical name' => $extension->getTechnicalName() ?? '—'],
            ['Repository' => $extension->getRepositoryUrl() ?? '—'],
            ['Releases' => (string) $extension->getReleases()->count()],
        );

        return Command::SUCCESS;
    }
}
