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
 * Release attachments from Gitea and Forgejo instances, including Codeberg.
 *
 * Gitea's API deliberately mirrors GitHub's shape, so this is the simplest of the
 * three: one REST call, a flat `assets` array with `browser_download_url`. Forgejo
 * is a Gitea fork and answers the same routes, so both are handled here.
 *
 * Unauthenticated, for the same reason as GitLab — these are public repositories
 * and the request volume is tiny.
 */
final class GiteaReleaseAssets implements ReleaseAssetSource
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly AssetNaming $naming,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Extension $extension): bool
    {
        return SourceHost::Gitea === $extension->getSourceHost();
    }

    public function forExtension(Extension $extension): ?array
    {
        $target = $this->parse($extension->getRepositoryUrl());

        if (null === $target) {
            return [];
        }

        [$base, $owner, $repo] = $target;
        $url = \sprintf('%s/api/v1/repos/%s/%s/releases', $base, rawurlencode($owner), rawurlencode($repo));

        try {
            $response = $this->http->request('GET', $url, [
                'timeout' => 8,
                'max_duration' => 15,
            ]);

            if (200 !== $response->getStatusCode()) {
                return 404 === $response->getStatusCode() ? [] : null;
            }

            /** @var list<array<string, mixed>> $releases */
            $releases = $response->toArray(false);
        } catch (HttpExceptionInterface|\JsonException $e) {
            $this->logger->info('Gitea releases unavailable', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        $assets = [];

        foreach ($releases as $release) {
            $tag = $release['tag_name'] ?? null;
            $attachments = $release['assets'] ?? null;

            if (!\is_string($tag) || !\is_array($attachments) || true === ($release['draft'] ?? false)) {
                continue;
            }

            foreach ($attachments as $asset) {
                if (!\is_array($asset)) {
                    continue;
                }

                $name = $asset['name'] ?? null;
                $downloadUrl = $asset['browser_download_url'] ?? null;

                if (!\is_string($name) || !\is_string($downloadUrl) || !$this->naming->isPluginArchive($name)) {
                    continue;
                }

                $size = $asset['size'] ?? null;

                $assets[$tag] = new ResolvedDownload(
                    url: $downloadUrl,
                    source: DistSource::ReleaseAsset,
                    sizeBytes: \is_int($size) ? $size : null,
                );

                break;
            }
        }

        return $assets;
    }

    /**
     * @return array{string, string, string}|null
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

        $segments = array_values(array_filter(explode('/', trim(preg_replace('/\.git$/', '', $path) ?? '', '/'))));

        if (2 !== \count($segments)) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';

        return [\sprintf('%s://%s', $scheme, $host), $segments[0], $segments[1]];
    }
}
