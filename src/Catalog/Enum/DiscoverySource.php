<?php

declare(strict_types=1);

namespace App\Catalog\Enum;

/**
 * How an extension entered the index.
 *
 * This is not bookkeeping — it decides whether the extension appears in our
 * Composer repository. Packagist discovery filters on the
 * `shopware-platform-plugin` type, so anything that arrived that way is on
 * Packagist by definition and `composer require` already works for it. Publishing
 * it again from here would add a staler mirror and put us in the dependency path of
 * installs that do not need us there.
 *
 * Extensions found only by GitHub topic are the opposite case: there is no
 * Packagist entry to resolve, so today a merchant cannot install them with Composer
 * at all. They are the reason the repository exists.
 */
enum DiscoverySource: string
{
    case Packagist = 'packagist';
    case GitHubTopic = 'github_topic';

    /**
     * Found by searching repositories rather than by a topic the maintainer set.
     *
     * Same standing as GitHubTopic — not on Packagist, so it is exactly what the
     * Composer repository is for. Recorded separately only so the channel's yield and
     * its noise can be measured rather than guessed at.
     */
    case GitHubSearch = 'github_search';

    /** Somebody pointed us at it. Also not on Packagist, or a crawl would have it. */
    case Submitted = 'submitted';

    public function isOnPackagist(): bool
    {
        return self::Packagist === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Packagist => 'Packagist',
            self::GitHubTopic => 'GitHub (not on Packagist)',
            self::GitHubSearch => 'GitHub (not on Packagist)',
            self::Submitted => 'Submitted (not on Packagist)',
        };
    }
}
