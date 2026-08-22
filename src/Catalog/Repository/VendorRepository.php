<?php

declare(strict_types=1);

namespace App\Catalog\Repository;

use App\Catalog\Entity\Vendor;
use App\Catalog\Enum\IndexStatus;
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
            ->setParameter('delisted', IndexStatus::Delisted)
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

    /**
     * How many extensions each vendor maintains.
     *
     * The plainest of the three vendor boards and the easiest to argue with: it counts
     * extensions without asking whether any of them still work.
     *
     * @return list<array{vendor: Vendor, value: float}>
     */
    public function topByExtensionCount(int $limit): array
    {
        return $this->board('COUNT(e.id)', $limit);
    }

    /**
     * The published per-extension score, added up per vendor.
     *
     * The one board that says something about quality, and the reason it can exist
     * without breaking the conflict-of-interest rule is that it introduces no new
     * formula: rankScore is the number already shown on every extension page and
     * explained in full on /ranking. Summing a public score is not a second algorithm
     * to disclose, so there is nothing here that a vendor could not have predicted.
     *
     * Divided by 100 so the figure reads as "extensions maintained well" rather than a
     * four-digit number with no meaning.
     *
     * @return list<array{vendor: Vendor, value: float}>
     */
    public function topByAggregateScore(int $limit): array
    {
        return $this->board('SUM(e.rankScore) / 100', $limit);
    }

    /**
     * Tagged releases shipped across a vendor's extensions.
     *
     * Measures sustained activity rather than breadth, and disagrees with the other two
     * sharply enough to be worth its own board: the vendor at the top here maintains a
     * third as many extensions as the vendor at the top of the first board.
     *
     * @return list<array{vendor: Vendor, value: float}>
     */
    public function topByReleaseCount(int $limit): array
    {
        return $this->board('COUNT(r.id)', $limit, joinReleases: true);
    }

    /**
     * One vendor board.
     *
     * The three differ only in what they measure, so they share everything else, most
     * importantly the visibility rule. Boards must show exactly what the catalogue
     * shows: an extension not public enough to be listed is not public enough to win
     * anything.
     *
     * @return list<array{vendor: Vendor, value: float}>
     */
    private function board(string $expression, int $limit, bool $joinReleases = false): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v', $expression.' AS value')
            ->join('v.extensions', 'e')
            ->andWhere('e.indexStatus IN (:visible)')
            ->setParameter('visible', [IndexStatus::Listed, IndexStatus::IndexOnly])
            ->groupBy('v.id')
            ->orderBy('value', 'DESC')
            // Ties resolved by name rather than by row id, so the order is reproducible
            // by anyone reading the rule and does not shuffle between deploys.
            ->addOrderBy('v.name', 'ASC')
            ->setMaxResults($limit);

        if ($joinReleases) {
            $qb->join('e.releases', 'r');
        }

        /** @var list<array{0: Vendor, value: string|float|int}> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(
            static fn (array $row): array => ['vendor' => $row[0], 'value' => (float) $row['value']],
            $rows,
        );
    }
}
