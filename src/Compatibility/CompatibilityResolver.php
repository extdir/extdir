<?php

declare(strict_types=1);

namespace App\Compatibility;

use App\Compatibility\Entity\ShopwareVersion;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;

/**
 * Decides which Shopware minors a parsed constraint covers.
 *
 * The test is *interval intersection*, not "does a representative version match".
 * That choice is load-bearing. Testing `>=6.6.5` against a stand-in "6.6.0.0" would
 * report the extension as incompatible with 6.6, when in truth it supports 6.6 from
 * patch 5 onward. Every plugin that raises its floor mid-minor, which is common
 * after a core bugfix, would be wrongly marked unsupported, and the errors would
 * cluster precisely on the actively maintained extensions.
 *
 * The question we are answering is therefore "does this constraint overlap the
 * minor at all?", which is what a merchant on any patch of 6.6 needs to know.
 */
final class CompatibilityResolver
{
    /** @var array<string, \Composer\Semver\Constraint\ConstraintInterface> */
    private array $rangeCache = [];

    public function __construct(
        private readonly VersionParser $versionParser = new VersionParser(),
    ) {
    }

    /**
     * @param list<ShopwareVersion> $shopwareVersions
     *
     * @return array<string, bool> majorMinor => whether the constraint covers it
     */
    public function resolve(ParsedConstraint $parsed, array $shopwareVersions): array
    {
        $result = [];

        if (!$parsed->isTestable()) {
            // No claim can be derived, so none is recorded. Leaving the cells empty
            // is honest; filling them with false would assert incompatibility we
            // have no evidence for.
            foreach ($shopwareVersions as $version) {
                $result[$version->getMajorMinor()] = false;
            }

            return $result;
        }

        $constraint = $this->versionParser->parseConstraints((string) $parsed->raw);

        foreach ($shopwareVersions as $version) {
            $result[$version->getMajorMinor()] = Intervals::haveIntersections(
                $constraint,
                $this->rangeFor($version),
            );
        }

        return $result;
    }

    public function satisfies(ParsedConstraint $parsed, ShopwareVersion $version): bool
    {
        if (!$parsed->isTestable()) {
            return false;
        }

        return Intervals::haveIntersections(
            $this->versionParser->parseConstraints((string) $parsed->raw),
            $this->rangeFor($version),
        );
    }

    private function rangeFor(ShopwareVersion $version): \Composer\Semver\Constraint\ConstraintInterface
    {
        $key = $version->getMajorMinor();

        // A full backfill parses the same handful of Shopware ranges once per
        // release, tens of thousands of times over.
        return $this->rangeCache[$key] ??= $this->versionParser->parseConstraints(
            $version->toConstraintString(),
        );
    }
}
