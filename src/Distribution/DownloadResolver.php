<?php

declare(strict_types=1);

namespace App\Distribution;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\ExtensionRelease;
use App\Distribution\Entity\Artifact;
use App\Distribution\Repository\ArtifactRepository;
use App\Distribution\Resolver\ReleaseAssetIndex;
use App\Distribution\Resolver\ResolvedDownload;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Decides where each release should be downloaded from, link-first.
 *
 * docs/brief.md §4.3 in code. The order is:
 *
 *   1. A ZIP the maintainer attached to a GitHub release — costs nothing and is
 *      the only form that reliably installs, because it was packaged with
 *      shopware-cli and therefore contains built administration and storefront
 *      assets.
 *   2. The tag zipball Packagist already points Composer at — also free, but
 *      source-only, so it is offered to Composer and labelled honestly on the site
 *      rather than presented as an installable archive.
 *   3. Building it ourselves — the fallback, gated on licence, always labelled
 *      unofficial, and the only branch that consumes storage or CI minutes.
 *
 * Almost every release resolves at step 1 or 2, which is exactly why the storage
 * projection stays small.
 */
final class DownloadResolver
{
    public function __construct(
        private readonly ReleaseAssetIndex $releaseAssets,
        private readonly Resolver\DownloadPicker $picker,
        private readonly ArtifactRepository $artifacts,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Resolves every stable release of an extension.
     *
     * @return array{resolved: int, bySource: array<string, int>}
     */
    public function resolveExtension(Extension $extension): array
    {
        // One GraphQL call covers every tag of the repository, so this is fetched
        // once per extension rather than once per release.
        $assets = $extension->getLicenseStatus()->isRedistributable()
            ? $this->releaseAssets->forExtension($extension)
            : [];

        $counts = [];
        $resolved = 0;

        foreach ($extension->getReleases() as $release) {
            if (!$release->isStable()) {
                continue;
            }

            $download = $this->resolveRelease($release, $assets);

            if (null === $download) {
                continue;
            }

            $this->persist($release, $download);
            $counts[$download->source->value] = ($counts[$download->source->value] ?? 0) + 1;
            ++$resolved;
        }

        $this->em->flush();

        return ['resolved' => $resolved, 'bySource' => $counts];
    }

    /**
     * @param array<string, ResolvedDownload> $assets keyed by tag name
     */
    public function resolveRelease(ExtensionRelease $release, array $assets): ?ResolvedDownload
    {
        return $this->picker->pick($release, $assets);
    }

    private function persist(ExtensionRelease $release, ResolvedDownload $download): void
    {
        $artifact = $this->artifacts->findOneBy(['release' => $release]);

        if (null === $artifact) {
            $artifact = new Artifact($release, $download->url, $download->source->value);
            $this->em->persist($artifact);
        } else {
            $artifact->updateLink($download->url, $download->source->value);
        }

        // Keep the release's own dist pointer in step, since that is what the
        // Composer repository endpoint will serve.
        $release->setDist($download->url, $download->source->value);
    }
}
