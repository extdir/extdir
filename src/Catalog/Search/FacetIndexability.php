<?php

declare(strict_types=1);

namespace App\Catalog\Search;

/**
 * Which filtered listings are worth putting in a search index.
 *
 * The filter UI can produce roughly three thousand URL combinations from six
 * Shopware versions, twenty-eight categories, four licence states and four
 * maintenance states. Almost all of them return nothing or nearly nothing, and a
 * crawler that follows the filters finds them all.
 *
 * Search Console reported the first symptom: ?shopware=6.3&category=admin&licence=
 * copyleft&maintenance=lagging as "duplicate, Google chose a different canonical".
 * The page said it was canonical and Google disagreed, which is what happens when a
 * page carrying one result looks like every other page carrying that result. Nothing
 * was misconfigured; there was simply nothing there worth keeping.
 *
 * One rule, used in two places. The listing template reads it to decide whether to
 * emit noindex, and the sitemap reads it to decide whether to submit the URL at all.
 * Those have to agree: submitting a page that tells the crawler not to index it is a
 * reported error, and a page worth submitting is by definition one worth indexing.
 */
final class FacetIndexability
{
    /**
     * Filters that still describe a page somebody would search for.
     *
     * "Shopware 6.7 payment extensions" is a real query and a useful landing page.
     * "Shopware 6.3 admin copyleft lagging extensions" is not a query anybody types;
     * it is a path through a filter UI, and the page at the end of it exists to be
     * used, not to be found.
     */
    public const int MAX_FILTERS = 2;

    /**
     * Results below which a listing is too thin to rank for anything.
     *
     * Set at three because the alternative is to argue about it. What matters is that
     * one row is not a page: whatever it ranks for, the extension's own page answers
     * better, and Google has already said so by choosing that canonical instead.
     */
    public const int MIN_RESULTS = 3;

    /**
     * @param int $activeFilters how many facets are set, ignoring page, sort and view
     * @param int $totalResults  matches across all pages, not just this one
     */
    public static function isIndexable(int $activeFilters, int $totalResults): bool
    {
        // The unfiltered listing is the catalogue itself and is always indexable,
        // even on the day it holds two extensions.
        if (0 === $activeFilters) {
            return true;
        }

        return $activeFilters <= self::MAX_FILTERS && $totalResults >= self::MIN_RESULTS;
    }
}
