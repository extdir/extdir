<?php

declare(strict_types=1);

namespace App\Tests\Satis;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\ExtensionRelease;
use App\Catalog\Entity\Vendor;
use App\Catalog\Enum\DiscoverySource;
use App\Catalog\Enum\IndexStatus;
use App\Catalog\Repository\ExtensionRepository;
use App\License\Enum\LicenseStatus;
use App\Satis\ComposerRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComposerRepository::class)]
#[CoversClass(DiscoverySource::class)]
final class ComposerRepositoryTest extends TestCase
{
    /**
     * The strictest application of §4.1 in the codebase.
     *
     * Everywhere else an unlicensed extension is listed with a warning badge.
     * Here it must vanish, because publishing a package in a Composer repository
     * is instructing a machine to download and install it — and a Composer client
     * does not read warnings.
     */
    #[DataProvider('unpublishableProvider')]
    public function testUnpublishableExtensionsAreNotServed(
        LicenseStatus $licence,
        IndexStatus $indexStatus,
        DiscoverySource $source,
        string $because,
    ): void {
        $extension = $this->extension($licence, $indexStatus, $source);
        $repository = new ComposerRepository($this->repositoryReturning([$extension]));

        self::assertNull($repository->package('acme/plugin'), $because);
        self::assertNotContains('acme/plugin', $repository->publishablePackageNames(), $because);
    }

    /**
     * @return iterable<string, array{LicenseStatus, IndexStatus, DiscoverySource, string}>
     */
    public static function unpublishableProvider(): iterable
    {
        yield 'no licence detected' => [
            LicenseStatus::Unknown, IndexStatus::IndexOnly, DiscoverySource::GitHubTopic,
            'an unlicensed package must never be offered to an installer',
        ];
        yield 'licence not accepted' => [
            LicenseStatus::Rejected, IndexStatus::IndexOnly, DiscoverySource::GitHubTopic,
            'a package declaring proprietary must not be redistributed',
        ];
        yield 'delisted after a takedown' => [
            LicenseStatus::Permissive, IndexStatus::Delisted, DiscoverySource::GitHubTopic,
            'a takedown has to reach the machine-readable surfaces too',
        ];
        yield 'already on Packagist' => [
            LicenseStatus::Permissive, IndexStatus::Listed, DiscoverySource::Packagist,
            'mirroring Packagist adds a staler copy and inserts us into installs that work without us',
        ];
    }

    public function testAnExtensionOnlyOnGitHubIsPublished(): void
    {
        $extension = $this->extension(
            LicenseStatus::Permissive,
            IndexStatus::Listed,
            DiscoverySource::GitHubTopic,
        );
        $repository = new ComposerRepository($this->repositoryReturning([$extension]));

        $metadata = $repository->package('acme/plugin');

        self::assertNotNull($metadata);
        self::assertArrayHasKey('acme/plugin', $metadata['packages']);
        self::assertCount(1, $metadata['packages']['acme/plugin']);
        self::assertContains('acme/plugin', $repository->publishablePackageNames());
    }

    /**
     * The maintainer's own manifest passes through untouched apart from dist, so
     * requirements and autoload rules are exactly what they published. Only the
     * download location is ours to decide.
     */
    public function testTheManifestPassesThroughWithOnlyDistReplaced(): void
    {
        $extension = $this->extension(
            LicenseStatus::Permissive,
            IndexStatus::Listed,
            DiscoverySource::GitHubTopic,
        );
        $repository = new ComposerRepository($this->repositoryReturning([$extension]));

        $metadata = $repository->package('acme/plugin');

        self::assertNotNull($metadata);
        $version = $metadata['packages']['acme/plugin'][0];

        self::assertSame(['shopware/core' => '~6.6.0'], $version['require']);
        self::assertSame('shopware-platform-plugin', $version['type']);
        self::assertSame('https://example.test/plugin-2.0.0.zip', $version['dist']['url']);
        self::assertSame('abc123', $version['dist']['reference']);
    }

    /**
     * A version with nowhere to download from would resolve and then fail at
     * install time. Omitting it fails earlier and says something truthful.
     */
    public function testVersionsWithoutADownloadAreOmitted(): void
    {
        $extension = $this->extension(
            LicenseStatus::Permissive,
            IndexStatus::Listed,
            DiscoverySource::GitHubTopic,
        );

        foreach ($extension->getReleases() as $release) {
            $release->setDist(null, null);
        }

        $repository = new ComposerRepository($this->repositoryReturning([$extension]));

        self::assertNull($repository->package('acme/plugin'));
    }

    public function testTheRootDocumentAdvertisesLazyMetadata(): void
    {
        $repository = new ComposerRepository($this->repositoryReturning([]));

        $root = $repository->root('/repo/p2/%package%.json');

        self::assertSame('/repo/p2/%package%.json', $root['metadata-url']);
        self::assertSame([], $root['available-packages']);
    }

    private function extension(
        LicenseStatus $licence,
        IndexStatus $indexStatus,
        DiscoverySource $source,
    ): Extension {
        $extension = new Extension(new Vendor('acme', 'acme'), 'acme/plugin', 'acme-plugin', 'Plugin');
        $extension->applyLicense('MIT', $licence);
        $extension->setIndexStatus($indexStatus);
        $extension->forceDiscoverySource($source);

        $release = new ExtensionRelease($extension, '2.0.0.0', 'v2.0.0');
        $release->setComposerJson([
            'name' => 'acme/plugin',
            'version' => 'v2.0.0',
            'type' => 'shopware-platform-plugin',
            'license' => ['MIT'],
            'require' => ['shopware/core' => '~6.6.0'],
        ]);
        $release->setDist('https://example.test/plugin-2.0.0.zip', 'release_asset');
        $release->setSourceReference('abc123');

        return $extension;
    }

    /**
     * @param list<Extension> $extensions
     */
    private function repositoryReturning(array $extensions): ExtensionRepository
    {
        $repository = $this->createStub(ExtensionRepository::class);
        $repository->method('findBy')->willReturn($extensions);
        $repository->method('findOneByPackageName')->willReturn($extensions[0] ?? null);

        return $repository;
    }
}
