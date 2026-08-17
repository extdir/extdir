<?php

declare(strict_types=1);

namespace App\Distribution\Resolver;

use App\Catalog\Entity\Extension;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Finds the archives a maintainer attached to their releases, on one hosting
 * platform.
 *
 * docs/brief.md §9 asks for pluggable sources, and this is where that earns its keep:
 * every forge publishes release attachments, but none of them agree on the shape.
 * GitHub answers GraphQL, GitLab and Gitea answer REST at different paths, and the
 * German half of this ecosystem runs self-hosted instances of both — so the base
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
     * @return array<string, ResolvedDownload>
     */
    public function forExtension(Extension $extension): array;
}
