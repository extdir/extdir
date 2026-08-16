<?php

declare(strict_types=1);

namespace App\Catalog\Search;

use App\Catalog\Entity\Extension;

/**
 * One page of search results plus the facet counts needed to render the sidebar.
 */
final readonly class SearchResult
{
    /**
     * @param list<Extension>                   $extensions
     * @param array<string, array<string, int>> $facets
     */
    public function __construct(
        public array $extensions,
        public int $total,
        public SearchCriteria $criteria,
        public array $facets,
    ) {
    }

    public function pageCount(): int
    {
        return (int) max(1, ceil($this->total / SearchCriteria::PER_PAGE));
    }

    public function hasNextPage(): bool
    {
        return $this->criteria->page < $this->pageCount();
    }

    public function hasPreviousPage(): bool
    {
        return $this->criteria->page > 1;
    }

    public function isEmpty(): bool
    {
        return [] === $this->extensions;
    }

    /**
     * @return array<string, int>
     */
    public function facet(string $name): array
    {
        return $this->facets[$name] ?? [];
    }
}
