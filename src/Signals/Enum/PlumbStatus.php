<?php

declare(strict_types=1);

namespace App\Signals\Enum;

/**
 * The outcome of a single Plumb check.
 *
 * Plumb's own vocabulary, kept verbatim rather than mapped onto the site's existing
 * badge tones. These are somebody else's findings and the page says so; renaming
 * "warn" to something of ours would quietly make it look like our judgement.
 *
 * NotApplicable is a real and frequent answer here, not a gap. Plumb scores the whole
 * PHP ecosystem, so a Shopware extension is measured against Laravel checks that
 * cannot apply to it, and a repository on GitHub is measured against GitLab CI
 * checks it will never have. Those must read as "this question does not apply"
 * rather than as a failure or as missing data.
 *
 * Unassessable is the one that is neither. Plumb returns it when it could not look:
 * a repository that has been renamed, made private or taken down. Thirty one of the
 * scanned extensions carry it today, and collapsing it into NotApplicable would tell
 * their readers that a question does not apply when the truth is that nobody managed
 * to ask it.
 */
enum PlumbStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Warn = 'warn';
    case NotApplicable = 'not_applicable';
    case Unassessable = 'unassessable';

    /**
     * Plumb's string, or the safest reading of one this site has not seen.
     *
     * 'error' is folded into Unassessable rather than given a case of its own. It is
     * rare, two extensions today, and the difference between "we could not look" and
     * "looking threw" is Plumb's operational concern, not something a maintainer
     * reading an extension page can act on differently. Both mean no verdict.
     *
     * Anything else unknown reads as NotApplicable, which shows the check without
     * inventing a verdict for it. Their vocabulary is theirs to extend.
     */
    public static function from_(string $value): self
    {
        if ('error' === $value) {
            return self::Unassessable;
        }

        return self::tryFrom($value) ?? self::NotApplicable;
    }

    /**
     * Whether this is a finding a maintainer could act on.
     */
    public function isActionable(): bool
    {
        return self::Fail === $this || self::Warn === $this;
    }

    /**
     * The site's existing badge tone, for rendering only.
     *
     * A display mapping, deliberately not stored. The tones are this site's visual
     * vocabulary; the status is Plumb's finding, and the two must be free to change
     * independently.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Pass => 'ok',
            self::Fail => 'risk',
            self::Warn => 'caution',
            self::NotApplicable, self::Unassessable => 'muted',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'Pass',
            self::Fail => 'Fail',
            self::Warn => 'Warning',
            self::NotApplicable => 'Not applicable',
            self::Unassessable => 'Not assessed',
        };
    }
}
