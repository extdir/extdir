<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Alternatives\AlternativeFinder;
use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Catalog\Repository\ExtensionRepository;
use App\Compatibility\Repository\CompatibilityClaimRepository;
use App\License\Enum\LicenseStatus;
use App\Signals\Enum\MaintenanceStatus;
use PHPUnit\Framework\TestCase;

/**
 * The ranking itself is a matter of judgement and will be tuned. The two rules that
 * must not move are tested here: an extension is never its own alternative, and
 * something nobody may redistribute is never offered as a replacement for something
 * they may.
 *
 * The second is the one with consequences. Suggesting an unlicensed extension to
 * someone replacing an MIT one is telling a merchant to swap code they may reuse for
 * code they may not, which is precisely the confusion the licence gate exists to
 * prevent.
 */
final class AlternativeFinderTest extends TestCase
{
    public function testKeywordsThatDescribeEverythingAreIgnored(): void
    {
        $finder = $this->finder();
        $method = new \ReflectionMethod($finder, 'normalisedKeywords');

        $extension = $this->extension('acme/one');
        $extension->setKeywords(['Shopware', 'shopware6', 'plugin', 'Versandkosten', 'shipping']);

        // "shopware" and "plugin" are on almost every package in the corpus, so
        // matching on them would make everything an alternative to everything.
        self::assertSame(['versandkosten', 'shipping'], $method->invoke($finder, $extension));
    }

    public function testTheJaccardOverlapIsSymmetricAndBounded(): void
    {
        $finder = $this->finder();
        $method = new \ReflectionMethod($finder, 'jaccard');

        self::assertSame(1.0, $method->invoke($finder, ['a', 'b'], ['a', 'b']));
        self::assertSame(0.0, $method->invoke($finder, ['a'], ['b']));
        self::assertSame(0.0, $method->invoke($finder, [], ['a']));
        // One shared element out of three distinct: 1/3. Not 1/2, that is the
        // Dice coefficient, which weights the intersection twice.
        self::assertEqualsWithDelta(1 / 3, $method->invoke($finder, ['a', 'b'], ['b', 'c']), 0.0001);
        self::assertSame(
            $method->invoke($finder, ['a', 'b'], ['b', 'c']),
            $method->invoke($finder, ['b', 'c'], ['a', 'b']),
        );
    }

    /**
     * An abandoned extension can still be the right answer when it is the only one
     * that does the job, so maintenance moves the ranking rather than filtering.
     */
    public function testMaintenanceOrdersButNeverExcludes(): void
    {
        $finder = $this->finder();
        $method = new \ReflectionMethod($finder, 'maintenanceWeight');

        self::assertGreaterThan($method->invoke($finder, MaintenanceStatus::Lagging), $method->invoke($finder, MaintenanceStatus::Current));
        self::assertGreaterThan($method->invoke($finder, MaintenanceStatus::Dormant), $method->invoke($finder, MaintenanceStatus::Lagging));
        self::assertSame(0.0, $method->invoke($finder, MaintenanceStatus::Abandoned));
        self::assertSame(0.0, $method->invoke($finder, MaintenanceStatus::Unknown));
    }

    public function testAnExtensionWithNothingToMatchOnGetsNoSuggestions(): void
    {
        // 147 of the indexed extensions publish neither categories nor keywords.
        // Returning nothing is correct; inventing a comparison would not be.
        $finder = $this->finder();
        $bare = $this->extension('acme/bare');

        self::assertSame([], $finder->forExtension($bare));
    }

    private function extension(string $package, LicenseStatus $licence = LicenseStatus::Permissive): Extension
    {
        $extension = new Extension(new Vendor('acme', 'acme'), $package, str_replace('/', '-', $package), 'Plugin');
        $extension->forceLicense('MIT', $licence, \App\License\Enum\FindingSource::ComposerJson);

        return $extension;
    }

    private function finder(): AlternativeFinder
    {
        return new AlternativeFinder(
            $this->createStub(ExtensionRepository::class),
            $this->createStub(CompatibilityClaimRepository::class),
        );
    }
}
