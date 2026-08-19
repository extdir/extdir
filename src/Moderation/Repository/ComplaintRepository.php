<?php

declare(strict_types=1);

namespace App\Moderation\Repository;

use App\Moderation\Entity\Complaint;
use App\Moderation\Enum\ComplaintStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Complaint>
 */
final class ComplaintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Complaint::class);
    }

    /**
     * The queue: open complaints, oldest first.
     *
     * Oldest first rather than newest, because the thing being managed is a
     * seven-day commitment. A newest-first queue quietly buries whatever is closest
     * to breaching it.
     *
     * @return list<Complaint>
     */
    public function findOpen(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.status = :open')
            ->setParameter('open', ComplaintStatus::Open)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Complaint>
     */
    public function findRecentlyResolved(int $limit = 20): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.status <> :open')
            ->setParameter('open', ComplaintStatus::Open)
            ->orderBy('c.resolvedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countOpen(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.status = :open')
            ->setParameter('open', ComplaintStatus::Open)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
