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
     * @param list<string> $declaredVersions Shopware minors this alternative declares,
     *                                       already computed during scoring
     */
    public function __construct(
        public Extension $extension,
        public float $score,
        public array $reasons,
        public array $declaredVersions = [],
    ) {
    }
}
