<?php

declare(strict_types=1);

namespace App\Catalog\Repository;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Catalog\Enum\IndexStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Extension>
 */
class ExtensionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Extension::class);
    }

    public function findOneByPackageName(string $packageName): ?Extension
    {
        return $this->findOneBy(['packageName' => $packageName]);
    }

    public function findOneBySlug(string $slug): ?Extension
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Package names already known, as a lookup set.
     *
     * Discovery compares a ~1,500-entry Packagist list against what we hold; doing
     * that with one findOneBy per package is 1,500 round trips for a set difference
     * that fits comfortably in memory.
     *
     * @return array<string, true>
     */
    public function findAllPackageNames(): array
    {
        /** @var list<array{packageName: string}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.packageName')
            ->getQuery()
            ->getArrayResult();

        $names = [];
        foreach ($rows as $row) {
            $names[$row['packageName']] = true;
        }

        return $names;
    }

    /**
     * Everything that may appear publicly, ordered for stable output.
     *
     * Shares the visibility rule with search rather than restating it: a delisted
     * extension that vanished from the listing but lingered in the sitemap would
     * keep being re-crawled and re-surfaced, which is a takedown that only half
     * happened.
     *
     * @return list<Extension>
     */
    public function findPubliclyVisible(): array
    {
        /** @var list<Extension> $result */
        $result = $this->createQueryBuilder('e')
            ->where('e.indexStatus IN (:visible)')
            ->setParameter('visible', [IndexStatus::Listed, IndexStatus::IndexOnly])
            ->orderBy('e.rankScore', 'DESC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Everything visible from one vendor, best first.
     *
     * @return list<Extension>
     */
    public function findVisibleForVendor(Vendor $vendor): array
    {
        /** @var list<Extension> $result */
        $result = $this->createQueryBuilder('e')
            ->where('e.vendor = :vendor')
            ->andWhere('e.indexStatus IN (:visible)')
            ->setParameter('vendor', $vendor)
            ->setParameter('visible', [IndexStatus::Listed, IndexStatus::IndexOnly])
            ->orderBy('e.rankScore', 'DESC')
            ->addOrderBy('e.label', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Extensions whose metadata is stalest, for incremental crawling.
     *
     * @return list<Extension>
     */
    public function findStalest(int $limit, ?\DateTimeImmutable $olderThan = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.lastCrawledAt', 'ASC')
            ->setMaxResults($limit);

        // NULLs sort first in MariaDB ascending order, so never-crawled extensions
        // are picked up before stale ones without any special casing.
        if (null !== $olderThan) {
            $qb->andWhere('e.lastCrawledAt IS NULL OR e.lastCrawledAt < :cutoff')
                ->setParameter('cutoff', $olderThan);
        }

        /** @var list<Extension> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
