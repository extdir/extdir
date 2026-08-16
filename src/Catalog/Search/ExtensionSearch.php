<?php

declare(strict_types=1);

namespace App\Catalog\Search;

use App\Catalog\Entity\Extension;
use App\Catalog\Repository\ExtensionRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Faceted search over the catalog.
 *
 * Built on MariaDB rather than a search service. At ~420 extensions a FULLTEXT
 * index plus a handful of indexed columns answers every query in single-digit
 * milliseconds, and skipping Meilisearch removes a daemon from a shared host that
 * has no process supervision to spare.
 *
 * The SQL is written directly rather than through the ORM because the query needs
 * MATCH ... AGAINST for relevance and one join per active facet. DQL cannot express
 * the first without an extension, and hydrating entities only for the ids on the
 * current page is both simpler and faster.
 */
final class ExtensionSearch
{
    /**
     * Statuses that may appear publicly. Delisted extensions are excluded here, at
     * the single point every listing passes through, rather than being filtered by
     * each caller — a takedown that only half applies is worse than none.
     */
    private const VISIBLE_STATUSES = ['listed', 'index_only'];

    public function __construct(
        private readonly Connection $connection,
        private readonly ExtensionRepository $extensions,
    ) {
    }

    public function search(SearchCriteria $criteria): SearchResult
    {
        [$where, $joins, $params, $types] = $this->buildFilters($criteria);

        $countSql = \sprintf(
            'SELECT COUNT(DISTINCT e.id) FROM extension e %s WHERE %s',
            implode(' ', $joins),
            implode(' AND ', $where),
        );
        $total = (int) $this->connection->fetchOne($countSql, $params, $types);

        $sql = \sprintf(
            'SELECT DISTINCT e.id, %s AS sort_value FROM extension e %s WHERE %s ORDER BY %s LIMIT :limit OFFSET :offset',
            $this->sortExpression($criteria),
            implode(' ', $joins),
            implode(' AND ', $where),
            $this->orderBy($criteria),
        );

        $rows = $this->connection->fetchAllAssociative(
            $sql,
            [...$params, 'limit' => SearchCriteria::PER_PAGE, 'offset' => $criteria->offset()],
            // LIMIT and OFFSET must be bound as integers. Left to infer, PDO quotes
            // them as strings and MariaDB rejects `LIMIT '24' OFFSET '0'`.
            [...$types, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return new SearchResult(
            extensions: $this->hydrateInOrder(array_map(static fn (array $r): int => (int) $r['id'], $rows)),
            total: $total,
            criteria: $criteria,
            facets: $this->facets($criteria),
        );
    }

    /**
     * Counts for every facet value, each computed with the *other* filters applied
     * but not its own.
     *
     * That asymmetry is what makes facets usable: showing "6.6 (0)" while 6.6 is
     * the selected filter would be nonsense, and counting every facet against the
     * fully filtered set would show zeros everywhere and make the filters look
     * broken.
     *
     * @return array<string, array<string, int>>
     */
    public function facets(SearchCriteria $criteria): array
    {
        return [
            'shopware' => $this->countBy($criteria, 'shopware'),
            'category' => $this->countBy($criteria, 'category'),
            'licence' => $this->countBy($criteria, 'licence'),
            'maintenance' => $this->countBy($criteria, 'maintenance'),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function countBy(SearchCriteria $criteria, string $facet): array
    {
        // Drop the facet being counted, so its own selection does not zero out the
        // alternatives the visitor might switch to.
        $relaxed = $criteria->with($facet, null);
        [$where, $joins, $params, $types] = $this->buildFilters($relaxed);

        [$select, $extraJoin, $group] = match ($facet) {
            'shopware' => [
                'sv.major_minor',
                'JOIN compatibility_claim fc ON fc.extension_id = e.id AND fc.satisfied = 1
                 JOIN extension_release fr ON fr.id = fc.release_id AND fr.stable = 1
                 JOIN shopware_version sv ON sv.id = fc.shopware_version_id AND sv.shown_in_matrix = 1',
                'sv.major_minor',
            ],
            'category' => [
                'c.category_key',
                'JOIN extension_category fec ON fec.extension_id = e.id
                 JOIN category c ON c.id = fec.category_id',
                'c.category_key',
            ],
            'licence' => ['e.license_status', '', 'e.license_status'],
            default => ['e.maintenance_status', '', 'e.maintenance_status'],
        };

        $sql = \sprintf(
            'SELECT %s AS facet_value, COUNT(DISTINCT e.id) AS n FROM extension e %s %s WHERE %s GROUP BY %s ORDER BY n DESC',
            $select,
            implode(' ', $joins),
            $extraJoin,
            implode(' AND ', $where),
            $group,
        );

        $counts = [];
        foreach ($this->connection->fetchAllAssociative($sql, $params, $types) as $row) {
            $counts[(string) $row['facet_value']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * @return array{list<string>, list<string>, array<string, mixed>, array<string, mixed>}
     */
    private function buildFilters(SearchCriteria $criteria): array
    {
        $where = ['e.index_status IN (:visible)'];
        $joins = [];
        $params = ['visible' => self::VISIBLE_STATUSES];
        $types = ['visible' => ArrayParameterType::STRING];

        if (null !== $criteria->query) {
            $boolean = $this->toBooleanQuery($criteria->query);

            if (null !== $boolean) {
                $where[] = 'MATCH(e.search_text) AGAINST (:q IN BOOLEAN MODE)';
                $params['q'] = $boolean;
            } else {
                // Every token was shorter than MariaDB's minimum index token size
                // (3 by default, and not changeable on shared hosting). Searching
                // "6.6" or "b2b" would otherwise silently return nothing.
                $where[] = 'e.search_text LIKE :like';
                $params['like'] = '%'.$criteria->query.'%';
            }
        }

        if (null !== $criteria->shopwareVersion) {
            $joins[] = 'JOIN compatibility_claim cc ON cc.extension_id = e.id AND cc.satisfied = 1
                        JOIN extension_release cr ON cr.id = cc.release_id AND cr.stable = 1
                        JOIN shopware_version csv ON csv.id = cc.shopware_version_id';
            $where[] = 'csv.major_minor = :shopware';
            $params['shopware'] = $criteria->shopwareVersion;
        }

        if (null !== $criteria->category) {
            $joins[] = 'JOIN extension_category ec ON ec.extension_id = e.id
                        JOIN category cat ON cat.id = ec.category_id';
            $where[] = 'cat.category_key = :category';
            $params['category'] = $criteria->category;
        }

        if (null !== $criteria->licence) {
            $where[] = 'e.license_status = :licence';
            $params['licence'] = $criteria->licence;
        }

        if (null !== $criteria->maintenance) {
            $where[] = 'e.maintenance_status = :maintenance';
            $params['maintenance'] = $criteria->maintenance;
        }

        return [$where, $joins, $params, $types];
    }

    /**
     * The relevance expression must agree with which branch buildFilters() took.
     * When the query falls back to LIKE there is no `:q` bound, so selecting a
     * MATCH score would reference an unbound parameter.
     */
    private function sortExpression(SearchCriteria $criteria): string
    {
        $usesFulltext = null !== $criteria->query && null !== $this->toBooleanQuery($criteria->query);

        return SearchCriteria::SORT_RELEVANCE === $criteria->sort && $usesFulltext
            ? 'MATCH(e.search_text) AGAINST (:q IN BOOLEAN MODE)'
            : 'e.rank_score';
    }

    private function orderBy(SearchCriteria $criteria): string
    {
        // Every ordering ends with e.id so pagination is stable. Without a total
        // order, MariaDB may return the same row on two pages and omit another.
        return match ($criteria->sort) {
            SearchCriteria::SORT_RELEVANCE => 'sort_value DESC, e.rank_score DESC, e.id ASC',
            SearchCriteria::SORT_UPDATED => 'e.last_commit_at IS NULL, e.last_commit_at DESC, e.id ASC',
            SearchCriteria::SORT_NAME => 'e.label ASC, e.id ASC',
            SearchCriteria::SORT_STARS => 'e.stars DESC, e.id ASC',
            default => 'e.rank_score DESC, e.id ASC',
        };
    }

    /**
     * Turns user input into a safe FULLTEXT boolean expression.
     *
     * The boolean-mode parser treats +, -, *, ", ( and ~ as operators, so raw input
     * either changes the query's meaning or makes it a syntax error. Tokens are
     * stripped to word characters and given a trailing * so "ship" finds "shipping".
     */
    private function toBooleanQuery(string $input): ?string
    {
        $terms = [];

        foreach (preg_split('/\s+/', $input) ?: [] as $token) {
            $clean = preg_replace('/[^\p{L}\p{N}_]/u', '', $token) ?? '';

            // Below MariaDB's innodb_ft_min_token_size the term is not indexed at
            // all, so including it would match nothing rather than narrowing.
            if (mb_strlen($clean) >= 3) {
                $terms[] = '+'.$clean.'*';
            }
        }

        return [] === $terms ? null : implode(' ', $terms);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Extension>
     */
    private function hydrateInOrder(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $byId = [];
        foreach ($this->extensions->findBy(['id' => $ids]) as $extension) {
            $byId[(int) $extension->getId()] = $extension;
        }

        // findBy() does not honour the id order, and the ordering is the result of
        // the search — so it is reapplied here rather than lost.
        return array_values(array_filter(array_map(
            static fn (int $id): ?Extension => $byId[$id] ?? null,
            $ids,
        )));
    }
}
