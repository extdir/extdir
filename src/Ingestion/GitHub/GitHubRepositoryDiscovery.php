<?php

declare(strict_types=1);

namespace App\Ingestion\GitHub;

use Psr\Log\LoggerInterface;

/**
 * Finds candidate Shopware extensions by searching repositories, not topics.
 *
 * The third discovery source, and the one that exists because of a specific gap. A
 * maintainer asked why their plugin was missing: it is a valid MIT extension declaring
 * `shopware-platform-plugin`, with six tags and recent commits, and it was invisible
 * because it is not on Packagist and carries no GitHub topics at all. Packagist covers
 * the packages that are already installable; topics cover the maintainers who tagged
 * their work. Nothing covered somebody who simply published a repository.
 *
 * §7 says Packagist is the seed and not GitHub search, and that still holds. This is a
 * third tier under it, and it does not relax what gets indexed: every candidate passes
 * the same composer.json type gate as everything else, applied by GitHubComposerProbe
 * before the assembler is called at all. The net is wider; the filter is identical.
 *
 * Measured before building: `shopware language:php fork:false pushed:>2023-01-01`
 * returns 978 repositories, comfortably inside the API's 1,000-result ceiling, and it
 * does match the plugin that prompted this.
 */
final class GitHubRepositoryDiscovery
{
    /**
     * Search queries, unioned.
     *
     * Each is kept under the 1,000-result ceiling on purpose: the API silently stops
     * paginating there, so a broader query does not find more, it just hides the tail.
     * Narrow queries that each fit are how the ceiling is worked around.
     *
     * `fork:false` is load-bearing rather than tidiness. A fork of SwagPayPal carries
     * an identical composer.json and an identical package name, so it passes the type
     * gate and is caught only by the package-name dedupe, every sweep, forever.
     * Excluding forks at the source is cheaper than rejecting them repeatedly.
     *
     * `pushed:` excludes repositories untouched for years. An extension abandoned
     * before Shopware 6.4 is not a useful directory entry, and the maintenance signals
     * would only mark it abandoned after we spent requests fetching it.
     *
     * @var list<string>
     */
    private const array QUERIES = [
        'shopware language:php fork:false archived:false pushed:>2023-01-01',
        'shopware6 fork:false archived:false pushed:>2023-01-01',
        'shopware-6 plugin fork:false archived:false pushed:>2023-01-01',
        'sw6 plugin fork:false archived:false pushed:>2023-01-01',
    ];

    /** GitHub's search API caps out at 1,000 results per query regardless of paging. */
    private const int MAX_PAGES = 10;
    private const int PER_PAGE = 100;

    public function __construct(
        private readonly GitHubClient $github,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Repository full names ("owner/repo"), deduplicated across queries.
     *
     * @param list<string>|null $queries
     *
     * @return list<string>
     */
    public function discover(?array $queries = null): array
    {
        $found = [];

        foreach ($queries ?? self::QUERIES as $query) {
            foreach ($this->search($query) as $fullName) {
                // Case-insensitive, like the topic discovery: GitHub preserves the
                // owner's casing but compares names case-insensitively, and the same
                // repository arrives from several of these queries.
                $found[strtolower($fullName)] = $fullName;
            }
        }

        return array_values($found);
    }

    /**
     * @return list<string>
     */
    private function search(string $query): array
    {
        $names = [];

        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $result = $this->github->get(\sprintf(
                'search/repositories?q=%s&per_page=%d&page=%d',
                rawurlencode($query),
                self::PER_PAGE,
                $page,
            ));

            if (null === $result) {
                // Search has its own, much smaller budget than the REST core, so a
                // failure here is usually the rate limit rather than a broken query.
                $this->logger->warning('GitHub repository search failed', ['query' => $query, 'page' => $page]);
                break;
            }

            $items = $result['items'] ?? null;

            if (!\is_array($items) || [] === $items) {
                break;
            }

            foreach ($items as $item) {
                if (!\is_array($item)) {
                    continue;
                }

                // Belt and braces: the qualifiers above already exclude these, but a
                // fork slipping through costs a probe on every future sweep.
                if (true === ($item['fork'] ?? false) || true === ($item['archived'] ?? false)) {
                    continue;
                }

                $fullName = $item['full_name'] ?? null;

                if (\is_string($fullName) && str_contains($fullName, '/')) {
                    $names[] = $fullName;
                }
            }

            if (\count($items) < self::PER_PAGE) {
                break;
            }
        }

        return $names;
    }
}
