<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Catalog\Enum\DiscoverySource;
use App\Catalog\Repository\ExtensionRepository;
use App\Ingestion\GitHub\GitHubClient;
use App\Ingestion\GitHub\GitHubPackageAssembler;
use App\Ingestion\GitHub\GitHubTopicDiscovery;
use App\Ingestion\PackageIngestor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Indexes Shopware extensions published to GitHub but never to Packagist.
 *
 * This is the source that makes the directory worth visiting. Everything ingested
 * from Packagist is already one `composer require` away, so indexing it adds
 * findability but nothing a developer could not get elsewhere. An extension with a
 * `shopware6` topic and no Packagist entry is genuinely hard to discover today, and
 * is exactly what a merchant asking "is there an open-source extension that does X"
 * is missing.
 *
 * Packagist stays the source of truth where both exist: a package already indexed
 * from there is skipped rather than overwritten, because Packagist's metadata is
 * complete and versioned while this path reads a bounded window of tags.
 */
#[AsCommand(
    name: 'app:ingest:github',
    description: 'Discover Shopware extensions by GitHub topic and ingest the ones not on Packagist',
)]
final class IngestGitHubCommand extends Command
{
    public function __construct(
        private readonly GitHubTopicDiscovery $discovery,
        private readonly GitHubPackageAssembler $assembler,
        private readonly PackageIngestor $ingestor,
        private readonly ExtensionRepository $extensions,
        private readonly GitHubClient $github,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Stop after this many candidate repositories')
            ->addOption('repository', 'r', InputOption::VALUE_REQUIRED, 'Ingest a single repository, e.g. owner/name')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be ingested without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->github->isAuthorised()) {
            $io->error('GitHub is not authorised. Run app:github:authorize first.');

            return Command::FAILURE;
        }

        $single = $input->getOption('repository');
        $candidates = \is_string($single) && '' !== $single
            ? [$single]
            : $this->discovery->discover();

        if ([] === $candidates) {
            $io->warning('No candidate repositories found.');

            return Command::SUCCESS;
        }

        $limit = $input->getOption('limit');
        if (\is_string($limit) && ctype_digit($limit)) {
            $candidates = \array_slice($candidates, 0, (int) $limit);
        }

        $io->writeln(\sprintf(' %d candidate repositories from GitHub topics.', \count($candidates)));

        $known = $this->extensions->findAllPackageNames();
        $dryRun = (bool) $input->getOption('dry-run');

        $io->progressStart(\count($candidates));

        $ingested = [];
        $notAnExtension = 0;
        $alreadyKnown = 0;
        $failed = [];

        foreach ($candidates as $index => $fullName) {
            try {
                $assembled = $this->assembler->assemble($fullName);

                if (null === $assembled) {
                    ++$notAnExtension;
                } elseif (isset($known[$assembled['package']])) {
                    ++$alreadyKnown;
                } else {
                    if (!$dryRun) {
                        $this->ingestor->ingest(
                            $assembled['package'],
                            $assembled['versions'],
                            DiscoverySource::GitHubTopic,
                        );
                    }

                    // Recorded immediately: two topics can surface the same
                    // repository under different casing, and forks share a name.
                    $known[$assembled['package']] = true;
                    $ingested[] = $assembled['package'];
                }
            } catch (\Throwable $e) {
                $failed[$fullName] = $e->getMessage();
            }

            if (0 === ($index + 1) % 25) {
                $this->em->clear();
                $known = $this->extensions->findAllPackageNames();
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        $io->table(
            ['Outcome', 'Repositories'],
            [
                [$dryRun ? 'Would ingest (new)' : 'Ingested (new)', \count($ingested)],
                ['Already indexed from Packagist', $alreadyKnown],
                ['Not a Shopware 6 extension', $notAnExtension],
                ['Failed', \count($failed)],
            ],
        );

        if ([] !== $ingested) {
            $io->section('New extensions');
            $io->listing(\array_slice($ingested, 0, 40));
        }

        if ([] !== $failed && $output->isVerbose()) {
            $io->section('Failures');
            foreach ($failed as $repository => $message) {
                $io->writeln(\sprintf(' <comment>%s</comment>: %s', $repository, $message));
            }
        }

        return Command::SUCCESS;
    }
}
