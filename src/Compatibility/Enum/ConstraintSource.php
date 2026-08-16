<?php

declare(strict_types=1);

namespace App\Compatibility\Enum;

/**
 * Which composer requirement a compatibility constraint was read from.
 *
 * Extensions do not agree on how to declare Shopware compatibility. Modern plugins
 * require `shopware/core`; Shopware 6.1-era plugins require `shopware/platform`
 * (the old monorepo package); theme and admin-only plugins sometimes declare only
 * `shopware/storefront` or `shopware/administration`. Reading just `shopware/core`
 * silently drops a meaningful slice of the ecosystem into the "unknown" bucket, so
 * all four are parsed and the source of the winning constraint is recorded.
 */
enum ConstraintSource: string
{
    case Core = 'shopware/core';
    case Platform = 'shopware/platform';
    case Storefront = 'shopware/storefront';
    case Administration = 'shopware/administration';
    case None = 'none';

    /**
     * Package names in preference order. `shopware/core` wins when several are
     * present, because it is the one package every Shopware 6 installation has.
     *
     * @return list<self>
     */
    public static function preferenceOrder(): array
    {
        return [self::Core, self::Platform, self::Storefront, self::Administration];
    }

    public function packageName(): string
    {
        return $this->value;
    }
}
