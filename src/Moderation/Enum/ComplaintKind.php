<?php

declare(strict_types=1);

namespace App\Moderation\Enum;

/**
 * What a complaint is actually about.
 *
 * The kind decides the urgency and the response, so it is recorded rather than
 * inferred from the text later. A rights holder demanding removal and a maintainer
 * reporting a wrong category both arrive through the same form and need very
 * different handling.
 */
enum ComplaintKind: string
{
    /**
     * Copyright, trademark or licence infringement. The takedown policy commits to
     * acting within seven days, and this is the kind that clock applies to.
     */
    case Rights = 'rights';

    /** The recorded licence is wrong — the most common correctable error. */
    case Licence = 'licence';

    /** Malware, credential harvesting, or anything unsafe to install. */
    case Security = 'security';

    /** Wrong label, description, category or compatibility. */
    case Metadata = 'metadata';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Rights => 'Rights infringement',
            self::Licence => 'Wrong licence',
            self::Security => 'Security concern',
            self::Metadata => 'Incorrect metadata',
            self::Other => 'Something else',
        };
    }

    /**
     * Whether the seven-day promise in the takedown policy applies.
     *
     * Only rights and security complaints carry it. A miscategorised extension is
     * worth fixing but nobody is exposed while it waits, and treating every report
     * as urgent is how the genuinely urgent ones get lost.
     */
    public function isUrgent(): bool
    {
        return self::Rights === $this || self::Security === $this;
    }
}
