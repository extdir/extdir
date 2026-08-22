<?php

declare(strict_types=1);

namespace App\Ingestion;

/**
 * The single definition of what counts as a Shopware 6 extension.
 *
 * Every discovery channel narrows to this same test, which is what lets them differ
 * wildly in noise without differing in what they admit. Packagist filters on the type
 * server-side; the GitHub channels read composer.json and check it here.
 *
 * It lives in its own class because it was previously a private method on the
 * assembler, and a second channel needed the same rule. Two copies of a string like
 * this do not fail loudly, they drift, and the cheaper copy quietly starts admitting
 * themes, libraries and Shopware 5 plugins that the other one rejects.
 *
 * Deliberately exact rather than a prefix match. `shopware-platform-plugin` is the
 * Shopware 6 plugin type; `shopware-plugin` is the Shopware 5 one, and
 * `shopware-platform-theme` is a theme. Neither belongs in this catalogue, and both
 * would pass a `str_starts_with('shopware')`.
 */
final class ShopwarePackageType
{
    public const string PLUGIN = 'shopware-platform-plugin';

    /**
     * @param array<string, mixed> $composerJson
     */
    public static function matches(array $composerJson): bool
    {
        return self::PLUGIN === ($composerJson['type'] ?? null);
    }
}
