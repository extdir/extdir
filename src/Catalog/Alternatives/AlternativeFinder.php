<?php

declare(strict_types=1);

namespace App\Catalog\Alternatives;

use App\Catalog\Entity\Extension;
use App\Catalog\Repository\ExtensionRepository;
use App\Compatibility\Repository\CompatibilityClaimRepository;
use App\License\Enum\LicenseStatus;
use App\Signals\Enum\MaintenanceStatus;

/**
 * "Extensions like this one.".
 *
 * The primary reader here is an agency developer comparing candidates, and the
 * question behind the feature is not "what is similar" but "what could I install
 * instead". Those differ: an extension in the same category that stops at Shopware
 * 6.4 is similar and useless to somebody running 6.7.
 *
 * So compatibility is weighted almost as heavily as category, and a licence rule
 * overrides both — see below.
 *
 * Nothing here is personalised and nothing is paid for. The scoring is the same for
 * every vendor including the operator's own, which the conflict-of-interest rule
 * requires and NoVendorExceptionsTest enforces.
 */
final readonly class AlternativeFinder
{
    private const int CANDIDATE_POOL = 120;

    public function __construct(
        private ExtensionRepository $extensions,
        private CompatibilityClaimRepository $claims,
    ) {
    }

    /**
     * @return list<Alternative>
     */
    public function forExtension(Extension $subject, int $limit = 6): array
    {
        $candidates = $this->candidates($subject);

        if ([] === $candidates) {
            return [];
        }

        $subjectCategories = $this->categoryKeys($subject);
        $subjectKeywords = $this->normalisedKeywords($subject);
        $subjectVersions = array_keys($this->claims->findMatrixForExtension($subject));

        // One query for every candidate rather than one per candidate.
        $matrices = $this->claims->findMatrixForExtensions($candidates);

        $scored = [];

        foreach ($candidates as $candidate) {
            $reasons = [];

            $categoryOverlap = $this->jaccard($subjectCategories, $this->categoryKeys($candidate));
            $keywordOverlap = $this->jaccard($subjectKeywords, $this->normalisedKeywords($candidate));

            $candidateVersions = array_keys($matrices[$candidate->getId()] ?? []);
            $versionOverlap = $this->jaccard($subjectVersions, $candidateVersions);

            if ($categoryOverlap > 0.0) {
                $reasons[] = 'same category';
            }

            // Stated in terms of the subject's versions rather than the overlap
            // score, because "covers every version this one does" is a claim a
            // reader can verify against the chips on the row.
            if ([] !== $subjectVersions && [] === array_diff($subjectVersions, $candidateVersions)) {
                $reasons[] = 'covers the same Shopware versions';
            } elseif ($versionOverlap > 0.0) {
                $reasons[] = 'overlapping Shopware versions';
            }

            if (MaintenanceStatus::Current === $candidate->getMaintenanceStatus()) {
                $reasons[] = 'actively maintained';
            }

            $score =
                (0.45 * $categoryOverlap)
                + (0.30 * $versionOverlap)
                + (0.15 * $keywordOverlap)
                + (0.10 * $this->maintenanceWeight($candidate->getMaintenanceStatus()));

            if ($score <= 0.0) {
                continue;
            }

            $scored[] = new Alternative($candidate, $score, $reasons, $candidateVersions);
        }

        usort($scored, static fn (Alternative $a, Alternative $b): int => $b->score <=> $a->score);

        return \array_slice($scored, 0, $limit);
    }

    /**
     * The pool to score against.
     *
     * Categories first, because they are the strongest signal, and keywords as a
     * fallback — 156 of the 423 indexed extensions matched no category rule, and
     * leaving those pages empty would mean the feature is missing exactly where the
     * catalogue is already thinnest.
     *
     * @return list<Extension>
     */
    private function candidates(Extension $subject): array
    {
        $categories = $this->categoryKeys($subject);
        $keywords = $this->normalisedKeywords($subject);

        if ([] === $categories && [] === $keywords) {
            return [];
        }

        $qb = $this->extensions->createQueryBuilder('e')
            ->leftJoin('e.categories', 'c')
            ->andWhere('e.id != :self')
            ->andWhere('e.indexStatus = :listed')
            ->setParameter('self', $subject->getId())
            ->setParameter('listed', \App\Catalog\Enum\IndexStatus::Listed)
            ->orderBy('e.rankScore', 'DESC')
            ->setMaxResults(self::CANDIDATE_POOL);

        if ([] !== $categories) {
            $qb->andWhere('c.key IN (:categories)')->setParameter('categories', $categories);
        }

        // The licence rule, and the one that overrides ranking entirely.
        //
        // Suggesting an unlicensed extension as a replacement for a licensed one
        // would be telling a merchant to swap something they may reuse for something
        // they may not. The reverse is harmless: anything is a safe alternative to
        // code that cannot be redistributed at all.
        if (LicenseStatus::Unknown !== $subject->getLicenseStatus() && LicenseStatus::Rejected !== $subject->getLicenseStatus()) {
            $qb->andWhere('e.licenseStatus NOT IN (:refused)')
                ->setParameter('refused', [LicenseStatus::Unknown, LicenseStatus::Rejected]);
        }

        /** @var list<Extension> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * @return list<string>
     */
    private function categoryKeys(Extension $extension): array
    {
        $keys = [];

        foreach ($extension->getCategories() as $category) {
            $keys[] = $category->getKey();
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function normalisedKeywords(Extension $extension): array
    {
        $keywords = array_map(
            static fn (string $k): string => mb_strtolower(trim($k)),
            $extension->getKeywords(),
        );

        // "shopware", "shopware6" and "plugin" appear on almost everything and
        // therefore distinguish nothing; leaving them in makes every extension look
        // like an alternative to every other.
        $useless = ['shopware', 'shopware6', 'shopware-6', 'plugin', 'extension', 'sw6'];

        return array_values(array_filter(
            array_unique($keywords),
            static fn (string $k): bool => '' !== $k && !\in_array($k, $useless, true),
        ));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ([] === $a || [] === $b) {
            return 0.0;
        }

        $intersection = \count(array_intersect($a, $b));

        if (0 === $intersection) {
            return 0.0;
        }

        return $intersection / \count(array_unique(array_merge($a, $b)));
    }

    private function maintenanceWeight(MaintenanceStatus $status): float
    {
        return match ($status) {
            MaintenanceStatus::Current => 1.0,
            MaintenanceStatus::Lagging => 0.6,
            MaintenanceStatus::Dormant => 0.2,
            MaintenanceStatus::Abandoned, MaintenanceStatus::Unknown => 0.0,
        };
    }
}
