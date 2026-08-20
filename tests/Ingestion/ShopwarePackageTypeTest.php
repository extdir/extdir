<?php

declare(strict_types=1);

namespace App\Tests\Ingestion;

use App\Ingestion\ShopwarePackageType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one rule that decides what may enter the catalogue.
 *
 * Every discovery channel narrows to this, so the near-miss types matter more than the
 * obvious ones: `shopware-plugin` is the Shopware 5 type and `shopware-platform-theme`
 * is a theme, and both would pass anything looser than an exact comparison.
 */
final class ShopwarePackageTypeTest extends TestCase
{
    #[DataProvider('types')]
    public function testOnlyTheShopware6PluginTypeIsAccepted(mixed $type, bool $expected): void
    {
        self::assertSame($expected, ShopwarePackageType::matches(['type' => $type]));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function types(): iterable
    {
        yield 'shopware 6 plugin' => ['shopware-platform-plugin', true];
        yield 'shopware 5 plugin' => ['shopware-plugin', false];
        yield 'shopware 6 theme' => ['shopware-platform-theme', false];
        yield 'plain library' => ['library', false];
        yield 'symfony bundle' => ['symfony-bundle', false];
        yield 'prefix only' => ['shopware', false];
        yield 'longer string' => ['shopware-platform-plugin-extra', false];
        yield 'wrong case' => ['Shopware-Platform-Plugin', false];
        yield 'null' => [null, false];
        yield 'not a string' => [['shopware-platform-plugin'], false];
    }

    public function testAComposerFileWithNoTypeIsRejected(): void
    {
        // composer.json defaults an absent type to "library", so silence is a no.
        self::assertFalse(ShopwarePackageType::matches(['name' => 'acme/widget']));
        self::assertFalse(ShopwarePackageType::matches([]));
    }
}
