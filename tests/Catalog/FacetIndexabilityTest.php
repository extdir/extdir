<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Search\FacetIndexability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which filtered listings are worth indexing.
 *
 * The case that prompted this is the last one below: four facets, one result, and
 * Search Console reporting "duplicate, Google chose a different canonical". The page
 * declared itself canonical and Google disagreed, correctly.
 */
final class FacetIndexabilityTest extends TestCase
{
    /**
     * The catalogue itself is always worth indexing, however small it is.
     */
    public function testTheUnfilteredListingIsAlwaysIndexable(): void
    {
        self::assertTrue(FacetIndexability::isIndexable(0, 595));
        self::assertTrue(FacetIndexability::isIndexable(0, 0));
    }

    #[DataProvider('cases')]
    public function testTheRule(int $filters, int $results, bool $expected, string $because): void
    {
        self::assertSame($expected, FacetIndexability::isIndexable($filters, $results), $because);
    }

    /**
     * @return iterable<string, array{int, int, bool, string}>
     */
    public static function cases(): iterable
    {
        yield 'one facet, plenty' => [1, 60, true, '"shopware 6.7 extensions" is a real query'];
        yield 'two facets, plenty' => [2, 24, true, '"shopware 6.7 payment extensions" is also a real query'];

        // Nobody searches for four stacked filters; that URL is a path through the
        // filter UI, and the page at the end of it exists to be used, not found.
        yield 'three facets, plenty' => [3, 40, false, 'three facets is a filter path, not a page'];
        yield 'four facets, plenty' => [4, 40, false, 'four even more so'];

        yield 'one facet, thin' => [1, 2, false, 'two results cannot rank for anything'];
        yield 'one facet, empty' => [1, 0, false, 'an empty listing is not a page'];
        yield 'one facet, exactly at the floor' => [1, 3, true, 'three is the agreed floor'];

        // The URL Search Console flagged.
        yield 'the reported URL' => [4, 1, false, 'four facets and one result, which Google already folded'];
    }
}
