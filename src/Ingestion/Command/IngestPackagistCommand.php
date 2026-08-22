<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Catalog\Enum\DiscoverySource;
use App\Catalog\Repository\ExtensionRepository;
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
 * few minutes of work, small enough that the queue would add moving parts without
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
        private readonly ExtensionRepository $extensions,
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

        $corrected = $this->markPackagesMissingFromPackagist($packages, null === $limit || !\is_string($limit));
        if ($corrected > 0) {
            $io->writeln(\sprintf(' %d extensions marked as not available on Packagist.', $corrected));
        }

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

    /**
     * Packagist's list is the authority on what Packagist carries, so anything the
     * index holds that the list does not mention is not installable from there.
     *
     * That fact drives the Composer repository: publishing a package Packagist
     * already serves would insert us into an install path that works without us,
     * and failing to publish one it does not serve leaves it uninstallable. Deriving
     * it from the crawl keeps it self-correcting, a package that later appears on
     * Packagist stops being published here on the next run.
     *
     * @param list<string> $packagistPackages
     */
    private function markPackagesMissingFromPackagist(array $packagistPackages, bool $fullCrawl): int
    {
        // A truncated crawl says nothing about the packages it never looked at.
        if (!$fullCrawl) {
            return 0;
        }

        $onPackagist = array_fill_keys($packagistPackages, true);
        $corrected = 0;

        foreach ($this->extensions->findBy([]) as $extension) {
            $listed = isset($onPackagist[$extension->getPackageName()]);
            $current = $extension->getDiscoverySource();

            if ($listed) {
                if (!$current->isOnPackagist()) {
                    $extension->forceDiscoverySource(DiscoverySource::Packagist);
                    ++$corrected;
                }

                continue;
            }

            // Not on Packagist. This pass knows that much and no more: topic, search
            // and submitted are all equally "not on Packagist", and it cannot tell
            // which one found the extension. So it corrects a stale Packagist claim
            // and otherwise leaves the recorded channel alone, rewriting them all to
            // GitHubTopic, as it used to, erased the provenance of every extension
            // found any other way.
            if ($current->isOnPackagist()) {
                $extension->forceDiscoverySource(DiscoverySource::GitHubTopic);
                ++$corrected;
            }
        }

        $this->em->flush();

        return $corrected;
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
            ['License' => ($extension->getLicenseSpdx() ?? '-').' ('.$extension->getLicenseStatus()->value.')'],
            ['Index status' => $extension->getIndexStatus()->value],
            ['Technical name' => $extension->getTechnicalName() ?? '-'],
            ['Repository' => $extension->getRepositoryUrl() ?? '-'],
            ['Releases' => (string) $extension->getReleases()->count()],
        );

        return Command::SUCCESS;
    }
}
