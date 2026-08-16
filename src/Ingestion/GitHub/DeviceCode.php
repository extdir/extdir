<?php

declare(strict_types=1);

namespace App\Ingestion\GitHub;

/**
 * GitHub's response to a device-code request: what to show the operator and how
 * fast we are allowed to poll while they act on it.
 */
final readonly class DeviceCode
{
    public function __construct(
        public string $deviceCode,
        public string $userCode,
        public string $verificationUri,
        public int $interval,
        public int $expiresIn,
    ) {
    }
}
