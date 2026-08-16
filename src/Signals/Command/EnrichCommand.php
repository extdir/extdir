<?php

declare(strict_types=1);

namespace App\Signals\Command;

use App\Catalog\Enum\SourceHost;
use App\Catalog\Repository\ExtensionRepository;
use App\Ingestion\GitHub\GitHubClient;
use App\Signals\RepositoryEnricher;
use App\Signals\SignalsRecomputer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Collects GitHub repository signals and recomputes maintenance status and ranking.
 *
 * Fetching and scoring are separable on purpose: `--no-fetch` re-scores the whole
 * corpus from stored data in seconds, which is what makes iterating on the ranking
 * formula cheap enough to do carefully.
 */
#[AsCommand(
    name: 'app:signals:refresh',
    description: 'Enrich extensions from GitHub and recompute maintenance and ranking',
)]
final class EnrichCommand extends Command
{
    public function __construct(
        private readonly ExtensionRepository $extensions,
        private readonly RepositoryEnricher $enricher,
        private readonly SignalsRecomputer $recomputer,
        private readonly GitHubClient $github,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('no-fetch', null, InputOption::VALUE_NONE, 'Skip GitHub and only re-score stored data')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Only enrich this many extensions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('no-fetch')) {
            if (!$this->github->isAuthorised()) {
                $io->error('GitHub is not authorised. Run app:github:authorize first.');

                return Command::FAILURE;
            }

            $io->section('Fetching repository signals from GitHub');

            $targets = array_values(array_filter(
                $this->extensions->findBy([]),
                static fn ($e): bool => SourceHost::GitHub === $e->getSourceHost(),
            ));

            $limit = $input->getOption('limit');
            if (\is_string($limit) && ctype_digit($limit)) {
                $targets = \array_slice($targets, 0, (int) $limit);
            }

            $result = $this->enricher->enrich($targets);

            $io->writeln(\sprintf(
                ' %d enriched, %d skipped (no parsable GitHub URL).',
                $result['enriched'],
                $result['skipped'],
            ));
        }

        $io->section('Recomputing maintenance status and ranking');

        $result = $this->recomputer->recompute();

        $rows = [];
        foreach ($result['statuses'] as $status => $count) {
            $rows[] = [$status, $count];
        }
        $io->table(['Maintenance status', 'Extensions'], $rows);

        $io->success(\sprintf('%d extensions scored.', $result['scored']));

        return Command::SUCCESS;
    }
}
