<?php

declare(strict_types=1);

namespace App\Tests\Ingestion\Packagist;

use App\Ingestion\Packagist\PackagistClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(PackagistClient::class)]
final class PackagistClientTest extends TestCase
{
    /**
     * The delta-expansion guard.
     *
     * Packagist's p2 documents are minified: only the first entry is complete, and
     * every later entry lists just what changed. For shopware/core, 122 of 209
     * entries carry no `require` key at all. A reader that skips expansion produces
     * a directory where most versions appear to declare no Shopware constraint,
     * and it fails silently, rendering an empty compatibility matrix while every
     * request returns HTTP 200. This test exists to make that failure loud.
     */
    public function testMinifiedVersionsAreExpandedFromTheirPredecessor(): void
    {
        $client = $this->clientReturning([
            'minified' => 'composer/2.0',
            'packages' => [
                'acme/plugin' => [
                    [
                        'name' => 'acme/plugin',
                        'version' => 'v2.0.0',
                        'version_normalized' => '2.0.0.0',
                        'license' => ['MIT'],
                        'require' => ['shopware/core' => '~6.6.0'],
                        'type' => 'shopware-platform-plugin',
                    ],
                    // Carries only what changed. Everything else is inherited.
                    [
                        'version' => 'v1.9.0',
                        'version_normalized' => '1.9.0.0',
                    ],
                    [
                        'version' => 'v1.8.0',
                        'version_normalized' => '1.8.0.0',
                        'require' => ['shopware/core' => '~6.5.0'],
                    ],
                ],
            ],
        ]);

        $versions = $client->fetchVersions('acme/plugin');

        self::assertCount(3, $versions);

        // The inheriting entry must end up with the predecessor's constraint,
        // license and type, not with nothing.
        self::assertSame('1.9.0.0', $versions[1]['version_normalized']);
        self::assertSame(['shopware/core' => '~6.6.0'], $versions[1]['require']);
        self::assertSame(['MIT'], $versions[1]['license']);
        self::assertSame('shopware-platform-plugin', $versions[1]['type']);

        // An entry that does supply its own value overrides rather than merges.
        self::assertSame(['shopware/core' => '~6.5.0'], $versions[2]['require']);

        // And the inherited fields still travel alongside the override.
        self::assertSame('acme/plugin', $versions[2]['name']);
    }

    /**
     * `__unset` is how the format expresses "this field is gone from here on".
     * Treating it as a literal value would leave the string "__unset" sitting in
     * place of a require block.
     */
    public function testUnsetMarkerRemovesTheInheritedField(): void
    {
        $client = $this->clientReturning([
            'minified' => 'composer/2.0',
            'packages' => [
                'acme/plugin' => [
                    [
                        'name' => 'acme/plugin',
                        'version' => 'v2.0.0',
                        'version_normalized' => '2.0.0.0',
                        'require-dev' => ['phpunit/phpunit' => '^10'],
                        'require' => ['shopware/core' => '~6.6.0'],
                    ],
                    [
                        'version' => 'v1.0.0',
                        'version_normalized' => '1.0.0.0',
                        'require-dev' => '__unset',
                    ],
                ],
            ],
        ]);

        $versions = $client->fetchVersions('acme/plugin');

        self::assertArrayNotHasKey('require-dev', $versions[1]);
        self::assertSame(['shopware/core' => '~6.6.0'], $versions[1]['require']);
    }

    /**
     * If Packagist ever serves an unminified document, expanding it would corrupt
     * the data in a way nothing downstream could detect. The client checks the
     * marker rather than assuming the encoding.
     */
    public function testUnminifiedDocumentsArePassedThroughUnchanged(): void
    {
        $client = $this->clientReturning([
            'packages' => [
                'acme/plugin' => [
                    ['version' => 'v2.0.0', 'require' => ['shopware/core' => '~6.6.0']],
                    ['version' => 'v1.0.0'],
                ],
            ],
        ]);

        $versions = $client->fetchVersions('acme/plugin');

        self::assertCount(2, $versions);
        self::assertArrayNotHasKey('require', $versions[1]);
    }

    public function testUnknownPackageYieldsNoVersions(): void
    {
        $client = new PackagistClient(
            new MockHttpClient(new MockResponse('', ['http_code' => 404])),
            new MockHttpClient(),
            new NullLogger(),
        );

        self::assertSame([], $client->fetchVersions('acme/missing'));
    }

    public function testPackageListIsReadFromTheWebsiteApi(): void
    {
        $client = new PackagistClient(
            new MockHttpClient(),
            new MockHttpClient(new MockResponse(json_encode([
                'packageNames' => ['acme/plugin', 'frosh/tools'],
            ], \JSON_THROW_ON_ERROR))),
            new NullLogger(),
        );

        self::assertSame(['acme/plugin', 'frosh/tools'], $client->listPackageNames());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function clientReturning(array $payload): PackagistClient
    {
        return new PackagistClient(
            new MockHttpClient(new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR))),
            new MockHttpClient(),
            new NullLogger(),
        );
    }
}
