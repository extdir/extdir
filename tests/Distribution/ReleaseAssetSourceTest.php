<?php

declare(strict_types=1);

namespace App\Tests\Distribution;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Distribution\Enum\DistSource;
use App\Distribution\Resolver\AssetNaming;
use App\Distribution\Resolver\GiteaReleaseAssets;
use App\Distribution\Resolver\GitLabReleaseAssets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(GitLabReleaseAssets::class)]
#[CoversClass(GiteaReleaseAssets::class)]
#[CoversClass(AssetNaming::class)]
final class ReleaseAssetSourceTest extends TestCase
{
    /**
     * GitLab groups nest arbitrarily deep, "fyrst/shopware/OrderStates" is a real
     * example from the corpus. The project is addressed by its whole path,
     * URL-encoded including the slashes, so splitting on the first slash the way a
     * GitHub owner/repo parser does would produce a 404.
     */
    public function testGitLabAddressesNestedGroupsAsASinglePath(): void
    {
        $requested = null;
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested = $url;

            return new MockResponse('[]');
        });

        $source = new GitLabReleaseAssets($client, new AssetNaming(), new NullLogger());
        $source->forExtension($this->extension('https://gitlab.com/fyrst/shopware/OrderStates'));

        self::assertNotNull($requested);
        self::assertStringContainsString('fyrst%2Fshopware%2FOrderStates', $requested);
    }

    /**
     * Self-hosted instances are the reason the discovery plan names GitLab at all, and a third of
     * the GitLab-hosted extensions in the corpus are on one. The API base has to
     * follow the repository host rather than being gitlab.com.
     */
    public function testGitLabUsesTheRepositoryHostForSelfHostedInstances(): void
    {
        $requested = null;
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested = $url;

            return new MockResponse('[]');
        });

        $source = new GitLabReleaseAssets($client, new AssetNaming(), new NullLogger());
        $source->forExtension($this->extension('https://gitlab.jonathan-martz.de/shopware/plugin'));

        self::assertNotNull($requested);
        self::assertStringStartsWith('https://gitlab.jonathan-martz.de/api/v4/projects/', $requested);
    }

    public function testGitLabReadsMaintainerAttachedArchives(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            [
                'tag_name' => 'v2.1.0',
                'commit' => ['id' => 'deadbeef'],
                'assets' => [
                    'links' => [
                        ['name' => 'Plugin.zip', 'direct_asset_url' => 'https://gitlab.com/x/y/-/releases/v2.1.0/downloads/Plugin.zip'],
                    ],
                    // Source archives are what Packagist already gives us, so they
                    // must not be mistaken for a built artifact.
                    'sources' => [
                        ['format' => 'zip', 'url' => 'https://gitlab.com/x/y/-/archive/v2.1.0/y-v2.1.0.zip'],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR)));

        $assets = (new GitLabReleaseAssets($client, new AssetNaming(), new NullLogger()))
            ->forExtension($this->extension('https://gitlab.com/x/y'));

        self::assertNotNull($assets);
        self::assertArrayHasKey('v2.1.0', $assets);
        self::assertSame(DistSource::ReleaseAsset, $assets['v2.1.0']->source);
        self::assertStringContainsString('downloads/Plugin.zip', $assets['v2.1.0']->url);
        self::assertSame('deadbeef', $assets['v2.1.0']->commitSha);
    }

    /**
     * GitLab's auto-generated source archives live under `assets.sources`. Treating
     * one as a maintainer artifact would promise it is installable when it is only
     * source, the exact mistake the release/zipball distinction exists to prevent.
     */
    public function testGitLabIgnoresGeneratedSourceArchives(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            [
                'tag_name' => 'v1.0.0',
                'assets' => [
                    'links' => [],
                    'sources' => [['format' => 'zip', 'url' => 'https://gitlab.com/x/y/-/archive/v1.0.0/y.zip']],
                ],
            ],
        ], \JSON_THROW_ON_ERROR)));

        $assets = (new GitLabReleaseAssets($client, new AssetNaming(), new NullLogger()))
            ->forExtension($this->extension('https://gitlab.com/x/y'));

        self::assertSame([], $assets);
    }

    /**
     * A self-hosted forge being down, slow or behind a login is routine, so it must
     * never abort a crawl of 422 packages, but it must report *failure*, not "no
     * archives".
     *
     * The distinction is not academic. A single run of 66 API timeouts previously
     * overwrote 378 maintainer release archives with source zipballs, because an
     * empty result was indistinguishable from a failed lookup and the resolver
     * dutifully wrote the weaker answer over the stronger one.
     */
    public function testAFailedLookupIsReportedAsFailureNotAsEmpty(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('connection timed out');
        });

        $assets = (new GitLabReleaseAssets($client, new AssetNaming(), new NullLogger()))
            ->forExtension($this->extension('https://gitlab.example.invalid/x/y'));

        self::assertNull($assets, 'a transport failure must not look like "no archives"');
    }

    /**
     * A 404 genuinely means the project has no releases endpoint we can read, so
     * that is an empty result rather than a failure, there is nothing to protect.
     */
    public function testAMissingProjectIsAnEmptyResultNotAFailure(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 404]));

        $assets = (new GitLabReleaseAssets($client, new AssetNaming(), new NullLogger()))
            ->forExtension($this->extension('https://gitlab.com/x/missing'));

        self::assertSame([], $assets);
    }

    /**
     * A 500 from the forge is a failure, not an answer.
     */
    public function testAServerErrorIsAFailure(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 502]));

        $assets = (new GitLabReleaseAssets($client, new AssetNaming(), new NullLogger()))
            ->forExtension($this->extension('https://gitlab.com/x/y'));

        self::assertNull($assets);
    }

    public function testGiteaReadsReleaseAttachments(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            [
                'tag_name' => 'v3.0.0',
                'draft' => false,
                'assets' => [
                    ['name' => 'Plugin.zip', 'browser_download_url' => 'https://codeberg.org/x/y/releases/download/v3.0.0/Plugin.zip', 'size' => 4096],
                ],
            ],
        ], \JSON_THROW_ON_ERROR)));

        $assets = (new GiteaReleaseAssets($client, new AssetNaming(), new NullLogger()))
            ->forExtension($this->extension('https://codeberg.org/x/y'));

        self::assertNotNull($assets);
        self::assertArrayHasKey('v3.0.0', $assets);
        self::assertSame(4096, $assets['v3.0.0']->sizeBytes);
    }

    /**
     * Release pages carry checksums and signatures beside the archive. Offering a
     * merchant `Plugin.zip.sha256` because it sorted first would be worse than
     * offering nothing at all.
     */
    #[DataProvider('assetNameProvider')]
    public function testOnlyRealPluginArchivesAreAccepted(string $name, bool $expected): void
    {
        self::assertSame($expected, (new AssetNaming())->isPluginArchive($name));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function assetNameProvider(): iterable
    {
        yield 'plugin archive' => ['FroshTools.zip', true];
        yield 'versioned archive' => ['MyPlugin-2.1.0.zip', true];
        yield 'uppercase extension' => ['Plugin.ZIP', true];
        yield 'sha256 companion' => ['Plugin.zip.sha256.zip', false];
        yield 'signature' => ['Plugin.zip.asc.zip', false];
        yield 'tarball' => ['Plugin.tar.gz', false];
        yield 'changelog' => ['CHANGELOG.md', false];
        yield 'generated source archive' => ['source-code.zip', false];
        yield 'generated archive alias' => ['Source_Code.zip', false];
    }

    private function extension(string $repositoryUrl): Extension
    {
        $extension = new Extension(new Vendor('acme', 'acme'), 'acme/plugin', 'acme-plugin', 'Plugin');
        $extension->setRepositoryUrl($repositoryUrl);

        return $extension;
    }
}
