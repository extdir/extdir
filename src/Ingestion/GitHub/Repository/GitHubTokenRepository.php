<?php

declare(strict_types=1);

namespace App\Ingestion\GitHub\Repository;

use App\Ingestion\GitHub\Entity\GitHubToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GitHubToken>
 */
class GitHubTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GitHubToken::class);
    }

    /**
     * The crawler authenticates as a single identity, so there is at most one row.
     */
    public function findCurrent(): ?GitHubToken
    {
        return $this->findOneBy([], ['id' => 'DESC']);
    }
}
