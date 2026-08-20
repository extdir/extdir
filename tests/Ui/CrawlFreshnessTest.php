<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Ui\Health\CrawlFreshness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rule behind both health checks.
 *
 * Same measurement, two tolerances: 48 hours answers "is this data still
 * trustworthy", 26 answers "did last night's crawl run". They are asked on different
 * URLs so a monitor can watch the second one without the first crying wolf.
 */
final class CrawlFreshnessTest extends TestCase
{
    #[DataProvider('ages')]
    public function testTheToleranceDecidesIt(?float $ageHours, float $tolerance, bool $expected): void
    {
        self::assertSame($expected, CrawlFreshness::check($ageHours, $tolerance)['ok']);
    }

    /**
     * @return iterable<string, array{float|null, float, bool}>
     */
    public static function ages(): iterable
    {
        // The nightly ingest starts at 03:23, so a healthy gap peaks just under 24
        // hours immediately before the next run.
        yield 'just crawled' => [0.2, 26.0, true];
        yield 'normal peak, the night before' => [23.9, 26.0, true];
        yield 'inside the two hours of grace' => [25.5, 26.0, true];
        yield 'a night was missed' => [26.5, 26.0, false];
        yield 'two nights missed' => [50.0, 26.0, false];

        // The same measurement against the slower question.
        yield 'a missed night is not yet untrustworthy' => [26.5, 48.0, true];
        yield 'two missed nights are' => [50.0, 48.0, false];

        yield 'never crawled' => [null, 26.0, false];
        yield 'never crawled, slow question' => [null, 48.0, false];
    }

    /**
     * The detail is what a person reads at 3am when the alert fires, so it has to say
     * how late rather than merely that something is wrong.
     */
    public function testTheDetailNamesTheAge(): void
    {
        self::assertSame('last crawl 30.2 hours ago', CrawlFreshness::check(30.24, 26.0)['detail']);
        self::assertSame('no crawl has ever completed', CrawlFreshness::check(null, 26.0)['detail']);
    }

    /**
     * Exactly at the tolerance counts as late. An off-by-one here would report healthy
     * at the precise moment the answer changes.
     */
    public function testTheBoundaryIsExclusive(): void
    {
        self::assertFalse(CrawlFreshness::check(26.0, 26.0)['ok']);
        self::assertTrue(CrawlFreshness::check(25.999, 26.0)['ok']);
    }
}
