<?php

declare(strict_types=1);

namespace App\Tests\Ingestion;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Catalog\Enum\DiscoverySource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How an extension records the channel that found it.
 *
 * This is not bookkeeping. isOnPackagist() decides whether the extension is published
 * in our Composer repository, so an extension wrongly marked as being on Packagist is
 * quietly withheld from the one place a merchant could install it from.
 *
 * The regression: a new Extension holds the column default, Packagist, before anything
 * sets it. setDiscoverySource() refuses to move away from Packagist — correct for a
 * later crawl, wrong at creation — so every GitHub-discovered extension was stored
 * claiming a Packagist entry it did not have. 108 of them, before it was noticed.
 */
final class DiscoverySourceTest extends TestCase
{
    public function testANewExtensionStartsOutClaimingPackagist(): void
    {
        // Documents the trap rather than endorsing it: this default is why creation
        // has to force the source instead of setting it.
        self::assertSame(DiscoverySource::Packagist, $this->extension()->getDiscoverySource());
    }

    #[DataProvider('nonPackagistSources')]
    public function testSettingIsRefusedOnAFreshEntityButForcingWorks(DiscoverySource $source): void
    {
        $extension = $this->extension();
        $extension->setDiscoverySource($source);

        self::assertSame(
            DiscoverySource::Packagist,
            $extension->getDiscoverySource(),
            'setDiscoverySource cannot express "found on GitHub" on a fresh entity.'
        );

        $extension->forceDiscoverySource($source);

        self::assertSame($source, $extension->getDiscoverySource());
        self::assertFalse($extension->isOnPackagist());
    }

    /**
     * A package that later appears on Packagist upgrades, because Packagist becomes
     * authoritative the moment it serves the package.
     */
    #[DataProvider('nonPackagistSources')]
    public function testAppearingOnPackagistLaterUpgrades(DiscoverySource $source): void
    {
        $extension = $this->extension();
        $extension->forceDiscoverySource($source);

        $extension->setDiscoverySource(DiscoverySource::Packagist);

        self::assertSame(DiscoverySource::Packagist, $extension->getDiscoverySource());
        self::assertTrue($extension->isOnPackagist());
    }

    /**
     * @return iterable<string, array{DiscoverySource}>
     */
    public static function nonPackagistSources(): iterable
    {
        yield 'topic' => [DiscoverySource::GitHubTopic];
        yield 'search' => [DiscoverySource::GitHubSearch];
        yield 'submitted' => [DiscoverySource::Submitted];
    }

    /**
     * Every case must answer both questions, or a new one throws at render time.
     */
    #[DataProvider('everySource')]
    public function testEverySourceHasALabelAndAPackagistAnswer(DiscoverySource $source): void
    {
        self::assertNotSame('', $source->label());
        self::assertSame(DiscoverySource::Packagist === $source, $source->isOnPackagist());
    }

    /**
     * @return iterable<string, array{DiscoverySource}>
     */
    public static function everySource(): iterable
    {
        foreach (DiscoverySource::cases() as $case) {
            yield $case->value => [$case];
        }
    }

    private function extension(): Extension
    {
        return new Extension(new Vendor('acme', 'acme'), 'acme/widget', 'acme-widget', 'Acme Widget');
    }
}
