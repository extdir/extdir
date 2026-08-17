<?php

declare(strict_types=1);

namespace App\Distribution\Resolver;

use App\Catalog\Entity\Extension;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Routes an extension to whichever forge actually hosts it.
 *
 * Adding support for another platform means adding one class implementing
 * ReleaseAssetSource — nothing here changes, and neither does the resolver. That
 * matters because the corpus keeps disagreeing with assumptions about where
 * Shopware extensions live: Bitbucket turned out to host more of them than GitLab
 * and Gitea combined, which no part of the original design anticipated.
 *
 * An extension on a forge nobody implements is not an error. It falls through to
 * the Packagist source archive, which is the same outcome as a forge that has no
 * release attachments.
 */
final class ReleaseAssetRegistry
{
    /**
     * @param iterable<ReleaseAssetSource> $sources
     */
    public function __construct(
        #[AutowireIterator('app.release_asset_source')]
        private readonly iterable $sources,
    ) {
    }

    /**
     * Null means the lookup failed and existing artifacts must be left alone; an
     * empty array means there genuinely are no maintainer archives.
     *
     * @return array<string, ResolvedDownload>|null
     */
    public function forExtension(Extension $extension): ?array
    {
        foreach ($this->sources as $source) {
            if ($source->supports($extension)) {
                return $source->forExtension($extension);
            }
        }

        return [];
    }

    /**
     * Whether any implementation claims this extension, for reporting how much of
     * the corpus is reachable at all.
     */
    public function hasSourceFor(Extension $extension): bool
    {
        foreach ($this->sources as $source) {
            if ($source->supports($extension)) {
                return true;
            }
        }

        return false;
    }
}
