<?php

declare(strict_types=1);

namespace App\Tests\Signals;

use App\Signals\PackagistPopularity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading install counts out of whatever Packagist actually sends.
 *
 * The shapes below are not hypothetical. Packagist has served this endpoint for over a
 * decade to a corpus registered across all of it, and the parser meets every one of
 * them during a single sweep of a few hundred packages.
 */
final class PackagistPopularityTest extends TestCase
{
    public function testReadsTheCurrentShape(): void
    {
        $stats = PackagistPopularity::fromPayload([
            'package' => ['downloads' => ['total' => 886084, 'monthly' => 36201, 'daily' => 481]],
        ]);

        self::assertSame(['total' => 886084, 'monthly' => 36201], $stats);
    }

    /**
     * An older revision of the format reports `downloads` as a bare integer.
     *
     * The lifetime total is recoverable; the monthly figure simply does not exist. It
     * has to come back as zero rather than as a copy of the total, because reporting a
     * lifetime count as a thirty-day one would put the package straight to the top of
     * the board that exists to correct for exactly that.
     */
    public function testReadsTheLegacyIntegerShape(): void
    {
        $stats = PackagistPopularity::fromPayload(['package' => ['downloads' => 4210]]);

        self::assertSame(['total' => 4210, 'monthly' => 0], $stats);
    }

    /**
     * A total with no monthly figure is still worth keeping: a package registered days
     * ago legitimately has one.
     */
    public function testAMissingMonthlyFigureDoesNotDiscardTheTotal(): void
    {
        $stats = PackagistPopularity::fromPayload(['package' => ['downloads' => ['total' => 12]]]);

        self::assertSame(['total' => 12, 'monthly' => 0], $stats);
    }

    public function testAcceptsCountsQuotedAsStrings(): void
    {
        $stats = PackagistPopularity::fromPayload([
            'package' => ['downloads' => ['total' => '886084', 'monthly' => '36201']],
        ]);

        self::assertSame(['total' => 886084, 'monthly' => 36201], $stats);
    }

    /**
     * Zero installs is a real answer and must survive.
     *
     * The distinction the boards depend on is between "measured at zero" and "never
     * measured", and it is carried by packagistCheckedAt, not by the count. If this
     * returned null the two would collapse into one and a genuinely unused package
     * would be indistinguishable from one that is not on Packagist at all.
     */
    public function testZeroIsAnAnswerRatherThanAnAbsence(): void
    {
        self::assertSame(
            ['total' => 0, 'monthly' => 0],
            PackagistPopularity::fromPayload(['package' => ['downloads' => ['total' => 0, 'monthly' => 0]]]),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('unusablePayloads')]
    public function testUnusableDocumentsYieldNothing(array $payload): void
    {
        self::assertNull(PackagistPopularity::fromPayload($payload));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusablePayloads(): iterable
    {
        // What a package deleted between the listing call and the fetch answers with.
        yield 'no package key' => [['status' => 'error', 'message' => 'Package not found']];
        yield 'package is not an object' => [['package' => 'gone']];
        yield 'no downloads at all' => [['package' => ['name' => 'vendor/plugin']]];
        yield 'downloads is a string' => [['package' => ['downloads' => 'many']]];
        yield 'total is not numeric' => [['package' => ['downloads' => ['total' => 'lots']]]];

        // Only a bug at one end or the other, and a negative would sort silently to the
        // bottom of a board rather than being noticed.
        yield 'negative total' => [['package' => ['downloads' => ['total' => -5]]]];
    }
}
