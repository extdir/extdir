<?php

declare(strict_types=1);

namespace App\Compatibility\Repository;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\ExtensionRelease;
use App\Compatibility\Entity\CompatibilityClaim;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompatibilityClaim>
 */
class CompatibilityClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompatibilityClaim::class);
    }

    /**
     * Drops every claim for a release. Recomputation replaces rather than patches,
     * because a re-parse can turn a satisfied claim into an unsatisfied one and a
     * merge would leave the stale row behind.
     */
    public function deleteForRelease(ExtensionRelease $release): void
    {
        $this->createQueryBuilder('c')
            ->delete()
            ->where('c.release = :release')
            ->setParameter('release', $release)
            ->getQuery()
            ->execute();
    }

    /**
     * The matrix row for one extension: which Shopware minors any stable release
     * declares support for, and the best tier backing each.
     *
     * @return array<string, string> majorMinor => tier value
     */
    public function findMatrixForExtension(Extension $extension): array
    {
        /** @var list<array{majorMinor: string, tier: string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('sv.majorMinor AS majorMinor', 'c.tier AS tier')
            ->join('c.shopwareVersion', 'sv')
            ->join('c.release', 'r')
            ->where('c.extension = :extension')
            ->andWhere('c.satisfied = true')
            ->andWhere('r.stable = true')
            ->setParameter('extension', $extension)
            ->getQuery()
            ->getArrayResult();

        $matrix = [];
        foreach ($rows as $row) {
            $existing = $matrix[$row['majorMinor']] ?? null;
            // 'explicit' beats 'caret' beats 'wildcard' beats 'absent'; the enum
            // cases are ordered strongest-first, so a lower index wins.
            if (null === $existing || self::tierRank($row['tier']) < self::tierRank($existing)) {
                $matrix[$row['majorMinor']] = $row['tier'];
            }
        }

        return $matrix;
    }

    private static function tierRank(string $tier): int
    {
        return match ($tier) {
            'explicit' => 0,
            'caret' => 1,
            'wildcard' => 2,
            default => 3,
        };
    }
}
