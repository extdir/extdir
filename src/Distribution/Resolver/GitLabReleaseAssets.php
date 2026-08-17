<?php

declare(strict_types=1);

namespace App\Distribution\Resolver;

use App\Catalog\Entity\Extension;
use App\Catalog\Enum\SourceHost;
use App\Distribution\Enum\DistSource;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Release attachments from GitLab, including self-hosted instances.
 *
 * Self-hosted support is the point rather than a bonus. docs/brief.md §7 singles out
 * GitLab because German Shopware agencies commonly run their own, and the corpus
 * bears that out: of the GitLab-hosted extensions indexed, more than a third live
 * on instances like `gitlab.jonathan-martz.de` rather than gitlab.com. A resolver
 * hardcoded to gitlab.com would miss exactly the repositories §7 is worried about.
 *
 * No authentication is used. These are public projects, our volume is a handful of
 * requests per crawl, and asking a stranger's GitLab for a token to read something
 * already public would be both rude and unworkable.
 */
final class GitLabReleaseAssets implements ReleaseAssetSource
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly AssetNaming $naming,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Extension $extension): bool
    {
        return SourceHost::GitLab === $extension->getSourceHost();
    }

    public function forExtension(Extension $extension): array
    {
        $target = $this->parse($extension->getRepositoryUrl());

        if (null === $target) {
            return [];
        }

        [$base, $projectPath] = $target;

        // GitLab identifies a project either by numeric id or by its full path
        // URL-encoded — including the slashes, which is why rawurlencode is applied
        // to the whole path rather than per segment. Groups nest arbitrarily deep
        // ("fyrst/shopware/OrderStates"), so splitting on the first slash is wrong.
        $url = \sprintf('%s/api/v4/projects/%s/releases', $base, rawurlencode($projectPath));

        try {
            $response = $this->http->request('GET', $url, [
                'timeout' => 8,
                'max_duration' => 15,
            ]);

            if (200 !== $response->getStatusCode()) {
                return [];
            }

            /** @var list<array<string, mixed>> $releases */
            $releases = $response->toArray(false);
        } catch (HttpExceptionInterface|\JsonException $e) {
            // A self-hosted instance being unreachable is routine, not exceptional.
            // Degrade to the Packagist source archive rather than failing the crawl.
            $this->logger->info('GitLab releases unavailable', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return $this->mapReleases($releases, $base);
    }

    /**
     * @param list<array<string, mixed>> $releases
     *
     * @return array<string, ResolvedDownload>
     */
    private function mapReleases(array $releases, string $base): array
    {
        $assets = [];

        foreach ($releases as $release) {
            $tag = $release['tag_name'] ?? null;
            if (!\is_string($tag)) {
                continue;
            }

            // `assets.links` are what a maintainer deliberately attached.
            // `assets.sources` are the archives GitLab generates from the tag, which
            // are equivalent to what Packagist already gives us — so only links are
            // treated as a maintainer-built artifact.
            $links = $release['assets']['links'] ?? null;
            if (!\is_array($links)) {
                continue;
            }

            foreach ($links as $link) {
                if (!\is_array($link)) {
                    continue;
                }

                $name = $link['name'] ?? null;
                $url = $link['direct_asset_url'] ?? $link['url'] ?? null;

                if (!\is_string($name) || !\is_string($url) || !$this->naming->isPluginArchive($name)) {
                    continue;
                }

                $commit = $release['commit']['id'] ?? null;

                $assets[$tag] = new ResolvedDownload(
                    url: $this->absolutise($url, $base),
                    source: DistSource::ReleaseAsset,
                    commitSha: \is_string($commit) ? $commit : null,
                );

                break;
            }
        }

        return $assets;
    }

    /**
     * `direct_asset_url` can be relative to the project on some GitLab versions.
     */
    private function absolutise(string $url, string $base): string
    {
        return str_starts_with($url, 'http') ? $url : rtrim($base, '/').'/'.ltrim($url, '/');
    }

    /**
     * Splits a repository URL into an API base and the project path.
     *
     * @return array{string, string}|null
     */
    private function parse(?string $repositoryUrl): ?array
    {
        if (null === $repositoryUrl) {
            return null;
        }

        $parts = parse_url($repositoryUrl);
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? null;

        if (!\is_string($host) || !\is_string($path)) {
            return null;
        }

        $projectPath = trim(preg_replace('/\.git$/', '', $path) ?? '', '/');

        if ('' === $projectPath || !str_contains($projectPath, '/')) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';

        return [\sprintf('%s://%s', $scheme, $host), $projectPath];
    }
}
