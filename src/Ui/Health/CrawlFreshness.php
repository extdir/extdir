<?php

declare(strict_types=1);

namespace App\Ui\Health;

/**
 * Whether the catalogue has been crawled recently enough, at a given tolerance.
 *
 * Two questions are asked of the same measurement and they are not the same question:
 *
 * - **Is the data still trustworthy?** Answered on `/health` at 48 hours. Two missed
 *   nights means the compatibility matrix may be describing a world that has moved on.
 * - **Did last night's crawl run?** Answered on `/health/crawl` at 26 hours. The
 *   nightly ingest starts at 03:23, so the gap peaks near 24 hours in normal operation
 *   and anything beyond 26 means a run was missed.
 *
 * Separate thresholds, one implementation. Two copies of "how old is too old" is the
 * kind of duplication that ends with the endpoints disagreeing about whether the site
 * is healthy.
 */
final class CrawlFreshness
{
    /**
     * @param float|null $ageHours hours since the last completed crawl, null if never
     *
     * @return array{ok: bool, detail: string}
     */
    public static function check(?float $ageHours, float $toleranceHours): array
    {
        if (null === $ageHours) {
            return ['ok' => false, 'detail' => 'no crawl has ever completed'];
        }

        return [
            'ok' => $ageHours < $toleranceHours,
            'detail' => \sprintf('last crawl %.1f hours ago', $ageHours),
        ];
    }
}
