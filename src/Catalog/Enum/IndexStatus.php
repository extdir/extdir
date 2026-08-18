<?php

declare(strict_types=1);

namespace App\Catalog\Enum;

/**
 * How far the directory is willing to go for a given extension.
 *
 * This is the visible consequence of the license gate and the takedown policy. It
 * is separate from LicenseStatus on purpose: an extension can be perfectly MIT and
 * still be `Delisted` because its author asked us to remove it, and the legal obligations
 * requires that removal path to exist before launch rather than after the first
 * complaint.
 */
enum IndexStatus: string
{
    /** Discovered but not yet processed enough to show. */
    case Pending = 'pending';

    /** Listed with full metadata. Distribution still depends on LicenseStatus. */
    case Listed = 'listed';

    /**
     * Listed with an external link and a visible "License unknown — not
     * redistributable" badge. No ZIP, no Satis entry, no mirroring (the licence gate).
     */
    case IndexOnly = 'index_only';

    /** Removed on request or after a complaint. Retained as a tombstone with a reason. */
    case Delisted = 'delisted';

    public function isPubliclyVisible(): bool
    {
        return match ($this) {
            self::Listed, self::IndexOnly => true,
            self::Pending, self::Delisted => false,
        };
    }
}
