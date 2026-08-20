<?php

declare(strict_types=1);

namespace App\Ingestion\GitHub;

use App\Ingestion\ShopwarePackageType;
use Psr\Log\LoggerInterface;

/**
 * Asks, cheaply and in bulk, which of these repositories are Shopware extensions.
 *
 * Repository search is a wide net: a query for `shopware` returns themes, SDKs,
 * Docker setups, Magento bridges, agency boilerplate and abandoned experiments. Around
 * nine in ten candidates are not extensions, and finding that out through the full
 * assembler would cost two GraphQL round trips each — roughly two thousand requests to
 * discard eighteen hundred repositories.
 *
 * So this reads one blob per repository, twenty-five repositories per query, using the
 * aliased-batch shape RepositoryEnricher already uses for signals. A thousand
 * candidates cost forty requests instead of two thousand, and only the survivors reach
 * the assembler.
 *
 * It answers one question and returns no data. Everything about the extension — the
 * per-tag constraints that make up the compatibility matrix — is still read by the
 * assembler afterwards, because HEAD's composer.json describes HEAD and nothing else.
 */
final class GitHubComposerProbe
{
    /**
     * Repositories per query.
     *
     * The same 25 the signals enricher uses. Each alias pulls a whole composer.json,
     * and a batch of 25 stays well inside GraphQL's node budget while keeping the
     * response small enough not to time out on a shared host.
     */
    private const int BATCH = 25;

    public function __construct(
        private readonly GitHubClient $github,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $fullNames repository full names, "owner/repo"
     *
     * @return list<string> the subset declaring type shopware-platform-plugin, in input order
     */
    public function filterToExtensions(array $fullNames): array
    {
        $keep = [];

        foreach (array_chunk($fullNames, self::BATCH) as $batch) {
            foreach ($this->probeBatch($batch) as $fullName) {
                $keep[] = $fullName;
            }
        }

        return $keep;
    }

    /**
     * @param list<string> $batch
     *
     * @return list<string>
     */
    private function probeBatch(array $batch): array
    {
        $declarations = [];
        $fields = [];
        $variables = [];
        $byAlias = [];

        foreach ($batch as $index => $fullName) {
            $parts = explode('/', $fullName);

            if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1]) {
                continue;
            }

            // Owner and name travel as variables, never interpolated: they come from a
            // search response, which is third-party input.
            $declarations[] = \sprintf('$o%d: String!, $n%d: String!', $index, $index);
            $variables['o'.$index] = $parts[0];
            $variables['n'.$index] = $parts[1];
            $fields[] = \sprintf(
                'r%1$d: repository(owner: $o%1$d, name: $n%1$d) { composer: object(expression: "HEAD:composer.json") { ... on Blob { text } } }',
                $index,
            );
            $byAlias['r'.$index] = $fullName;
        }

        if ([] === $fields) {
            return [];
        }

        $result = $this->github->graphql(
            \sprintf('query(%s) { %s }', implode(', ', $declarations), implode(' ', $fields)),
            $variables,
        );

        if (null === $result) {
            // A failed batch is 25 repositories not considered this week, not a reason
            // to abandon the sweep. They are still there next time.
            $this->logger->warning('Composer probe batch failed', ['repositories' => \count($byAlias)]);

            return [];
        }

        $keep = [];

        foreach ($byAlias as $alias => $fullName) {
            $text = $result[$alias]['composer']['text'] ?? null;

            if (!\is_string($text)) {
                // No composer.json at all, or a repository that vanished between the
                // search and this query. Ordinary, and not worth a log line each.
                continue;
            }

            $decoded = json_decode($text, true);

            if (\is_array($decoded) && ShopwarePackageType::matches($decoded)) {
                $keep[] = $fullName;
            }
        }

        return $keep;
    }
}
