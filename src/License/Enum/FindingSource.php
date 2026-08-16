<?php

declare(strict_types=1);

namespace App\License\Enum;

/**
 * Where a license finding came from, kept as evidence rather than collapsed into a
 * single string on the extension.
 *
 * The distinction matters because the two stages carry different authority. A
 * `composer.json` license field is cheap to read at ingest and good enough to badge
 * with, but it is an unverified self-declaration. Only a real detector run over the
 * actual files may authorise redistribution (docs/brief.md §4.1: "Detect licenses with
 * a real detector. Never infer from the README.").
 */
enum FindingSource: string
{
    /** The `license` field of composer.json. Stage 1: cheap, indicative, not binding. */
    case ComposerJson = 'composer_json';

    /** A LICENSE/COPYING file found in the repository, identified by content. */
    case LicenseFile = 'license_file';

    /** Output of askalono or licensee run over a checkout. Stage 2: authoritative. */
    case Detector = 'detector';

    /** A human decision recorded during moderation, e.g. after a takedown request. */
    case Manual = 'manual';

    /**
     * Whether a finding from this source is allowed to authorise a build.
     * Deliberately narrow — see the class docblock.
     */
    public function isAuthoritative(): bool
    {
        return match ($this) {
            self::Detector, self::Manual => true,
            self::ComposerJson, self::LicenseFile => false,
        };
    }
}
