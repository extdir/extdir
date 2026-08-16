<?php

declare(strict_types=1);

namespace App\Distribution\Enum;

/**
 * Lifecycle of a build we asked the isolated CI runner to perform.
 *
 * `Rejected` is deliberately distinct from `Failed`. A failed build is a technical
 * problem — a broken dependency, a timeout, a compiler error. A rejected build
 * means the licence gate refused it, and that is a decision rather than a fault: it
 * must never be retried automatically, and it must be visible as a reason rather
 * than as noise in a failure list.
 */
enum BuildState: string
{
    case Queued = 'queued';
    case Dispatched = 'dispatched';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Rejected = 'rejected';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Rejected => true,
            self::Queued, self::Dispatched => false,
        };
    }

    /**
     * A licence rejection is final until the upstream repository changes, so it is
     * never retried. Retrying would mean repeatedly asking a runner to build code
     * we have already established we may not redistribute.
     */
    public function isRetryable(): bool
    {
        return self::Failed === $this;
    }
}
