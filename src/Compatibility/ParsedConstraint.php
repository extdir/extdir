<?php

declare(strict_types=1);

namespace App\Compatibility;

use App\Compatibility\Enum\ConstraintSource;
use App\Compatibility\Enum\ConstraintTier;

/**
 * The outcome of reading a release's Shopware compatibility declaration.
 *
 * The raw string is carried alongside the interpretation because the detail page
 * always shows the maintainer's own words. If our tiering is ever wrong, a reader
 * can see the actual constraint and judge for themselves, which is the difference
 * between a directory that can be checked and one that has to be trusted.
 */
final readonly class ParsedConstraint
{
    private function __construct(
        public ?string $raw,
        public ConstraintSource $source,
        public ConstraintTier $tier,
        public bool $parseable,
    ) {
    }

    public static function found(string $raw, ConstraintSource $source, ConstraintTier $tier): self
    {
        return new self($raw, $source, $tier, true);
    }

    /**
     * A constraint was declared but composer/semver could not read it, a typo, or
     * a syntax we do not support. Kept visible rather than discarded: "declared
     * something unreadable" is a different fact from "declared nothing", and it is
     * usually a bug worth reporting upstream.
     */
    public static function unparseable(string $raw, ConstraintSource $source): self
    {
        return new self($raw, $source, ConstraintTier::Absent, false);
    }

    public static function absent(): self
    {
        return new self(null, ConstraintSource::None, ConstraintTier::Absent, false);
    }

    /**
     * Whether this constraint can be tested against a Shopware version at all.
     * Only parseable constraints produce compatibility claims; everything else
     * leaves the matrix cell empty rather than filling it with a guess.
     */
    public function isTestable(): bool
    {
        return $this->parseable && null !== $this->raw;
    }
}
