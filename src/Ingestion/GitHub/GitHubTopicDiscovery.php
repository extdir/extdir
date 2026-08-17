<?php

declare(strict_types=1);

namespace App\Ingestion\GitHub;

use Psr\Log\LoggerInterface;

/**
 * Finds candidate Shopware extensions by GitHub topic.
 *
 * This is the second discovery source in docs/brief.md §7, and it is what makes the
 * directory more than a re-skin of Packagist. Every package indexed so far came
 * from Packagist by construction, so `composer require` already worked for all of
 * them. The extensions that are genuinely hard to find — published to GitHub with
 * a topic and never submitted to Packagist — only appear through this path.
 *
 * Measured before building: the three topics below return 461 distinct
 * repositories, 338 of which are not in the index. Not all are Shopware 6
 * extensions; the composer.json type filter downstream decides that.
 *
 * Topic search is used rather than code search deliberately. GitHub's code search
 * requires the repository to be indexed for it and returns nothing useful for
 * `shopware-platform-plugin in:file` — it answered zero when tried.
 */
final class GitHubTopicDiscovery
{
    /**
     * Topics in use across the ecosystem. There is no single canonical one, which
     * is why all three are searched and the results unioned.
     *
     * @var list<string>
     */
    private const TOPICS = ['shopware6', 'shopware-plugin', 'shopware6-plugin'];

    /** GitHub's search API caps out at 1,000 results per query regardless of paging. */
    private const MAX_PAGES = 10;
    private const PER_PAGE = 100;

    public function __construct(
        private readonly GitHubClient $github,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Repository full names ("owner/repo"), deduplicated across topics.
     *
     * @param list<string>|null $topics
     *
     * @return list<string>
     */
    public function discover(?array $topics = null): array
    {
        $found = [];

        foreach ($topics ?? self::TOPICS as $topic) {
            foreach ($this->searchTopic($topic) as $fullName) {
                // Case-insensitive dedupe: GitHub preserves the owner's casing but
                // treats names case-insensitively, so the same repository can arrive
                // as "FriendsOfShopware/..." from one topic and a different casing
                // from another.
                $found[strtolower($fullName)] = $fullName;
            }
        }

        return array_values($found);
    }

    /**
     * @return list<string>
     */
    private function searchTopic(string $topic): array
    {
        $names = [];

        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $result = $this->github->get(\sprintf(
                'search/repositories?q=%s&per_page=%d&page=%d',
                rawurlencode('topic:'.$topic),
                self::PER_PAGE,
                $page,
            ));

            if (null === $result) {
                $this->logger->warning('GitHub topic search failed', ['topic' => $topic, 'page' => $page]);
                break;
            }

            $items = $result['items'] ?? null;
            if (!\is_array($items) || [] === $items) {
                break;
            }

            foreach ($items as $item) {
                $fullName = \is_array($item) ? ($item['full_name'] ?? null) : null;

                if (\is_string($fullName)) {
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
