<?php

declare(strict_types=1);

namespace App\Signals;

/**
 * Reads install counts out of a Packagist package document.
 *
 * Separated from the HTTP so it can be tested against the shapes the API actually
 * returns rather than the shape it is supposed to return. Packagist has been serving
 * this endpoint for well over a decade and the corpus contains packages registered
 * across all of it: some carry a `downloads` object with three keys, some carry a bare
 * integer from an older revision of the format, and a package deleted between the
 * listing call and this one answers with a document that has no `package` key at all.
 *
 * Every one of those is a normal Tuesday, not an error worth stopping a sweep for, so
 * the answer is null and the caller moves on.
 */
final class PackagistPopularity
{
    /**
     * @param array<string, mixed> $payload the decoded package document
     *
     * @return array{total: int, monthly: int}|null null when the document carries no usable counts
     */
    public static function fromPayload(array $payload): ?array
    {
        $package = $payload['package'] ?? null;

        if (!\is_array($package)) {
            return null;
        }

        $downloads = $package['downloads'] ?? null;

        // The older shape: `downloads` as a plain integer meaning the lifetime total.
        // There is no monthly figure to be had, and reporting the total as the monthly
        // count would put the package at the top of the wrong board.
        if (\is_int($downloads)) {
            return ['total' => max(0, $downloads), 'monthly' => 0];
        }

        if (!\is_array($downloads)) {
            return null;
        }

        $total = self::count($downloads['total'] ?? null);

        if (null === $total) {
            return null;
        }

        // A missing monthly figure is not a reason to discard the total. Packages
        // registered in the last few days legitimately have one.
        return ['total' => $total, 'monthly' => self::count($downloads['monthly'] ?? null) ?? 0];
    }

    /**
     * A non-negative integer, or null if the value is not one.
     *
     * Accepts a numeric string because JSON from this API is not consistent about
     * quoting large counts, and rejects a negative outright: it can only be a bug at
     * one end or the other, and a negative count would sort to the bottom of a board
     * silently rather than being noticed.
     */
    private static function count(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (\is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
