<?php

declare(strict_types=1);

namespace App\Signals\Enum;

/**
 * Maintenance state, measured against Shopware's release cadence rather than the
 * calendar.
 *
 * The ranking guidance proposes an "abandoned" badge after 18 months of inactivity. That
 * rule misfires in this ecosystem: a small, single-purpose plugin can be finished
 * and sit untouched for two years while working perfectly — right up until a major
 * core release changes something under it. Time-since-commit alone would badge
 * healthy extensions as dead, and every false positive is an angry maintainer email
 * and a moderation ticket (the legal obligations calls moderation "weekly labour"; this is how that
 * bill gets run up).
 *
 * The question a merchant actually asks is not "is this old?" but "has anyone
 * touched it since the Shopware version I run came out?". So the signal is relative
 * to the ShopwareVersion release timeline, with an absolute floor as a backstop.
 */
enum MaintenanceStatus: string
{
    /** Commits landed after the newest Shopware minor shipped. */
    case Current = 'current';

    /** Quiet since the newest minor, but active after the one before it. */
    case Lagging = 'lagging';

    /** Silent across two or more core minors AND idle for over 24 months. */
    case Dormant = 'dormant';

    /** Explicitly marked abandoned by the maintainer on Packagist. Not a guess. */
    case Abandoned = 'abandoned';

    /** Not enough repository data collected yet to say. */
    case Unknown = 'unknown';

    public function rankingWeight(): float
    {
        return match ($this) {
            self::Current => 1.0,
            self::Lagging => 0.7,
            self::Dormant => 0.25,
            self::Abandoned => 0.1,
            self::Unknown => 0.5,
        };
    }

    /**
     * Whether to warn the visitor. An abandoned or dormant extension running in a
     * production shop is a deferred security hole (the ranking guidance), so this is surfaced
     * prominently rather than buried in metadata.
     */
    public function warrantsWarning(): bool
    {
        return match ($this) {
            self::Dormant, self::Abandoned => true,
            default => false,
        };
    }
}
