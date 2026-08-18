<?php

declare(strict_types=1);

namespace App\Ingestion;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\ExtensionRelease;
use App\Catalog\Entity\Vendor;
use App\Catalog\Enum\DiscoverySource;
use App\Catalog\Enum\IndexStatus;
use App\Catalog\Repository\ExtensionReleaseRepository;
use App\Catalog\Repository\ExtensionRepository;
use App\Catalog\Repository\VendorRepository;
use App\Catalog\SearchTextBuilder;
use App\Compatibility\CompatibilityResolver;
use App\Compatibility\ConstraintParser;
use App\Compatibility\Entity\CompatibilityClaim;
use App\Compatibility\Repository\CompatibilityClaimRepository;
use App\Compatibility\Repository\ShopwareVersionRepository;
use App\License\Entity\LicenseFinding;
use App\License\Enum\FindingSource;
use App\License\Enum\LicenseStatus;
use App\License\SpdxAllowlist;
use App\Metadata\ComposerMetadataExtractor;
use Composer\Semver\VersionParser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns one package's Packagist metadata into persisted catalog rows.
 *
 * Idempotent by design: ingestion runs repeatedly over the same packages forever,
 * so every step is an upsert and re-running must converge rather than accumulate.
 */
final class PackageIngestor
{
    /**
     * The vendor operated by the person who runs extdir.
     *
     * Used for one thing only — setting the disclosure flag that renders a badge
     * (the conflict-of-interest rule). It is deliberately absent from ranking, verification and
     * ordering, and there is a test asserting that stays true. A directory whose
     * maintainer also publishes extensions has exactly one way to remain credible,
     * and this constant is the boundary of the special-casing.
     */
    private const MAINTAINER_VENDOR = 'runio';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VendorRepository $vendors,
        private readonly ExtensionRepository $extensions,
        private readonly ExtensionReleaseRepository $releases,
        private readonly CompatibilityClaimRepository $claims,
        private readonly ShopwareVersionRepository $shopwareVersions,
        private readonly ComposerMetadataExtractor $metadataExtractor,
        private readonly ConstraintParser $constraintParser,
        private readonly CompatibilityResolver $compatibilityResolver,
        private readonly SpdxAllowlist $spdx,
        private readonly SearchTextBuilder $searchText,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $versions delta-expanded p2 entries
     */
    public function ingest(
        string $packageName,
        array $versions,
        DiscoverySource $discoveredVia = DiscoverySource::Packagist,
    ): ?Extension {
        if ([] === $versions) {
            return null;
        }

        $sorted = $this->sortNewestFirst($versions);
        $latestStable = $this->pickLatestStable($sorted);

        $extension = $this->upsertExtension($packageName, $latestStable ?? $sorted[0]);
        $extension->setDiscoverySource($discoveredVia);

        $shopwareVersions = $this->shopwareVersions->findOrdered();
        $existing = $this->releases->findKeyedByVersion($extension);

        foreach ($sorted as $entry) {
            $this->upsertRelease($extension, $entry, $existing, $shopwareVersions);
        }

        $extension->markCrawled();
        $this->em->flush();

        return $extension;
    }

    /**
     * @param array<string, mixed> $latest
     */
    private function upsertExtension(string $packageName, array $latest): Extension
    {
        $extension = $this->extensions->findOneByPackageName($packageName);
        $metadata = $this->metadataExtractor->extract($latest, $packageName);

        if (null === $extension) {
            $extension = new Extension(
                $this->upsertVendor($packageName),
                $packageName,
                $this->uniqueSlug($packageName),
                $metadata->label,
            );
            $this->em->persist($extension);
        } else {
            $extension->setLabel($metadata->label);
        }

        $extension->setDescription($metadata->description);
        $extension->setLabels($metadata->labels);
        $extension->setDescriptions($metadata->descriptions);
        $extension->setKeywords($metadata->keywords);
        $extension->setPluginClass($metadata->pluginClass);
        $extension->setIconPath($metadata->pluginIcon);
        $extension->setManufacturerLink($metadata->manufacturerLink);
        $extension->setSupportLink($metadata->supportLink);
        $extension->setRepositoryUrl($metadata->repositoryUrl ?? $metadata->homepage);

        $this->applyDeclaredLicense($extension, $metadata->license);

        // Built last, so it reflects every field set above.
        $extension->setSearchText($this->searchText->build($extension));

        return $extension;
    }

    /**
     * Stage 1 of the license gate: read what composer.json declares.
     *
     * This is indicative only and never authorises a build — that requires a real
     * detector run over the actual files inside CI (the licence gate). What it does decide is
     * whether the extension is fully listed or shown index-only with the "License
     * unknown — not redistributable" badge, and the default is the cautious one.
     *
     * @param string|list<string>|null $declared
     */
    private function applyDeclaredLicense(Extension $extension, string|array|null $declared): void
    {
        $status = $this->spdx->classifyDeclared($declared);
        $spdx = $this->spdx->firstAcceptedIdentifier($declared);

        $changed = $extension->getLicenseStatus() !== $status
            || $extension->getLicenseSpdx() !== $spdx;

        $extension->applyLicense($spdx, $status);

        // A takedown decision outranks anything the crawler infers; a delisted
        // extension must not quietly return to the index on the next crawl.
        if (IndexStatus::Delisted !== $extension->getIndexStatus()) {
            $extension->setIndexStatus(
                LicenseStatus::Unknown === $status || LicenseStatus::Rejected === $status
                    ? IndexStatus::IndexOnly
                    : IndexStatus::Listed,
            );
        }

        // Append evidence only when the conclusion moved, so the trail records
        // decisions rather than one row per crawl per package forever.
        if ($changed) {
            $finding = new LicenseFinding($extension, $status, FindingSource::ComposerJson, $spdx);
            $finding->setRawValue(\is_array($declared) ? implode(', ', $declared) : $declared);
            $this->em->persist($finding);
        }
    }

    private function upsertVendor(string $packageName): Vendor
    {
        $name = explode('/', $packageName)[0];

        $vendor = $this->vendors->findOneByName($name);
        if (null === $vendor) {
            $vendor = new Vendor($name, $this->slugify($name));
            $vendor->setMaintainerOperated(self::MAINTAINER_VENDOR === $name);
            $this->em->persist($vendor);
        }

        return $vendor;
    }

    /**
     * @param array<string, mixed>                            $entry
     * @param array<string, ExtensionRelease>                 $existing
     * @param list<\App\Compatibility\Entity\ShopwareVersion> $shopwareVersions
     */
    private function upsertRelease(
        Extension $extension,
        array $entry,
        array $existing,
        array $shopwareVersions,
    ): void {
        $normalized = $entry['version_normalized'] ?? null;
        $raw = $entry['version'] ?? null;

        if (!\is_string($normalized) || !\is_string($raw)) {
            return;
        }

        $release = $existing[$normalized] ?? null;
        if (null === $release) {
            $release = new ExtensionRelease($extension, $normalized, $raw);
            $this->em->persist($release);
        }

        $release->setComposerJson($entry);
        $release->setStable('stable' === VersionParser::parseStability($raw));
        $release->setReleasedAt($this->releaseDate($entry));
        $release->setSourceReference($this->sourceReference($entry));

        $dist = $entry['dist'] ?? null;
        if (\is_array($dist) && \is_string($dist['url'] ?? null)) {
            // Packagist's own dist URL, which for GitHub-hosted packages is the tag
            // zipball. That is already step 2 of the link-first resolution order, so
            // no artifact needs building unless the P3 resolver finds it unusable.
            $release->setDist($dist['url'], 'packagist');
        }

        $parsed = $this->constraintParser->parse($entry);
        $release->applyConstraint($parsed->raw, $parsed->source, $parsed->tier);

        if ($release->isStable() && (null === $extension->getLastReleaseAt()
            || (null !== $release->getReleasedAt() && $release->getReleasedAt() > $extension->getLastReleaseAt()))) {
            $extension->setLastReleaseAt($release->getReleasedAt());
        }

        $this->rebuildClaims($release, $parsed, $shopwareVersions);
    }

    /**
     * @param list<\App\Compatibility\Entity\ShopwareVersion> $shopwareVersions
     */
    private function rebuildClaims(
        ExtensionRelease $release,
        \App\Compatibility\ParsedConstraint $parsed,
        array $shopwareVersions,
    ): void {
        // Replace rather than patch: a re-parse can flip a claim from satisfied to
        // unsatisfied, and merging would leave the stale row behind.
        if (null !== $release->getId()) {
            $this->claims->deleteForRelease($release);
        }

        if (!$parsed->isTestable()) {
            return;
        }

        $matrix = $this->compatibilityResolver->resolve($parsed, $shopwareVersions);

        foreach ($shopwareVersions as $version) {
            if ($matrix[$version->getMajorMinor()] ?? false) {
                $this->em->persist(new CompatibilityClaim($release, $version, true));
            }
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function releaseDate(array $entry): ?\DateTimeImmutable
    {
        $time = $entry['time'] ?? null;

        if (!\is_string($time)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($time);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function sourceReference(array $entry): ?string
    {
        $source = $entry['source'] ?? null;
        $reference = \is_array($source) ? ($source['reference'] ?? null) : null;

        return \is_string($reference) ? $reference : null;
    }

    /**
     * @param list<array<string, mixed>> $versions
     *
     * @return list<array<string, mixed>>
     */
    private function sortNewestFirst(array $versions): array
    {
        usort($versions, function (array $a, array $b): int {
            $left = \is_string($a['version_normalized'] ?? null) ? $a['version_normalized'] : '0';
            $right = \is_string($b['version_normalized'] ?? null) ? $b['version_normalized'] : '0';

            return version_compare($right, $left);
        });

        return $versions;
    }

    /**
     * @param list<array<string, mixed>> $sorted
     *
     * @return array<string, mixed>|null
     */
    private function pickLatestStable(array $sorted): ?array
    {
        foreach ($sorted as $entry) {
            $raw = $entry['version'] ?? null;
            if (\is_string($raw) && 'stable' === VersionParser::parseStability($raw)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Composer names permit up to two consecutive hyphens in the package segment,
     * so no separator choice is collision-proof. Rather than pretend otherwise, the
     * obvious slug is tried first and a counter appended if it is taken.
     */
    private function uniqueSlug(string $packageName): string
    {
        $base = $this->slugify(str_replace('/', '-', $packageName));
        $slug = $base;

        for ($i = 2; null !== $this->extensions->findOneBySlug($slug); ++$i) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    private function slugify(string $value): string
    {
        $slug = strtolower($value);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }
}
