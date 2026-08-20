<?php

declare(strict_types=1);

namespace App\Submission\Enum;

/**
 * What happened to an extension, and at whose hand.
 *
 * The audit log exists because the legal obligations requires a takedown procedure that
 * can be shown to have been followed, and the conflict-of-interest rule requires that no vendor gets
 * treatment another vendor could not. Both of those are claims about history, and
 * a claim about history needs a record — one that is written by the same code path
 * for everyone, including the maintainer of this directory.
 */
enum ModerationActionType: string
{
    case OwnershipVerified = 'ownership_verified';
    case OwnershipRevoked = 'ownership_revoked';

    /** Removed from the index — by its maintainer, or after a rights complaint. */
    case Delisted = 'delisted';

    /** Restored after a delisting was withdrawn or found unfounded. */
    case Relisted = 'relisted';

    case MetadataCorrected = 'metadata_corrected';

    /**
     * Somebody pointed us at a repository the crawlers had not found.
     *
     * Recorded because it is the one way into the catalogue that a person chose
     * rather than an algorithm, and the audit log should be able to answer "why is
     * this here" for every entry, not only for the ones that were removed.
     */
    case Submitted = 'submitted';

    public function label(): string
    {
        return match ($this) {
            self::OwnershipVerified => 'Ownership verified',
            self::OwnershipRevoked => 'Ownership revoked',
            self::Delisted => 'Removed from the index',
            self::Relisted => 'Restored to the index',
            self::MetadataCorrected => 'Metadata corrected',
            self::Submitted => 'Submitted by a visitor',
        };
    }
}
