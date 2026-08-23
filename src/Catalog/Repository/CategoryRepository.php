<?php

declare(strict_types=1);

namespace App\Catalog\Repository;

use App\Catalog\Entity\Category;
use App\Catalog\Entity\Extension;
use App\Catalog\Enum\IndexStatus;
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
     * Visible extensions per category key.
     *
     * The sitemap needs this to apply FacetIndexability before submitting a category
     * page. Counted in one query rather than by running the listing search once per
     * category, which would be twenty-eight round trips to answer a yes or no.
     *
     * @return array<string, int>
     */
    public function countsByKey(): array
    {
        // Queried from the extension side because the association is unidirectional:
        // Extension owns categories, Category holds no inverse collection.
        /** @var list<array{categoryKey: string, total: int|string}> $rows */
        $rows = $this->getEntityManager()
            ->createQuery(
                'SELECT c.key AS categoryKey, COUNT(e.id) AS total'
                .' FROM '.Extension::class.' e JOIN e.categories c'
                .' WHERE e.indexStatus IN (:visible) GROUP BY c.id',
            )
            ->setParameter('visible', [IndexStatus::Listed, IndexStatus::IndexOnly])
            ->getResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['categoryKey']] = (int) $row['total'];
        }

        return $counts;
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
