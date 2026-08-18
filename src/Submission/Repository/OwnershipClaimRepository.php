<?php

declare(strict_types=1);

namespace App\Submission\Repository;

use App\Catalog\Entity\Extension;
use App\Submission\Entity\OwnershipClaim;
use App\Submission\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OwnershipClaim>
 */
class OwnershipClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OwnershipClaim::class);
    }

    public function findFor(User $user, Extension $extension): ?OwnershipClaim
    {
        return $this->findOneBy(['user' => $user, 'extension' => $extension]);
    }

    /**
     * @return list<OwnershipClaim>
     */
    public function findActiveFor(User $user): array
    {
        /** @var list<OwnershipClaim> $result */
        $result = $this->findBy(['user' => $user, 'revokedAt' => null], ['verifiedAt' => 'DESC']);

        return $result;
    }
}
