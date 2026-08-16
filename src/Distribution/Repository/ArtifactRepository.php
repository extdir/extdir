<?php

declare(strict_types=1);

namespace App\Distribution\Repository;

use App\Distribution\Entity\Artifact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Artifact>
 */
class ArtifactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Artifact::class);
    }

    /**
     * How many artifacts exist per source, for the operator dashboard and for
     * checking that the link-first strategy is actually holding.
     *
     * @return array<string, int>
     */
    public function countBySource(): array
    {
        /** @var list<array{source: string, n: int}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.source AS source', 'COUNT(a.id) AS n')
            ->groupBy('a.source')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['source']] = (int) $row['n'];
        }

        return $counts;
    }
}
