<?php

declare(strict_types=1);

namespace App\Ingestion\Packagist;

use Composer\MetadataMinifier\MetadataMinifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads package metadata from Packagist.
 *
 * This is the primary metadata source for the whole directory, and the reason the
 * GitHub API budget can be spent entirely on maintenance signals: the p2 endpoint
 * returns *every published version with its full require block* in one request, so
 * the complete compatibility matrix for a package costs a single HTTP call and zero
 * GitHub quota.
 */
final class PackagistClient
{
    /**
     * Composer's own plugin type for Shopware 6 platform plugins. docs/brief.md §7
     * makes this the discovery seed rather than GitHub search, because it is
     * structured, complete and free of the false positives a topic search returns.
     */
    public const SHOPWARE_PLUGIN_TYPE = 'shopware-platform-plugin';

    public function __construct(
        #[Autowire(service: 'packagist.repo')]
        private readonly HttpClientInterface $repoClient,
        #[Autowire(service: 'packagist.api')]
        private readonly HttpClientInterface $apiClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Every package name of a given Composer type.
     *
     * @return list<string>
     */
    public function listPackageNames(string $type = self::SHOPWARE_PLUGIN_TYPE): array
    {
        $response = $this->apiClient->request('GET', 'packages/list.json', [
            'query' => ['type' => $type],
        ]);

        /** @var array{packageNames?: list<string>} $data */
        $data = $response->toArray();

        return $data['packageNames'] ?? [];
    }

    /**
     * All published versions of a package, delta-expanded.
     *
     * The p2 documents use Composer's "minified" encoding: the first entry is
     * complete and every later entry lists only the fields that changed, with the
     * literal string `__unset` marking removals. Reading them as-is is the single
     * most damaging mistake available here — for shopware/core, 122 of 209 entries
     * carry no `require` key at all, so a naive reader would conclude that most
     * versions declare no Shopware constraint and would render an empty matrix
     * while looking perfectly healthy.
     *
     * Expansion is delegated to composer/metadata-minifier, which is the same
     * implementation Composer itself uses to read these files.
     *
     * @return list<array<string, mixed>> newest first, as Packagist orders them
     */
    public function fetchVersions(string $packageName, bool $includeDev = false): array
    {
        $versions = $this->fetchMetadataDocument($packageName, false);

        if ($includeDev) {
            // Dev branches live in a separate document. They matter more than they
            // look: a plugin's support for a brand-new Shopware major often lands
            // on a branch weeks before it is tagged.
            $versions = [...$versions, ...$this->fetchMetadataDocument($packageName, true)];
        }

        return $versions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchMetadataDocument(string $packageName, bool $dev): array
    {
        $path = \sprintf('p2/%s%s.json', $packageName, $dev ? '~dev' : '');

        try {
            $response = $this->repoClient->request('GET', $path);

            if (404 === $response->getStatusCode()) {
                return [];
            }

            /** @var array{packages?: array<string, list<array<string, mixed>>>, minified?: string} $data */
            $data = $response->toArray();
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning('Packagist metadata fetch failed', [
                'package' => $packageName,
                'dev' => $dev,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $raw = $data['packages'][$packageName] ?? [];
        if ([] === $raw) {
            return [];
        }

        // Guard rather than assume: if Packagist ever serves an unminified
        // document, expanding it would be wrong in a way that is invisible.
        if ('composer/2.0' !== ($data['minified'] ?? null)) {
            return $raw;
        }

        /** @var list<array<string, mixed>> $expanded */
        $expanded = MetadataMinifier::expand($raw);

        return $expanded;
    }
}
