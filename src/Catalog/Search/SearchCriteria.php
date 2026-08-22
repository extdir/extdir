<?php

declare(strict_types=1);

namespace App\Catalog\Search;

use Symfony\Component\HttpFoundation\Request;

/**
 * A single search request, parsed from query parameters.
 *
 * Everything lives in the URL rather than in session or component state. For a
 * directory that is not a stylistic preference: a merchant who finds "extensions
 * that support 6.7, are actively maintained and handle shipping" needs to be able
 * to paste that link into a team chat, and search engines need to be able to index
 * it. Both are lost the moment filters become client-side state.
 */
final readonly class SearchCriteria
{
    public const PER_PAGE = 24;

    public const SORT_RELEVANCE = 'relevance';
    public const SORT_RANK = 'rank';
    public const SORT_UPDATED = 'updated';
    public const SORT_NAME = 'name';
    public const SORT_STARS = 'stars';

    /**
     * Row density. Comfortable carries the description; compact is one line per
     * extension for someone comparing forty of them rather than reading three.
     *
     * In the URL rather than a cookie, for the same reason every filter is: the view
     * survives filtering, it can be pasted to a colleague, and the site continues to
     * set no cookies at all, which the privacy policy states and a test enforces.
     */
    public const VIEW_COMFORTABLE = 'comfortable';
    public const VIEW_COMPACT = 'compact';

    public function __construct(
        public ?string $query = null,
        public ?string $shopwareVersion = null,
        public ?string $category = null,
        public ?string $licence = null,
        public ?string $maintenance = null,
        public string $sort = self::SORT_RANK,
        public int $page = 1,
        public string $view = self::VIEW_COMFORTABLE,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $query = trim((string) $request->query->get('q', ''));
        $sort = (string) $request->query->get('sort', '');

        if (!\in_array($sort, self::sortOptions(), true)) {
            // Relevance is meaningless without a search term, so the default
            // depends on whether one was given.
            $sort = '' !== $query ? self::SORT_RELEVANCE : self::SORT_RANK;
        }

        return new self(
            query: '' !== $query ? $query : null,
            shopwareVersion: self::cleanFilter($request->query->get('shopware')),
            category: self::cleanFilter($request->query->get('category')),
            licence: self::cleanFilter($request->query->get('licence')),
            maintenance: self::cleanFilter($request->query->get('maintenance')),
            sort: $sort,
            page: max(1, (int) $request->query->get('page', 1)),
            view: self::VIEW_COMPACT === $request->query->get('view')
                ? self::VIEW_COMPACT
                : self::VIEW_COMFORTABLE,
        );
    }

    public function isCompact(): bool
    {
        return self::VIEW_COMPACT === $this->view;
    }

    /**
     * @return list<string>
     */
    public static function sortOptions(): array
    {
        return [self::SORT_RELEVANCE, self::SORT_RANK, self::SORT_UPDATED, self::SORT_NAME, self::SORT_STARS];
    }

    /**
     * @return array<string, string>
     */
    public static function sortLabels(): array
    {
        return [
            self::SORT_RELEVANCE => 'Best match',
            self::SORT_RANK => 'Recommended',
            self::SORT_UPDATED => 'Recently updated',
            self::SORT_NAME => 'Name',
            self::SORT_STARS => 'Stars',
        ];
    }

    public function offset(): int
    {
        return ($this->page - 1) * self::PER_PAGE;
    }

    public function hasFilters(): bool
    {
        return null !== $this->query
            || null !== $this->shopwareVersion
            || null !== $this->category
            || null !== $this->licence
            || null !== $this->maintenance;
    }

    /**
     * The same criteria with one facet changed, for building filter links.
     * A null value clears that facet.
     */
    public function with(string $facet, ?string $value): self
    {
        return new self(
            query: 'query' === $facet ? $value : $this->query,
            shopwareVersion: 'shopware' === $facet ? $value : $this->shopwareVersion,
            category: 'category' === $facet ? $value : $this->category,
            licence: 'licence' === $facet ? $value : $this->licence,
            maintenance: 'maintenance' === $facet ? $value : $this->maintenance,
            sort: 'sort' === $facet ? (string) $value : $this->sort,
            // Any facet change invalidates the current page number.
            page: 'page' === $facet ? max(1, (int) $value) : 1,
            // Density is not a facet. Changing a filter must not silently throw the
            // reader back to the other layout.
            view: 'view' === $facet ? (string) $value : $this->view,
        );
    }

    /**
     * Query parameters, with empty ones dropped so URLs stay readable and the
     * same result set always has one canonical address.
     *
     * @return array<string, string|int>
     */
    public function toQueryParameters(): array
    {
        $params = array_filter([
            'q' => $this->query,
            'shopware' => $this->shopwareVersion,
            'category' => $this->category,
            'licence' => $this->licence,
            'maintenance' => $this->maintenance,
        ], static fn (?string $v): bool => null !== $v && '' !== $v);

        $defaultSort = null !== $this->query ? self::SORT_RELEVANCE : self::SORT_RANK;
        if ($this->sort !== $defaultSort) {
            $params['sort'] = $this->sort;
        }

        if ($this->page > 1) {
            $params['page'] = $this->page;
        }

        if (self::VIEW_COMFORTABLE !== $this->view) {
            $params['view'] = $this->view;
        }

        return $params;
    }

    /**
     * Query parameters for the canonical URL.
     *
     * Sort and view are dropped: reordering or re-spacing the same result set
     * produces a page that is word-for-word identical to a search engine, so every
     * variant would compete with the others for the same query. Facets and page are
     * kept, because those
     * genuinely change what is on the page, a paginated result canonicalised back
     * to page one is a documented way to make everything past the first page
     * invisible.
     *
     * @return array<string, string|int>
     */
    public function toCanonicalParameters(): array
    {
        $params = $this->toQueryParameters();
        unset($params['sort'], $params['view']);

        return $params;
    }

    private static function cleanFilter(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
