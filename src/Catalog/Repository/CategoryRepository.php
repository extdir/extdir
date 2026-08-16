<?php

declare(strict_types=1);

namespace App\Catalog\Repository;

use App\Catalog\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Keyed by category key, for the rules engine which resolves matches by key
     * and would otherwise issue one query per extension per rule.
     *
     * @return array<string, Category>
     */
    public function findAllKeyed(): array
    {
        $result = [];
        foreach ($this->findBy([], ['sortOrder' => 'ASC']) as $category) {
            $result[$category->getKey()] = $category;
        }

        return $result;
    }
}
