<?php

declare(strict_types=1);

namespace App\Distribution\Resolver;

use App\Catalog\Entity\Extension;
use App\Ingestion\GitHub\GitHubClient;
use App\Signals\RepositoryEnricher;

/**
 * Looks up the ZIP files maintainers attach to their GitHub releases.
 *
 * This is step one of the §4.3 resolution order and the one that pays for itself
 * twice: it costs no storage, and for Shopware it is usually the *only* archive
 * that actually installs. A maintainer's release ZIP was produced by
 * `shopware-cli extension package`, so it contains the compiled administration and
 * storefront assets; a git zipball of the same tag contains source only and will
 * not run in a shop without a build step the merchant has no way to perform.
 *
 * Fetched per repository in one GraphQL query covering all tags at once, then held
 * in memory for the resolver chain to consult per release.
 */
final class ReleaseAssetIndex
{
    private const QUERY = <<<'GRAPHQL'
        query($owner: String!, $name: String!) {
            repository(owner: $owner, name: $name) {
                releases(first: 100, orderBy: {field: CREATED_AT, direction: DESC}) {
                    nodes {
                        tagName
                        isDraft
                        isPrerelease
                        tagCommit { oid }
                        releaseAssets(first: 20) {
                            nodes { name downloadUrl size contentType }
                        }
                    }
                }
            }
        }
        GRAPHQL;

    public function __construct(
        private readonly GitHubClient $github,
    ) {
    }

    /**
     * Release assets for one extension, keyed by tag name.
     *
     * @return array<string, ResolvedDownload>
     */
    public function forExtension(Extension $extension): array
    {
        $repo = RepositoryEnricher::parseRepository($extension->getRepositoryUrl());

        if (null === $repo) {
            return [];
        }

        $data = $this->github->graphql(self::QUERY, ['owner' => $repo[0], 'name' => $repo[1]]);
        $nodes = $data['repository']['releases']['nodes'] ?? null;

        if (!\is_array($nodes)) {
            return [];
        }

        $assets = [];

        foreach ($nodes as $release) {
            if (!\is_array($release) || true === ($release['isDraft'] ?? false)) {
                continue;
            }

            $tag = $release['tagName'] ?? null;
            if (!\is_string($tag)) {
                continue;
            }

            $asset = $this->pickInstallableAsset($release['releaseAssets']['nodes'] ?? []);
            if (null === $asset) {
                continue;
            }

            $commit = $release['tagCommit']['oid'] ?? null;

            $assets[$tag] = new ResolvedDownload(
                url: $asset['url'],
                source: \App\Distribution\Enum\DistSource::ReleaseAsset,
                commitSha: \is_string($commit) ? $commit : null,
                sizeBytes: $asset['size'],
            );
        }

        return $assets;
    }

    /**
     * Picks the plugin archive from a release's attachments.
     *
     * Releases routinely carry things that are not the plugin — checksum files,
     * signatures, changelogs, source archives GitHub generates automatically.
     * Handing a merchant a `.zip.sha256` because it sorted first would be worse
     * than offering nothing.
     *
     * @return array{url: string, size: int|null}|null
     */
    private function pickInstallableAsset(mixed $nodes): ?array
    {
        if (!\is_array($nodes)) {
            return null;
        }

        foreach ($nodes as $asset) {
            if (!\is_array($asset)) {
                continue;
            }

            $name = $asset['name'] ?? null;
            $url = $asset['downloadUrl'] ?? null;

            if (!\is_string($name) || !\is_string($url)) {
                continue;
            }

            if (!str_ends_with(strtolower($name), '.zip')) {
                continue;
            }

            // Signature and checksum files are named after the archive they
            // describe, so they end in .zip only after another suffix is stripped.
            if (preg_match('/\.(sha256|sha512|md5|asc|sig)\.zip$/i', $name)) {
                continue;
            }

            $size = $asset['size'] ?? null;

            return ['url' => $url, 'size' => \is_int($size) ? $size : null];
        }

        return null;
    }
}
