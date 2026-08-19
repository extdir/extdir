<?php

declare(strict_types=1);

namespace App\Catalog\Alternatives;

use App\Catalog\Entity\Extension;

/**
 * One suggested alternative, with the reason it was suggested.
 *
 * The reason is carried rather than computed again in the template, because a list
 * of "similar extensions" with no stated basis is a list nobody can check. Saying
 * "same category, covers the same Shopware versions" lets a reader disagree with the
 * suggestion, which is the difference between a recommendation and an assertion.
 */
final readonly class Alternative
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public Extension $extension,
        public float $score,
        public array $reasons,
    ) {
    }
}
