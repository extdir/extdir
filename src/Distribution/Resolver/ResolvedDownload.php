<?php

declare(strict_types=1);

namespace App\Distribution\Resolver;

use App\Distribution\Enum\DistSource;

/**
 * Where one release can be downloaded from, and what kind of archive it is.
 */
final readonly class ResolvedDownload
{
    public function __construct(
        public string $url,
        public DistSource $source,
        public ?string $commitSha = null,
        public ?int $sizeBytes = null,
    ) {
    }
}
