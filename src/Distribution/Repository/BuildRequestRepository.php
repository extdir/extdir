<?php

declare(strict_types=1);

namespace App\Distribution\Repository;

use App\Distribution\Entity\BuildRequest;
use App\Distribution\Enum\BuildState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BuildRequest>
 */
class BuildRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BuildRequest::class);
    }

    public function findByCallbackToken(string $token): ?BuildRequest
    {
        return $this->findOneBy(['callbackToken' => $token]);
    }

    /**
     * @return list<BuildRequest>
     */
    public function findQueued(int $limit): array
    {
        /** @var list<BuildRequest> $result */
        $result = $this->findBy(['state' => BuildState::Queued], ['requestedAt' => 'ASC'], $limit);

        return $result;
    }
}
