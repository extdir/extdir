<?php

declare(strict_types=1);

namespace App\Compatibility\Repository;

use App\Compatibility\Entity\ShopwareVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShopwareVersion>
 */
class ShopwareVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShopwareVersion::class);
    }

    /**
     * @return list<ShopwareVersion>
     */
    public function findOrdered(): array
    {
        /** @var list<ShopwareVersion> $result */
        $result = $this->findBy([], ['sortOrder' => 'ASC']);

        return $result;
    }

    /**
     * Only the minors rendered as columns in the public matrix. Showing every
     * historical minor back to 6.0 would make the table unreadable and imply we
     * care about versions nobody should still be running.
     *
     * @return list<ShopwareVersion>
     */
    public function findShownInMatrix(): array
    {
        /** @var list<ShopwareVersion> $result */
        $result = $this->findBy(['shownInMatrix' => true], ['sortOrder' => 'ASC']);

        return $result;
    }

    public function findCurrent(): ?ShopwareVersion
    {
        return $this->findOneBy(['current' => true]);
    }

    /**
     * The newest minors, most recent first. Maintenance scoring asks "was there
     * activity after this one shipped?" for the latest two.
     *
     * @return list<ShopwareVersion>
     */
    public function findLatest(int $count): array
    {
        /** @var list<ShopwareVersion> $result */
        $result = $this->findBy([], ['sortOrder' => 'DESC'], $count);

        return $result;
    }

    public function findByMajorMinor(string $majorMinor): ?ShopwareVersion
    {
        return $this->findOneBy(['majorMinor' => $majorMinor]);
    }
}
