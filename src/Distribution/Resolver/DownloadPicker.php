<?php

declare(strict_types=1);

namespace App\Distribution\Resolver;

use App\Catalog\Entity\ExtensionRelease;
use App\Distribution\Enum\DistSource;

/**
 * The link-first resolution decision, with no collaborators.
 *
 * Kept separate from DownloadResolver, which owns fetching and persistence. This
 * is the part where being wrong matters — pick the zipball over a maintainer's
 * archive and every download becomes source-only — so it is a pure function of its
 * inputs and directly testable, rather than reachable only through a GitHub client
 * and an entity manager.
 */
final class DownloadPicker
{
    /**
     * @param array<string, ResolvedDownload> $assetsByTag maintainer archives, keyed by git tag
     */
    public function pick(ExtensionRelease $release, array $assetsByTag): ?ResolvedDownload
    {
        // Step 1: an archive the maintainer attached to their release. Free, and
        // the only form that reliably installs.
        foreach ($this->tagCandidates($release) as $tag) {
            if (isset($assetsByTag[$tag])) {
                return $assetsByTag[$tag];
            }
        }

        // Step 2: the tag zipball Packagist already points Composer at. Free, but
        // source-only, so it is labelled rather than presented as installable.
        $distUrl = $release->getDistUrl();
        if (null !== $distUrl && '' !== $distUrl) {
            return new ResolvedDownload(
                url: $distUrl,
                source: DistSource::TagZipball,
                commitSha: $release->getSourceReference(),
            );
        }

        // Step 3 — building — is not a URL that can be returned synchronously. It
        // is a queued request with a licence gate in front of it.
        return null;
    }

    /**
     * Tag spellings to try.
     *
     * Maintainers tag inconsistently: some as `v3.12.0`, some as `3.12.0`, and
     * Packagist records whichever they used. Matching only one spelling would
     * quietly downgrade a large share of releases to source archives.
     *
     * @return list<string>
     */
    public function tagCandidates(ExtensionRelease $release): array
    {
        $raw = $release->getVersionRaw();
        $stripped = ltrim($raw, 'vV');

        return array_values(array_unique([$raw, $stripped, 'v'.$stripped]));
    }
}
