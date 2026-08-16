<?php

declare(strict_types=1);

namespace App\Tests\Distribution;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\ExtensionRelease;
use App\Catalog\Entity\Vendor;
use App\Distribution\Enum\DistSource;
use App\Distribution\Resolver\DownloadPicker;
use App\Distribution\Resolver\ResolvedDownload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DownloadPicker::class)]
#[CoversClass(DistSource::class)]
final class DownloadResolverTest extends TestCase
{
    private DownloadPicker $picker;

    protected function setUp(): void
    {
        $this->picker = new DownloadPicker();
    }

    /**
     * The maintainer's own archive wins over the zipball even though both are
     * free. For Shopware this is a correctness question, not a preference: a
     * release ZIP was packaged with shopware-cli and carries built administration
     * and storefront assets, while a git zipball is source only and will not
     * install into a shop.
     */
    public function testAMaintainerArchiveIsPreferredOverTheZipball(): void
    {
        $release = $this->release('3.12.0');
        $release->setDist('https://api.github.com/repos/acme/plugin/zipball/3.12.0', 'packagist');

        $download = $this->picker->pick($release, [
            '3.12.0' => new ResolvedDownload('https://github.com/acme/plugin/releases/download/3.12.0/Plugin.zip', DistSource::ReleaseAsset),
        ]);

        self::assertNotNull($download);
        self::assertSame(DistSource::ReleaseAsset, $download->source);
        self::assertStringContainsString('Plugin.zip', $download->url);
    }

    /**
     * Maintainers tag inconsistently — some as `v3.12.0`, some as `3.12.0` — and
     * Packagist records whichever they used. Matching only one spelling would
     * silently downgrade half the corpus to source archives.
     */
    #[DataProvider('tagSpellingProvider')]
    public function testTagsMatchWithAndWithoutTheVPrefix(string $releaseVersion, string $assetTag): void
    {
        $download = $this->picker->pick($this->release($releaseVersion), [
            $assetTag => new ResolvedDownload('https://example.test/Plugin.zip', DistSource::ReleaseAsset),
        ]);

        self::assertNotNull($download, \sprintf('release %s should match asset tag %s', $releaseVersion, $assetTag));
        self::assertSame(DistSource::ReleaseAsset, $download->source);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tagSpellingProvider(): iterable
    {
        yield 'both plain' => ['3.12.0', '3.12.0'];
        yield 'both prefixed' => ['v3.12.0', 'v3.12.0'];
        yield 'release plain, tag prefixed' => ['3.12.0', 'v3.12.0'];
        yield 'release prefixed, tag plain' => ['v3.12.0', '3.12.0'];
    }

    public function testFallsBackToTheZipballWhenNoArchiveWasAttached(): void
    {
        $release = $this->release('1.0.0');
        $release->setDist('https://api.github.com/repos/acme/plugin/zipball/1.0.0', 'packagist');
        $release->setSourceReference('abc123');

        $download = $this->picker->pick($release, []);

        self::assertNotNull($download);
        self::assertSame(DistSource::TagZipball, $download->source);
        self::assertSame('abc123', $download->commitSha);
    }

    /**
     * A different version's archive must never be offered. Serving 3.11.0 to
     * someone who asked for 3.12.0 would be worse than serving nothing.
     */
    public function testAnArchiveForAnotherVersionIsNotUsed(): void
    {
        $release = $this->release('3.12.0');

        $download = $this->picker->pick($release, [
            '3.11.0' => new ResolvedDownload('https://example.test/old.zip', DistSource::ReleaseAsset),
        ]);

        self::assertNull($download, 'no dist url and no matching asset means nothing to offer');
    }

    /**
     * With neither an attached archive nor a zipball, resolution yields nothing
     * rather than inventing a URL — that case is what the build queue is for.
     */
    public function testNothingResolvesWhenThereIsNoArchiveAtAll(): void
    {
        self::assertNull($this->picker->pick($this->release('1.0.0'), []));
    }

    /**
     * The honesty rule behind the download column: only the maintainer's archive
     * is presented as installable.
     */
    public function testOnlyMaintainerArchivesAreMarkedInstallable(): void
    {
        self::assertTrue(DistSource::ReleaseAsset->isInstallableAsIs());
        self::assertFalse(DistSource::TagZipball->isInstallableAsIs());
        self::assertTrue(DistSource::Built->isInstallableAsIs());
    }

    /**
     * Only builds consume our storage. If this ever changes, the whole storage
     * projection in the architecture changes with it.
     */
    public function testOnlyBuiltArchivesAreHostedByUs(): void
    {
        self::assertFalse(DistSource::ReleaseAsset->isHostedByUs());
        self::assertFalse(DistSource::TagZipball->isHostedByUs());
        self::assertTrue(DistSource::Built->isHostedByUs());
    }

    private function release(string $versionRaw): ExtensionRelease
    {
        $extension = new Extension(new Vendor('acme', 'acme'), 'acme/plugin', 'acme-plugin', 'Plugin');

        return new ExtensionRelease($extension, ltrim($versionRaw, 'vV').'.0', $versionRaw);
    }
}
