<?php

declare(strict_types=1);

namespace App\Compatibility\Enum;

/**
 * How much weight a declared `shopware/core` constraint can carry.
 *
 * The constraint is self-reported by the maintainer and is frequently stale, a
 * plugin may declare `^6.4` and never bump it, or declare `*` and mean nothing at
 * all. The directory's central claim depends on this data, so the uncertainty is
 * modelled explicitly rather than averaged away: every compatibility statement in
 * the UI is qualified by its tier, and ranking penalises the weaker tiers.
 */
enum ConstraintTier: string
{
    /** A bounded range: `~6.6.0`, `>=6.5 <6.7`, `6.6.*`. The maintainer stated an upper bound. */
    case Explicit = 'explicit';

    /** An open upper bound: `^6.5`. Says where support starts, not where it ends. */
    case Caret = 'caret';

    /** `*`, `>=6.0` and similar. Technically parseable, practically meaningless. */
    case Wildcard = 'wildcard';

    /** No Shopware constraint declared on any of the core packages. */
    case Absent = 'absent';

    /**
     * Multiplier applied to the ranking score. Deliberately public and documented
     * on /ranking, the conflict-of-interest rule requires the algorithm be auditable, which means
     * these numbers live in one place and are rendered from the source of truth.
     */
    public function rankingWeight(): float
    {
        return match ($this) {
            self::Explicit => 1.0,
            self::Caret => 0.85,
            self::Wildcard => 0.4,
            self::Absent => 0.2,
        };
    }

    /**
     * Whether a compatibility claim at this tier may be stated without a caveat in
     * the UI. Only `Explicit` earns an unqualified "declares support for".
     */
    public function isConfident(): bool
    {
        return self::Explicit === $this;
    }
}
