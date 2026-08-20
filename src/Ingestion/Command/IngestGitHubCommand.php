<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Catalog\Enum\DiscoverySource;
use App\Catalog\Repository\ExtensionRepository;
use App\Ingestion\GitHub\GitHubClient;
use App\Ingestion\GitHub\GitHubComposerProbe;
use App\Ingestion\GitHub\GitHubPackageAssembler;
use App\Ingestion\GitHub\GitHubRepositoryDiscovery;
use App\Ingestion\GitHub\GitHubTopicDiscovery;
use App\Ingestion\PackageIngestor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
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
 * findability but nothing a developer could not get elsewhere. An extension that never
 * reached Packagist is genuinely hard to discover today, and is exactly what a merchant
 * asking "is there an open-source extension that does X" is missing.
 *
 * Two channels feed it. Topics find maintainers who labelled their work; repository
 * search finds the ones who did not. The second exists because a maintainer asked why
 * their plugin was absent and the answer was that it had neither a Packagist entry nor
 * a single topic — invisible to everything we had.
 *
 * Packagist stays the source of truth where both exist: a package already indexed from
 * there is skipped rather than overwritten, because Packagist's metadata is complete
 * and versioned while this path reads a bounded window of tags.
 */
#[AsCommand(
    name: 'app:ingest:github',
    description: 'Discover Shopware extensions on GitHub by topic and repository search, and ingest the ones not on Packagist',
)]
final class IngestGitHubCommand extends Command
{
    /**
     * Requests to keep in reserve before starting a sweep.
     *
     * A sweep that runs out of budget half way leaves the catalogue holding whichever
     * extensions happened to sort first, and the operator with no signal that anything
     * was missed. Refusing to start is recoverable; stopping in the middle looks like
     * success.
     */
    private const int REQUIRED_BUDGET = 500;

    public function __construct(
        private readonly GitHubTopicDiscovery $topics,
        private readonly GitHubRepositoryDiscovery $repositories,
        private readonly GitHubComposerProbe $probe,
        private readonly GitHubPackageAssembler $assembler,
        private readonly PackageIngestor $ingestor,
        private readonly ExtensionRepository $extensions,
        private readonly GitHubClient $github,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', 'c', InputOption::VALUE_REQUIRED, 'Which discovery to run: topic, search or all', 'all')
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

        $channel = (string) $input->getOption('channel');

        if (!\in_array($channel, ['topic', 'search', 'all'], true)) {
            $io->error(\sprintf('Unknown channel "%s". Use topic, search or all.', $channel));

            return Command::FAILURE;
        }

        $single = $input->getOption('repository');
        $dryRun = (bool) $input->getOption('dry-run');

        if (\is_string($single) && '' !== $single) {
            $candidates = [$single => DiscoverySource::GitHubTopic];
        } else {
            if (!$this->hasBudgetFor($io)) {
                return Command::FAILURE;
            }

            $candidates = $this->discover($channel, $io);
        }

        if ([] === $candidates) {
            $io->warning('No candidate repositories found.');

            return Command::SUCCESS;
        }

        $limit = $input->getOption('limit');
        if (\is_string($limit) && ctype_digit($limit)) {
            $candidates = \array_slice($candidates, 0, (int) $limit, true);
        }

        $io->writeln(\sprintf(' %d candidate repositories.', \count($candidates)));

        [$candidates, $skippedKnown] = $this->dropAlreadyHandled($candidates);

        if ($skippedKnown > 0) {
            $io->writeln(\sprintf(
                ' %d already indexed from Packagist and refreshed there — not re-fetched.',
                $skippedKnown,
            ));
        }

        // The probe is what makes a wide net affordable: roughly nine in ten search
        // candidates are not extensions, and finding that out through the assembler
        // would cost two GraphQL round trips each instead of one twenty-fifth of one.
        $candidates = $this->probeUnknown($candidates, $io);

        if ([] === $candidates) {
            $io->success('Nothing new to ingest.');

            return Command::SUCCESS;
        }

        return $this->ingest($candidates, $dryRun, $io, $output);
    }

    /**
     * @return array<string, DiscoverySource> candidate repositories, keyed by full name
     */
    private function discover(string $channel, SymfonyStyle $io): array
    {
        $candidates = [];

        // Topics first, so a repository found by both keeps the channel that has the
        // stronger claim: the maintainer labelled it deliberately. Without a fixed
        // order the recorded source would flip on alternate sweeps.
        if ('topic' === $channel || 'all' === $channel) {
            foreach ($this->topics->discover() as $fullName) {
                $candidates[$fullName] = DiscoverySource::GitHubTopic;
            }

            $io->writeln(\sprintf(' %d from topics.', \count($candidates)));
        }

        if ('search' === $channel || 'all' === $channel) {
            $before = \count($candidates);

            foreach ($this->repositories->discover() as $fullName) {
                if (!isset($candidates[$fullName])) {
                    $candidates[$fullName] = DiscoverySource::GitHubSearch;
                }
            }

            $io->writeln(\sprintf(' %d more from repository search.', \count($candidates) - $before));
        }

        return $candidates;
    }

    /**
     * Drops candidates whose repository is already indexed and kept fresh elsewhere.
     *
     * Not a blanket "skip if known". An extension that came from Packagist has its
     * releases refreshed nightly from there, so re-reading its tags here is pure waste.
     * One found on GitHub has no other refresh path — this command is the only thing
     * that ever picks up its new tags — so it is deliberately re-assembled.
     *
     * @param array<string, DiscoverySource> $candidates
     *
     * @return array{array<string, DiscoverySource>, int}
     */
    private function dropAlreadyHandled(array $candidates): array
    {
        $known = $this->extensions->findAllRepositorySlugs();
        $keep = [];
        $skipped = 0;

        foreach ($candidates as $fullName => $source) {
            $slug = strtolower($fullName);

            if (isset($known[$slug]) && $known[$slug]->isOnPackagist()) {
                ++$skipped;
                continue;
            }

            $keep[$fullName] = $source;
        }

        return [$keep, $skipped];
    }

    /**
     * @param array<string, DiscoverySource> $candidates
     *
     * @return array<string, DiscoverySource>
     */
    private function probeUnknown(array $candidates, SymfonyStyle $io): array
    {
        $names = array_keys($candidates);
        $extensions = $this->probe->filterToExtensions($names);

        $io->writeln(\sprintf(
            ' %d of %d declare %s.',
            \count($extensions),
            \count($names),
            'shopware-platform-plugin',
        ));

        $kept = [];

        foreach ($extensions as $fullName) {
            $kept[$fullName] = $candidates[$fullName];
        }

        return $kept;
    }

    /**
     * @param array<string, DiscoverySource> $candidates
     */
    private function ingest(array $candidates, bool $dryRun, SymfonyStyle $io, OutputInterface $output): int
    {
        $known = $this->extensions->findAllPackageNames();

        $io->progressStart(\count($candidates));

        $ingested = [];
        $notAnExtension = 0;
        $alreadyKnown = 0;
        $failed = [];
        $index = 0;

        foreach ($candidates as $fullName => $source) {
            try {
                $assembled = $this->assembler->assemble($fullName);

                if (null === $assembled) {
                    // The probe said yes and the assembler said no: usually a
                    // repository with no usable tags, or one renamed since the search.
                    ++$notAnExtension;
                } elseif (isset($known[$assembled['package']])) {
                    ++$alreadyKnown;
                } else {
                    if (!$dryRun) {
                        $this->ingestor->ingest($assembled['package'], $assembled['versions'], $source);
                    }

                    // Recorded immediately: forks share a package name, and two
                    // channels can surface the same repository under different casing.
                    $known[$assembled['package']] = true;
                    $ingested[] = $assembled['package'];
                }
            } catch (\Throwable $e) {
                $failed[$fullName] = $e->getMessage();

                // Doctrine closes the EntityManager on any failed query, and every
                // later write then throws "The EntityManager is closed" — so a single
                // bad package silently took out the remaining 95 of a sweep the first
                // time this was run for real. Catching per candidate is not enough on
                // its own; the manager has to be replaced before continuing.
                if (!$this->em->isOpen()) {
                    $this->registry->resetManager();
                    $known = $this->extensions->findAllPackageNames();
                }
            }

            if (0 === (++$index) % 25) {
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
                ['Already indexed under this package name', $alreadyKnown],
                ['No usable release after all', $notAnExtension],
                ['Failed', \count($failed)],
            ],
        );

        if ([] !== $ingested) {
            $io->section('New extensions');

            // Truncated by default because a sweep can add a hundred at once, but
            // never silently: a list that stops at 40 with no note reads as a complete
            // answer, and the question being asked is usually "did it find X?".
            if ($output->isVerbose() || \count($ingested) <= 40) {
                $io->listing($ingested);
            } else {
                $io->listing(\array_slice($ingested, 0, 40));
                $io->writeln(\sprintf(' … and %d more. Re-run with -v to list them all.', \count($ingested) - 40));
            }
        }

        if ([] !== $failed && $output->isVerbose()) {
            $io->section('Failures');
            foreach ($failed as $repository => $message) {
                $io->writeln(\sprintf(' <comment>%s</comment>: %s', $repository, $message));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * The client has no backoff of any kind, so this is the only thing standing
     * between a sweep and a half-written catalogue.
     */
    private function hasBudgetFor(SymfonyStyle $io): bool
    {
        $remaining = $this->github->remainingRequests();

        if (null === $remaining) {
            // Could not ask. Proceeding is the old behaviour and the failure mode is
            // visible in the per-repository error list.
            return true;
        }

        if ($remaining < self::REQUIRED_BUDGET) {
            $io->error(\sprintf(
                'Only %d GitHub requests left this hour; a sweep needs about %d. Not starting.',
                $remaining,
                self::REQUIRED_BUDGET,
            ));

            return false;
        }

        return true;
    }
}
