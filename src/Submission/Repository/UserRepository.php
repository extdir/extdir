<?php

declare(strict_types=1);

namespace App\Submission\Repository;

use App\Submission\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Looked up by GitHub's numeric id rather than the login, because a login can
     * be changed and then claimed by somebody else.
     */
    public function findByGithubId(int $githubId): ?User
    {
        return $this->findOneBy(['githubId' => $githubId]);
    }
}
