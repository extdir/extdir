<?php

declare(strict_types=1);

namespace App\Submission;

use App\Submission\Entity\OwnershipClaim;

/**
 * The outcome of an ownership check.
 *
 * Three states rather than a boolean, because "we could not check" and "you do not
 * have access" are different facts and telling a maintainer the second when the
 * first is true is both wrong and unpleasant. Only `verified` grants anything;
 * only `denied` is a statement about the person.
 */
final readonly class VerificationResult
{
    private function __construct(
        public bool $isVerified,
        public bool $isAvailable,
        public string $message,
        public ?OwnershipClaim $claim = null,
    ) {
    }

    public static function verified(OwnershipClaim $claim): self
    {
        return new self(true, true, 'Ownership verified.', $claim);
    }

    public static function denied(string $message): self
    {
        return new self(false, true, $message);
    }

    /**
     * The check could not be performed — GitHub was unreachable, or the forge has
     * no API we can ask. Not a judgement about the person, and the caller should
     * offer the proof-file route rather than turning them away.
     */
    public static function unavailable(string $message): self
    {
        return new self(false, false, $message);
    }
}
