<?php

declare(strict_types=1);

namespace App\License\Enum;

/**
 * The redistribution decision for an extension, derived from license detection.
 *
 * The licence gate is the hard rule this enum enforces: a repository with no LICENSE
 * file is "all rights reserved", not open source. Public readability on GitHub does
 * not grant anyone the right to redistribute. Anything that is not positively
 * identified as an accepted SPDX license is `Unknown`, and `Unknown` never gets
 * built, mirrored or hosted, only linked.
 */
enum LicenseStatus: string
{
    /** MIT, BSD, Apache-2.0 and friends. Redistributable with notice preserved. */
    case Permissive = 'permissive';

    /**
     * GPL/AGPL/LGPL. Genuinely open source and redistributable, but categorised
     * separately: the licence gate forbids lumping copyleft extensions under an "MIT/open
     * source" label, because the obligations they place on a merchant differ.
     */
    case Copyleft = 'copyleft';

    /** Detected, but not on the accepted list. Index only, never redistribute. */
    case Rejected = 'rejected';

    /** No license detected. The default, and the safe one. */
    case Unknown = 'unknown';

    /**
     * The gate. Only a positive answer here may lead to a build or a hosted ZIP.
     * Note that this is necessary but not sufficient: the authoritative check runs
     * a real detector inside CI over the actual checkout before packaging, because
     * a composer.json `license` field is a claim, not evidence.
     */
    public function isRedistributable(): bool
    {
        return match ($this) {
            self::Permissive, self::Copyleft => true,
            self::Rejected, self::Unknown => false,
        };
    }

    public function badgeLabel(): string
    {
        return match ($this) {
            self::Permissive => 'Open source',
            self::Copyleft => 'Copyleft',
            self::Rejected => 'Not open source',
            self::Unknown => 'License unknown, not redistributable',
        };
    }
}
