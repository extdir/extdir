<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Search\SearchCriteria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(SearchCriteria::class)]
final class SearchCriteriaTest extends TestCase
{
    public function testFiltersAreReadFromTheQueryString(): void
    {
        $criteria = SearchCriteria::fromRequest(Request::create('/', 'GET', [
            'q' => 'payment',
            'shopware' => '6.7',
            'category' => 'payment',
            'licence' => 'permissive',
            'maintenance' => 'current',
            'page' => '3',
        ]));

        self::assertSame('payment', $criteria->query);
        self::assertSame('6.7', $criteria->shopwareVersion);
        self::assertSame('current', $criteria->maintenance);
        self::assertSame(3, $criteria->page);
        self::assertTrue($criteria->hasFilters());
    }

    /**
     * Sorting by relevance is meaningless without a search term, so the default
     * depends on whether one was supplied.
     */
    public function testDefaultSortDependsOnWhetherThereIsAQuery(): void
    {
        self::assertSame(
            SearchCriteria::SORT_RELEVANCE,
            SearchCriteria::fromRequest(Request::create('/', 'GET', ['q' => 'payment']))->sort,
        );

        self::assertSame(
            SearchCriteria::SORT_RANK,
            SearchCriteria::fromRequest(Request::create('/'))->sort,
        );
    }

    /**
     * Sort comes straight from the URL and is interpolated into an ORDER BY, so
     * anything unrecognised must fall back rather than reach the query builder.
     */
    public function testUnknownSortValuesAreRejected(): void
    {
        $criteria = SearchCriteria::fromRequest(
            Request::create('/', 'GET', ['sort' => 'id; DROP TABLE extension']),
        );

        self::assertContains($criteria->sort, SearchCriteria::sortOptions());
    }

    public function testPageIsClampedToAtLeastOne(): void
    {
        self::assertSame(1, SearchCriteria::fromRequest(Request::create('/', 'GET', ['page' => '0']))->page);
        self::assertSame(1, SearchCriteria::fromRequest(Request::create('/', 'GET', ['page' => '-5']))->page);
        self::assertSame(1, SearchCriteria::fromRequest(Request::create('/', 'GET', ['page' => 'abc']))->page);
    }

    public function testBlankFiltersAreTreatedAsAbsent(): void
    {
        $criteria = SearchCriteria::fromRequest(Request::create('/', 'GET', [
            'q' => '   ',
            'category' => '',
        ]));

        self::assertNull($criteria->query);
        self::assertNull($criteria->category);
        self::assertFalse($criteria->hasFilters());
    }

    /**
     * Changing any facet must reset paging, or filtering from page 5 lands the
     * visitor on an empty page 5 of a much smaller result set.
     */
    public function testChangingAFacetResetsThePage(): void
    {
        $criteria = new SearchCriteria(query: 'payment', page: 5);

        self::assertSame(1, $criteria->with('category', 'shipping')->page);
        self::assertSame(2, $criteria->with('page', '2')->page);
    }

    public function testAFacetCanBeCleared(): void
    {
        $criteria = new SearchCriteria(category: 'payment', shopwareVersion: '6.7');
        $cleared = $criteria->with('category', null);

        self::assertNull($cleared->category);
        self::assertSame('6.7', $cleared->shopwareVersion, 'other facets survive');
    }

    /**
     * Default values are omitted from the URL so one result set has one canonical
     * address — which matters for both sharing and indexing.
     */
    public function testDefaultsAreOmittedFromTheUrl(): void
    {
        self::assertSame([], (new SearchCriteria())->toQueryParameters());

        self::assertSame(
            ['q' => 'payment'],
            (new SearchCriteria(query: 'payment', sort: SearchCriteria::SORT_RELEVANCE))->toQueryParameters(),
        );

        self::assertSame(
            ['q' => 'payment', 'sort' => SearchCriteria::SORT_STARS],
            (new SearchCriteria(query: 'payment', sort: SearchCriteria::SORT_STARS))->toQueryParameters(),
        );
    }

    public function testCriteriaSurviveAUrlRoundTrip(): void
    {
        $original = new SearchCriteria(
            query: 'payment',
            shopwareVersion: '6.7',
            category: 'payment',
            licence: 'permissive',
            maintenance: 'current',
            sort: SearchCriteria::SORT_UPDATED,
            page: 3,
        );

        $restored = SearchCriteria::fromRequest(
            Request::create('/', 'GET', $original->toQueryParameters()),
        );

        self::assertEquals($original, $restored);
    }

    public function testOffsetFollowsFromThePage(): void
    {
        self::assertSame(0, (new SearchCriteria(page: 1))->offset());
        self::assertSame(SearchCriteria::PER_PAGE, (new SearchCriteria(page: 2))->offset());
    }
}
