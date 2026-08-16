<?php

declare(strict_types=1);

namespace App\Distribution\Enum;

/**
 * Where a downloadable archive for a release comes from.
 *
 * The order of these cases is the resolution order from docs/brief.md §4.3, and it is
 * not just about storage cost. For Shopware specifically it is also about whether
 * the archive *works*: a raw git zipball contains source only, so an extension with
 * an administration or storefront bundle ships without its compiled assets and
 * cannot simply be dropped into a shop. A ZIP the maintainer attached to a release
 * was built with shopware-cli and does contain them.
 *
 * So preferring the maintainer's own artifact is the technically correct choice as
 * well as the cheap and legally safe one.
 */
enum DistSource: string
{
    /** A ZIP the maintainer attached to a GitHub release. Best case: correct and free. */
    case ReleaseAsset = 'release_asset';

    /** GitHub's generated zipball for a tag. Source only — no built assets. */
    case TagZipball = 'tag_zipball';

    /** Built by extdir because neither of the above existed. Always labelled unofficial. */
    case Built = 'built';

    public function isHostedByUs(): bool
    {
        return self::Built === $this;
    }

    /**
     * Whether the archive can be installed into a shop as-is.
     *
     * A tag zipball usually cannot, for extensions that ship admin or storefront
     * code — which is most of them. Saying so plainly is more useful than offering
     * a download that fails after the merchant has already unzipped it.
     */
    public function isInstallableAsIs(): bool
    {
        return self::TagZipball !== $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::ReleaseAsset => 'Official release archive',
            self::TagZipball => 'Source archive (no built assets)',
            self::Built => 'Built by extdir (unofficial)',
        };
    }
}
