<?php

declare(strict_types=1);

namespace App\Compatibility;

use App\Compatibility\Enum\ConstraintSource;
use App\Compatibility\Enum\ConstraintTier;
use Composer\Semver\Interval;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;

/**
 * Reads a release's Shopware compatibility declaration out of its composer.json
 * and decides how much confidence it deserves.
 *
 * This is the most consequential class in the codebase: the compatibility matrix is
 * the product, and it is derived entirely from what this returns. It is also the
 * class most likely to be subtly wrong in a way tests do not catch, which is why
 * the plan calls for hand-checking twenty well-known extensions against their
 * READMEs after any change here.
 */
final class ConstraintParser
{
    /**
     * composer/semver's sentinel for "no upper bound", as a version string.
     * Comparing against it is how an unbounded constraint is recognised.
     */
    private readonly string $positiveInfinity;

    public function __construct(
        private readonly VersionParser $versionParser = new VersionParser(),
    ) {
        $this->positiveInfinity = Interval::untilPositiveInfinity()->getVersion();
    }

    /**
     * @param array<string, mixed> $composerJson
     */
    public function parse(array $composerJson): ParsedConstraint
    {
        $require = $composerJson['require'] ?? null;
        if (!\is_array($require)) {
            return ParsedConstraint::absent();
        }

        foreach (ConstraintSource::preferenceOrder() as $source) {
            $raw = $require[$source->packageName()] ?? null;

            if (!\is_string($raw) || '' === trim($raw)) {
                continue;
            }

            return $this->interpret(trim($raw), $source);
        }

        return ParsedConstraint::absent();
    }

    private function interpret(string $raw, ConstraintSource $source): ParsedConstraint
    {
        try {
            $constraint = $this->versionParser->parseConstraints($raw);
            $intervals = Intervals::get($constraint);
        } catch (\UnexpectedValueException) {
            return ParsedConstraint::unparseable($raw, $source);
        }

        if ([] === $intervals['numeric']) {
            // Parses, but matches no numeric version at all, e.g. a pure branch
            // constraint like `dev-trunk`. Nothing to test a release against.
            return ParsedConstraint::unparseable($raw, $source);
        }

        return ParsedConstraint::found($raw, $source, $this->tierFor(array_values($intervals['numeric'])));
    }

    /**
     * The tiering rule, and the reasoning behind it.
     *
     * `^6.5` is not the same kind of statement as `~6.6.0`, even though Composer
     * treats both as bounded ranges. `^6.5` expands to `>=6.5 <7.0`, which means it
     * silently claims every Shopware minor released after the maintainer wrote it,
     * including ones that did not exist yet and were never tested. `~6.6.0` expands
     * to `>=6.6 <6.7`, a deliberate statement about one minor.
     *
     * So the distinction is not "has an upper bound" but "where the upper bound
     * sits": a constraint whose ceiling is the next *major* is making a forward
     * promise it cannot have verified, and is tiered down accordingly.
     *
     * @param list<Interval> $numeric
     */
    private function tierFor(array $numeric): ConstraintTier
    {
        $start = $numeric[0]->getStart()->getVersion();
        $end = $numeric[\count($numeric) - 1]->getEnd()->getVersion();

        if ($end === $this->positiveInfinity) {
            return ConstraintTier::Wildcard;
        }

        $startMajor = $this->majorOf($start);
        $endMajor = $this->majorOf($end);

        // An upper bound at the next major (or beyond) covers every future minor
        // of the current one, which is the `^6.5` case.
        return $endMajor > $startMajor
            ? ConstraintTier::Caret
            : ConstraintTier::Explicit;
    }

    private function majorOf(string $version): int
    {
        return (int) explode('.', $version)[0];
    }
}
