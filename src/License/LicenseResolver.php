<?php

declare(strict_types=1);

namespace App\License;

use App\Catalog\Entity\Extension;
use App\Catalog\Enum\IndexStatus;
use App\License\Entity\LicenseFinding;
use App\License\Enum\FindingSource;
use App\License\Enum\LicenseStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Decides an extension's effective licence from the evidence collected about it.
 *
 * The reason this exists is a measurement rather than a theory. Of 93 indexed
 * repositories whose composer.json says `"license": "proprietary"`, GitHub's
 * licensee detector finds an actual MIT licence file in 12 and GPL-3.0 in one.
 * Those thirteen authors did license their work openly; what they did not do is
 * edit the line that `composer create-project` wrote for them, because
 * `"license": "proprietary"` is the skeleton default and nothing ever forces you
 * to change it.
 *
 * Treating the manifest as the last word therefore denied redistribution rights to
 * people who had granted them, a false negative, and the kind that quietly makes
 * the directory less useful while looking cautious.
 *
 * So the LICENSE file wins over the manifest when the two disagree. That is not a
 * relaxation of the licence gate; the licence gate names "composer.json → license, or a LICENSE
 * file" as the two accepted sources, and of the two the file is the actual grant
 * while the manifest is metadata about it. The conservative default is untouched:
 * where no licence is detected anywhere, nothing is redistributable.
 */
final class LicenseResolver
{
    public function __construct(
        private readonly SpdxAllowlist $spdx,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Records a detector result and re-derives the effective licence.
     *
     * @param string|null $detectedSpdx as reported by a real detector over the repository files
     */
    public function applyDetectorResult(Extension $extension, ?string $detectedSpdx, string $detector): void
    {
        // GitHub answers NOASSERTION when it finds a licence file it cannot
        // identify, a modified or custom licence. That is evidence of a file, not
        // evidence of a grant we can act on, so it stays unknown.
        if (null === $detectedSpdx || 'NOASSERTION' === $detectedSpdx) {
            return;
        }

        $normalised = $this->spdx->normalise($detectedSpdx);
        $status = $this->spdx->classify($detectedSpdx);

        if (null === $normalised || !$status->isRedistributable()) {
            return;
        }

        if (!$this->hasDetectorFinding($extension, $normalised)) {
            $finding = new LicenseFinding($extension, $status, FindingSource::Detector, $normalised);
            $finding
                ->withDetector($detector, 'github-api', null, null)
                ->setRawValue($detectedSpdx);

            $this->em->persist($finding);
            $extension->getLicenseFindings()->add($finding);
        }

        $this->reconcile($extension);
    }

    /**
     * Re-derives the effective licence from every finding on record.
     *
     * Deciding from the accumulated evidence rather than from whichever source
     * wrote last makes the outcome independent of crawl order, and it repairs
     * conclusions reached before a rule existed, the three packages downgraded
     * from AGPL to MIT before the copyleft guard was written correct themselves on
     * the next pass instead of needing a migration.
     */
    public function reconcile(Extension $extension): void
    {
        $copyleft = null;
        $permissive = null;

        foreach ($extension->getLicenseFindings() as $finding) {
            if (null === $finding->getSpdx()) {
                continue;
            }

            if (LicenseStatus::Copyleft === $finding->getStatus() && null === $copyleft) {
                $copyleft = $finding;
            }

            if (LicenseStatus::Permissive === $finding->getStatus() && null === $permissive) {
                $permissive = $finding;
            }
        }

        // Copyleft wins whenever any source claims it. Overstating a user's rights
        // is the only error here with a victim: a merchant told an AGPL plugin is
        // MIT can deploy a modified copy in a hosted shop believing they owe
        // nothing. Understating them merely makes us look cautious. The licence gate requires
        // copyleft to be classified separately rather than lumped under an
        // "MIT/open source" label, and this is where that is enforced.
        $winner = $copyleft ?? $permissive;

        if (null === $winner) {
            return;
        }

        $extension->forceLicense($winner->getSpdx(), $winner->getStatus(), $winner->getSource());

        // An extension held back only by an unreadable manifest becomes listable
        // once the licence file is read. A takedown still outranks everything.
        if (IndexStatus::Delisted !== $extension->getIndexStatus()) {
            $extension->setIndexStatus(IndexStatus::Listed);
        }
    }

    private function hasDetectorFinding(Extension $extension, string $spdx): bool
    {
        foreach ($extension->getLicenseFindings() as $finding) {
            if (FindingSource::Detector === $finding->getSource() && $finding->getSpdx() === $spdx) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the repository's licence file contradicts what composer.json claims.
     *
     * Worth surfacing rather than silently correcting: for the maintainer it is a
     * one-line bug in their own manifest, and for a merchant reading the page it
     * explains why we say MIT where the package metadata says otherwise.
     */
    public function manifestContradictsFile(Extension $extension): bool
    {
        if (FindingSource::Detector !== $extension->getLicenseEvidence()) {
            return false;
        }

        foreach ($extension->getLicenseFindings() as $finding) {
            if (FindingSource::ComposerJson === $finding->getSource()
                && !$finding->getStatus()->isRedistributable()) {
                return true;
            }
        }

        return false;
    }
}
