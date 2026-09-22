<?php

declare(strict_types=1);

namespace App\Ui\Chart;

/**
 * The arithmetic behind the charts, kept out of Twig.
 *
 * Everything here is pure, so it tests without a browser, a database or a kernel.
 * That matters more than it sounds: a scale that is off by one renders as a chart
 * that looks fine and is wrong, which is the failure mode no smoke test catches.
 *
 * Most charts on the statistics page are HTML and CSS rather than SVG. A bar is a
 * box with a width, a column is a box with a height, and a heat cell is a box with
 * a background, so drawing them as elements keeps every label at a real font size,
 * keeps them readable at phone width, and keeps them in the document for a reader
 * who has no JavaScript. Only the adoption curve needs real geometry, because a
 * diagonal line is the one shape CSS cannot make honestly.
 */
final readonly class Plot
{
    /**
     * The line chart's coordinate space.
     *
     * The viewBox is close to the size the chart actually renders at, so stroke
     * widths and dot radii mean roughly what they say. The SVG then scales with its
     * container; only the marks are inside it, every label is HTML beside it.
     */
    public const int WIDTH = 640;
    public const int HEIGHT = 220;

    /**
     * A round number at or above the largest value, for the top of an axis.
     *
     * An axis that stops exactly at the tallest bar gives the reader no reference to
     * measure against, and one that stops at 1,447 gives them arithmetic to do.
     *
     * The step list is deliberately fine. A coarse one of 1, 2, 5 and 10 sends a
     * peak of 345 up to an axis of 500 and leaves a third of the plot empty, which
     * flattens every bar in the chart to buy nothing. These steps put the same peak
     * at 400 and keep the shape of the data.
     */
    public static function niceMax(int $max): int
    {
        if ($max <= 0) {
            return 1;
        }

        $magnitude = 10 ** (int) floor(log10($max));

        foreach ([1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 7, 8, 9, 10] as $step) {
            $candidate = (int) ceil($step * $magnitude);

            if ($candidate >= $max) {
                return $candidate;
            }
        }

        return (int) (10 * $magnitude);
    }

    /**
     * A value as a percentage of the axis ceiling, for a CSS width or height.
     *
     * Floored at a hair above zero for any value that is not itself zero. A count of
     * one against a ceiling of fifteen hundred rounds to 0.07%, which renders as
     * nothing at all, and a bar that is present in the table and invisible in the
     * chart reads as missing data rather than as a small number.
     */
    public static function share(int $value, int $ceiling): float
    {
        if ($ceiling <= 0 || $value <= 0) {
            return 0.0;
        }

        return max(0.8, round($value / $ceiling * 100, 2));
    }

    /**
     * Which step of the sequential ramp a proportion lands on, 1 to 5.
     *
     * Five steps rather than a continuous scale. Past about five the adjacent classes
     * stop being separable, and a heat cell nobody can rank against its neighbour is
     * decoration. Zero is its own case and is drawn as an empty cell, because "none
     * of this category" and "the least of this category" are different facts.
     */
    public static function rampStep(float $share): int
    {
        if ($share <= 0.0) {
            return 0;
        }

        return match (true) {
            $share >= 0.8 => 5,
            $share >= 0.6 => 4,
            $share >= 0.4 => 3,
            $share >= 0.2 => 2,
            default => 1,
        };
    }

    /**
     * An SVG path through a series of points.
     *
     * @param list<array{month: int, value: int}> $points
     */
    public static function linePath(array $points, int $maxMonth, int $ceiling): string
    {
        $commands = [];

        foreach (self::project($points, $maxMonth, $ceiling) as $index => $point) {
            $commands[] = \sprintf('%s %s %s', 0 === $index ? 'M' : 'L', $point['x'], $point['y']);
        }

        return implode(' ', $commands);
    }

    /**
     * The same points as coordinates, for the hover targets and the end label.
     *
     * @param list<array{month: int, value: int}> $points
     *
     * @return list<array{x: float, y: float, month: int, value: int}>
     */
    public static function project(array $points, int $maxMonth, int $ceiling): array
    {
        $projected = [];

        foreach ($points as $point) {
            $projected[] = [
                'x' => round($maxMonth > 0 ? $point['month'] / $maxMonth * self::WIDTH : 0.0, 2),
                'y' => round($ceiling > 0 ? self::HEIGHT - ($point['value'] / $ceiling * self::HEIGHT) : self::HEIGHT, 2),
                'month' => $point['month'],
                'value' => $point['value'],
            ];
        }

        return $projected;
    }

    /**
     * The largest value across every series, so the lines share one axis.
     *
     * Separate scales per line would make the shortest series look like the tallest,
     * which on a chart whose whole subject is comparing them would be a lie told by
     * the axis rather than by the data.
     *
     * @param list<array{version: string, releasedAt: string, points: list<array{month: int, value: int}>}> $series
     */
    public static function peak(array $series): int
    {
        $peak = 0;

        foreach ($series as $line) {
            foreach ($line['points'] as $point) {
                $peak = max($peak, $point['value']);
            }
        }

        return $peak;
    }

    /**
     * The longest run of months any series reaches, so every line shares one x axis.
     *
     * @param list<array{version: string, releasedAt: string, points: list<array{month: int, value: int}>}> $series
     */
    public static function span(array $series): int
    {
        $span = 0;

        foreach ($series as $line) {
            foreach ($line['points'] as $point) {
                $span = max($span, $point['month']);
            }
        }

        return max(1, $span);
    }

    /**
     * Axis tick values from zero to the ceiling.
     *
     * @return list<int>
     */
    public static function ticks(int $ceiling, int $count = 4): array
    {
        $ticks = [];

        foreach (range(0, $count) as $step) {
            $ticks[] = (int) round($ceiling / $count * $step);
        }

        return $ticks;
    }
}
