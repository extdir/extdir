<?php

declare(strict_types=1);

namespace App\Signals;

use App\Catalog\Entity\Extension;
use App\Ingestion\GitHub\GitHubClient;
use App\Signals\Entity\RepositorySnapshot;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Collects repository health signals from GitHub.
 *
 * Batched deliberately: one GraphQL query carries many repositories as aliased
 * fields, so the whole corpus costs tens of requests rather than thousands. That
 * headroom is what lets enrichment run often enough for "last commit" to mean
 * something.
 */
final class RepositoryEnricher
{
    /**
     * Repositories per query.
     *
     * GraphQL is capped by query complexity rather than field count, and each
     * repository here pulls two issue aggregates plus a commit. Twenty-five stays
     * comfortably inside the limit while keeping a full corpus pass to ~17 calls.
     */
    private const BATCH_SIZE = 25;

    public function __construct(
        private readonly GitHubClient $github,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<Extension> $extensions
     *
     * @return array{enriched: int, skipped: int}
     */
    public function enrich(array $extensions): array
    {
        $targets = [];
        $skipped = 0;

        foreach ($extensions as $extension) {
            $repo = self::parseRepository($extension->getRepositoryUrl());
            if (null === $repo) {
                ++$skipped;
                continue;
            }

            $targets[] = ['extension' => $extension, 'owner' => $repo[0], 'name' => $repo[1]];
        }

        $enriched = 0;

        foreach (array_chunk($targets, self::BATCH_SIZE) as $batch) {
            $enriched += $this->enrichBatch($batch);
        }

        $this->em->flush();

        return ['enriched' => $enriched, 'skipped' => $skipped];
    }

    /**
     * @param list<array{extension: Extension, owner: string, name: string}> $batch
     */
    private function enrichBatch(array $batch): int
    {
        $data = $this->github->graphql(...$this->buildQuery($batch));

        if (null === $data) {
            return 0;
        }

        $enriched = 0;

        foreach ($batch as $index => $target) {
            $repo = $data['r'.$index] ?? null;

            // A null alias means that one repository is gone, renamed or private.
            // The rest of the batch is still good, so this is not a batch failure.
            if (!\is_array($repo)) {
                continue;
            }

            $this->applySnapshot($target['extension'], $repo);
            ++$enriched;
        }

        return $enriched;
    }

    /**
     * @param list<array{extension: Extension, owner: string, name: string}> $batch
     *
     * @return array{string, array<string, mixed>}
     */
    private function buildQuery(array $batch): array
    {
        $declarations = [];
        $fields = [];
        $variables = [];

        foreach ($batch as $index => $target) {
            // Owner and name travel as GraphQL variables rather than being
            // interpolated into the query string. Repository names come from
            // third-party composer.json files, so they are untrusted input.
            $declarations[] = \sprintf('$o%d: String!, $n%d: String!', $index, $index);
            $variables['o'.$index] = $target['owner'];
            $variables['n'.$index] = $target['name'];

            $fields[] = \sprintf(
                'r%1$d: repository(owner: $o%1$d, name: $n%1$d) { ...repoFields }',
                $index,
            );
        }

        $query = \sprintf(
            'query(%s) { %s } %s',
            implode(', ', $declarations),
            implode(' ', $fields),
            self::REPO_FRAGMENT,
        );

        return [$query, $variables];
    }

    private const REPO_FRAGMENT = <<<'GRAPHQL'
        fragment repoFields on Repository {
            stargazerCount
            forkCount
            isArchived
            isDisabled
            pushedAt
            licenseInfo { spdxId }
            defaultBranchRef {
                name
                target {
                    ... on Commit {
                        committedDate
                        statusCheckRollup { state }
                    }
                }
            }
            openIssues: issues(states: OPEN) { totalCount }
            closedIssues: issues(states: CLOSED) { totalCount }
        }
        GRAPHQL;

    /**
     * @param array<string, mixed> $repo
     */
    private function applySnapshot(Extension $extension, array $repo): void
    {
        $branch = \is_array($repo['defaultBranchRef'] ?? null) ? $repo['defaultBranchRef'] : [];
        $target = \is_array($branch['target'] ?? null) ? $branch['target'] : [];

        $lastCommit = \is_string($target['committedDate'] ?? null)
            ? new \DateTimeImmutable($target['committedDate'])
            : null;

        $rollup = \is_array($target['statusCheckRollup'] ?? null) ? $target['statusCheckRollup'] : [];

        $snapshot = new RepositorySnapshot($extension);
        $snapshot
            ->setCounts(
                self::intOf($repo['stargazerCount'] ?? null),
                self::intOf($repo['forkCount'] ?? null),
                self::intOf($repo['openIssues']['totalCount'] ?? null),
                self::intOf($repo['closedIssues']['totalCount'] ?? null),
            )
            ->setLastCommitAt($lastCommit)
            ->setDefaultBranch(\is_string($branch['name'] ?? null) ? $branch['name'] : null)
            ->setCiStatus(\is_string($rollup['state'] ?? null) ? strtolower($rollup['state']) : null)
            ->setArchived(true === ($repo['isArchived'] ?? false));

        $this->em->persist($snapshot);

        $extension->setLastCommitAt($lastCommit);
    }

    private static function intOf(mixed $value): int
    {
        return \is_int($value) ? $value : 0;
    }

    /**
     * Splits a GitHub URL into owner and repository name.
     *
     * @return array{string, string}|null
     */
    public static function parseRepository(?string $url): ?array
    {
        if (null === $url) {
            return null;
        }

        if (1 !== preg_match('#^https?://(?:www\.)?github\.com/([^/]+)/([^/]+?)(?:\.git)?/?$#i', $url, $m)) {
            return null;
        }

        return [$m[1], $m[2]];
    }
}
