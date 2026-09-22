<?php

declare(strict_types=1);

namespace App\Tests\Ui\Chart;

use App\Ui\Chart\Plot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic behind the charts.
 *
 * Worth testing precisely because none of it fails loudly. A scale that is off by
 * one renders a chart that looks entirely reasonable and says something untrue, and
 * no smoke test, no linter and no amount of looking at the page catches that.
 */
final class PlotTest extends TestCase
{
    /**
     * An axis ceiling has to be a number a person reads without doing arithmetic.
     */
    #[DataProvider('ceilings')]
    public function testTheAxisCeilingRoundsUpToSomethingReadable(int $max, int $expected): void
    {
        self::assertSame($expected, Plot::niceMax($max));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function ceilings(): iterable
    {
        yield 'nothing yet' => [0, 1];
        yield 'a single item' => [1, 1];
        yield 'single digits' => [7, 7];
        yield 'exactly a round number stays put' => [10, 10];
        yield 'a hundred and five' => [105, 120];
        yield 'a real release count' => [1440, 1500];
        // The case the fine step list exists for. A coarse 1, 2, 5, 10 ladder sends
        // this to 500 and leaves a third of the plot empty, flattening every bar in
        // the chart to buy nothing.
        yield 'the adoption peak' => [345, 400];
    }

    /**
     * A bar that exists must be visible.
     *
     * One release against a ceiling of two thousand is 0.05 percent, which rounds to
     * a bar of no width at all. A row that is present in the table and absent from
     * the chart reads as missing data rather than as a small number, so the floor is
     * deliberate.
     */
    public function testASmallValueStillDrawsSomething(): void
    {
        self::assertGreaterThan(0.0, Plot::share(1, 2000));
    }

    public function testAZeroValueDrawsNothing(): void
    {
        self::assertSame(0.0, Plot::share(0, 2000));
    }

    /**
     * A ceiling of zero would be a division by zero, and happens on an empty catalogue.
     */
    public function testAnEmptyChartDoesNotDivideByZero(): void
    {
        self::assertSame(0.0, Plot::share(5, 0));
        self::assertSame(1, Plot::niceMax(0));
    }

    /**
     * Zero gets no shade, because none of a category and the least of a category are
     * different facts and must not share ink.
     */
    public function testNoneOfACategoryIsNotTheLightestShade(): void
    {
        self::assertSame(0, Plot::rampStep(0.0));
        self::assertSame(1, Plot::rampStep(0.01));
    }

    #[DataProvider('shares')]
    public function testTheRampStepsWhereTheProportionSays(float $share, int $expected): void
    {
        self::assertSame($expected, Plot::rampStep($share));
    }

    /**
     * @return iterable<string, array{float, int}>
     */
    public static function shares(): iterable
    {
        yield 'a fifth' => [0.2, 2];
        yield 'under half' => [0.39, 2];
        yield 'half' => [0.5, 3];
        yield 'most' => [0.75, 4];
        yield 'nearly all' => [0.95, 5];
        yield 'all of it' => [1.0, 5];
    }

    /**
     * Zero is the bottom of the plot and the ceiling is the top, not the other way up.
     *
     * SVG y grows downward, which is the single easiest thing to get backwards here,
     * and a chart drawn upside down still looks like a chart.
     */
    public function testTheYAxisPointsTheRightWay(): void
    {
        $points = Plot::project([['month' => 0, 'value' => 0], ['month' => 12, 'value' => 100]], 12, 100);

        self::assertSame((float) Plot::HEIGHT, $points[0]['y'], 'zero belongs at the bottom');
        self::assertSame(0.0, $points[1]['y'], 'the ceiling belongs at the top');
        self::assertSame(0.0, $points[0]['x'], 'month zero belongs at the left');
        self::assertSame((float) Plot::WIDTH, $points[1]['x'], 'the last month belongs at the right');
    }

    public function testThePathStartsWithAMoveAndThenDraws(): void
    {
        $path = Plot::linePath([['month' => 0, 'value' => 0], ['month' => 1, 'value' => 5]], 1, 10);

        self::assertStringStartsWith('M ', $path);
        self::assertStringContainsString(' L ', $path);
    }

    public function testAnEmptySeriesProducesNoPath(): void
    {
        self::assertSame('', Plot::linePath([], 12, 100));
    }

    /**
     * Every line shares one scale.
     *
     * Scaling each series to its own maximum would draw the shortest line at the same
     * height as the tallest, which on a chart whose entire subject is comparing them
     * would be a lie told by the axis rather than by the data.
     */
    public function testThePeakSpansEverySeries(): void
    {
        $series = [
            ['version' => '6.6', 'releasedAt' => '2024-03-18', 'points' => [['month' => 0, 'value' => 40]]],
            ['version' => '6.7', 'releasedAt' => '2025-06-10', 'points' => [['month' => 0, 'value' => 310]]],
        ];

        self::assertSame(310, Plot::peak($series));
    }

    /**
     * A version that has existed for three months must not stretch the axis to
     * twenty-four, and must not compress every other line into the left quarter.
     */
    public function testTheSpanIsTheLongestSeries(): void
    {
        $series = [
            ['version' => '6.6', 'releasedAt' => '2024-03-18', 'points' => [['month' => 0, 'value' => 1], ['month' => 20, 'value' => 9]]],
            ['version' => '6.7', 'releasedAt' => '2025-06-10', 'points' => [['month' => 0, 'value' => 5]]],
        ];

        self::assertSame(20, Plot::span($series));
    }

    public function testASpanIsNeverZero(): void
    {
        self::assertSame(1, Plot::span([]));
    }

    public function testTicksRunFromZeroToTheCeiling(): void
    {
        self::assertSame([0, 100, 200, 300, 400], Plot::ticks(400));
    }
}
