<?php

declare(strict_types=1);

namespace App\Distribution\Resolver;

use App\Catalog\Entity\Extension;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Finds the archives a maintainer attached to their releases, on one hosting
 * platform.
 *
 * The Composer surface plan asks for pluggable sources, and this is where that earns its keep:
 * every forge publishes release attachments, but none of them agree on the shape.
 * GitHub answers GraphQL, GitLab and Gitea answer REST at different paths, and the
 * German half of this ecosystem runs self-hosted instances of both, so the base
 * URL cannot be a constant either.
 *
 * Implementations must fail soft. A self-hosted forge that is slow, unreachable or
 * behind a login is a normal Tuesday, and it must degrade to the Packagist source
 * archive rather than abort the crawl.
 */
#[AutoconfigureTag('app.release_asset_source')]
interface ReleaseAssetSource
{
    public function supports(Extension $extension): bool;

    /**
     * Maintainer-attached archives keyed by git tag.
     *
     * Returns null when the lookup itself failed, and an empty array when the
     * forge answered but the releases carry no archives. Collapsing those two into
     * one value is what let a transient API timeout silently overwrite a
     * maintainer's release archive with a source zipball, the data got quietly
     * worse on every flaky run, with nothing in the output to show it.
     *
     * @return array<string, ResolvedDownload>|null
     */
    public function forExtension(Extension $extension): ?array;
}
