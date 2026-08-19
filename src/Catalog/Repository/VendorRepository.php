<?php

declare(strict_types=1);

namespace App\Catalog\Repository;

use App\Catalog\Entity\Vendor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Vendor>
 */
class VendorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vendor::class);
    }

    public function findOneBySlug(string $slug): ?Vendor
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Vendors that have at least one visible extension, with their counts.
     *
     * A vendor whose every extension has been delisted still has a row, and a page
     * listing nothing would be a dead end for a search engine and a reader alike.
     *
     * @return list<array{vendor: Vendor, extensions: int}>
     */
    public function findWithVisibleExtensions(): array
    {
        /** @var list<array{0: Vendor, extensions: int}> $rows */
        $rows = $this->createQueryBuilder('v')
            ->select('v', 'COUNT(e.id) AS extensions')
            ->join('v.extensions', 'e')
            ->andWhere('e.indexStatus != :delisted')
            ->setParameter('delisted', \App\Catalog\Enum\IndexStatus::Delisted)
            ->groupBy('v.id')
            ->orderBy('extensions', 'DESC')
            ->addOrderBy('v.name', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): array => ['vendor' => $row[0], 'extensions' => (int) $row['extensions']],
            $rows,
        );
    }

    public function findOneByName(string $name): ?Vendor
    {
        return $this->findOneBy(['name' => $name]);
    }
}
