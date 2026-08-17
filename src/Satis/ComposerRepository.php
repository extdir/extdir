<?php

declare(strict_types=1);

namespace App\Satis;

use App\Catalog\Entity\Extension;
use App\Catalog\Enum\IndexStatus;
use App\Catalog\Repository\ExtensionRepository;

/**
 * Generates Composer v2 repository metadata straight from the database.
 *
 * Upstream Satis is deliberately not used. Running it would mean executing
 * Composer over untrusted third-party package metadata on the host that holds the
 * database and the credentials, which is the thing docs/brief.md §4.2 exists to
 * prevent. The output format is a documented JSON contract, and we already store
 * every release's full composer.json — so generating it is a serialisation problem,
 * not a reason to run someone else's resolver.
 *
 * What this repository is *for* is worth stating, because it would be pointless
 * otherwise: 62 of the indexed extensions are published to GitHub and never
 * submitted to Packagist. A merchant cannot `composer require` those today at all.
 * Packages that are already on Packagist are excluded — mirroring them would add a
 * slower, staler copy of something that already works, and would put us in the
 * dependency path of installs that do not need us there.
 */
final class ComposerRepository
{
    /**
     * Packages Composer may be told about at all.
     *
     * The licence gate applies here in its strongest form. Publishing a package in
     * a Composer repository is telling a machine to download and install it — so
     * anything without a detected open-source licence is excluded outright, not
     * merely badged. §4.1 draws the line at redistribution, and pointing an
     * automated installer at code is squarely on the far side of it.
     */
    public function __construct(
        private readonly ExtensionRepository $extensions,
    ) {
    }

    /**
     * The root `packages.json`.
     *
     * `metadata-url` makes this a lazy v2 repository: Composer fetches per-package
     * documents on demand rather than downloading the whole index, which is what
     * keeps it cheap to serve from shared hosting.
     *
     * @return array<string, mixed>
     */
    public function root(string $metadataUrlTemplate): array
    {
        return [
            'packages' => new \stdClass(),
            'metadata-url' => $metadataUrlTemplate,
            // Declaring the exact set lets Composer skip requests for packages we
            // do not have, instead of probing for every requirement in a project.
            'available-packages' => $this->publishablePackageNames(),
        ];
    }

    /**
     * Per-package metadata document.
     *
     * Served unminified. The delta encoding Packagist uses saves bandwidth at the
     * cost of correctness risk — reading it wrong is the bug that would have
     * emptied our own compatibility matrix — and at this size the saving does not
     * justify handing every consumer the same trap.
     *
     * @return array<string, mixed>|null
     */
    public function package(string $packageName): ?array
    {
        $extension = $this->extensions->findOneByPackageName($packageName);

        if (null === $extension || !$this->isPublishable($extension)) {
            return null;
        }

        $versions = [];

        foreach ($extension->getReleases() as $release) {
            if (!$release->isStable()) {
                continue;
            }

            $composerJson = $release->getComposerJson();

            if ([] === $composerJson) {
                continue;
            }

            $distUrl = $release->getDistUrl();

            if (null === $distUrl) {
                // Without somewhere to download from, an entry would resolve and
                // then fail at install time. Omitting it fails earlier and clearer.
                continue;
            }

            // The stored manifest is the maintainer's own, so requirements,
            // autoload rules and extra all pass through untouched. Only dist is
            // replaced, with whatever the resolver decided is the best archive.
            $composerJson['dist'] = [
                'type' => 'zip',
                'url' => $distUrl,
                'reference' => $release->getSourceReference() ?? '',
            ];

            $versions[] = $composerJson;
        }

        if ([] === $versions) {
            return null;
        }

        return ['packages' => [$packageName => $versions]];
    }

    /**
     * @return list<string>
     */
    public function publishablePackageNames(): array
    {
        $names = [];

        foreach ($this->extensions->findBy([]) as $extension) {
            if ($this->isPublishable($extension)) {
                $names[] = $extension->getPackageName();
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Three conditions, each of which is a rule from the brief rather than a
     * preference.
     */
    private function isPublishable(Extension $extension): bool
    {
        // §4.1 — no detected licence, no distribution. Strictest application:
        // excluded entirely rather than listed with a warning, because a Composer
        // client does not read warnings.
        if (!$extension->getLicenseStatus()->isRedistributable()) {
            return false;
        }

        // A takedown must reach every surface, including the machine-readable ones.
        if (IndexStatus::Delisted === $extension->getIndexStatus()) {
            return false;
        }

        // Already installable from Packagist — mirroring adds a staler copy and
        // inserts us into an install path that works fine without us.
        return !$this->isOnPackagist($extension);
    }

    /**
     * Whether Packagist already serves this package.
     *
     * Inferred from how it entered the index: Packagist discovery uses the
     * `shopware-platform-plugin` type filter, so anything that came from there is
     * by definition on Packagist, and anything found only by GitHub topic is not.
     * Recorded at ingest rather than probed, so this stays a local decision.
     */
    private function isOnPackagist(Extension $extension): bool
    {
        return $extension->isOnPackagist();
    }
}
