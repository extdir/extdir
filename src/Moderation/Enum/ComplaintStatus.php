<?php

declare(strict_types=1);

namespace App\Moderation\Enum;

enum ComplaintStatus: string
{
    case Open = 'open';

    /** Acted on: the extension was delisted, corrected, or otherwise changed. */
    case Upheld = 'upheld';

    /** Considered and refused. Recorded rather than deleted, because a complainant
     *  who disagrees deserves to see that it was read and answered. */
    case Rejected = 'rejected';

    public function isClosed(): bool
    {
        return self::Open !== $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Upheld => 'Upheld',
            self::Rejected => 'Rejected',
        };
    }
}
