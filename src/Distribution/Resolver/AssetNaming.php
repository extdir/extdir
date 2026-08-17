<?php

declare(strict_types=1);

namespace App\Distribution\Resolver;

/**
 * Decides whether a release attachment is the plugin archive.
 *
 * Shared by every forge, because they all have the same problem: a release page
 * carries the plugin ZIP alongside checksums, detached signatures, changelogs and
 * whatever else the maintainer's CI uploaded. Handing a merchant `Plugin.zip.sha256`
 * because it happened to sort first is worse than offering them nothing.
 */
final class AssetNaming
{
    /**
     * Suffixes that describe an archive rather than being one. These end in `.zip`
     * only because they are named after the file they accompany.
     */
    private const COMPANION_PATTERN = '/\.(sha1|sha256|sha512|md5|asc|sig|sbom|json|txt)\.zip$/i';

    /**
     * Names that indicate a forge-generated source archive rather than a built
     * plugin. Those are already covered by the Packagist dist URL, and treating one
     * as a maintainer archive would wrongly promise it is installable.
     */
    private const SOURCE_ARCHIVE_PATTERN = '/^(source[-_]?code|archive|repository)\b/i';

    public function isPluginArchive(string $name): bool
    {
        if (!str_ends_with(strtolower($name), '.zip')) {
            return false;
        }

        if (1 === preg_match(self::COMPANION_PATTERN, $name)) {
            return false;
        }

        return 1 !== preg_match(self::SOURCE_ARCHIVE_PATTERN, $name);
    }
}
