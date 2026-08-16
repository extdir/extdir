<?php

declare(strict_types=1);

namespace App\Catalog\Repository;

use App\Catalog\Entity\Vendor;
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

    public function findOneByName(string $name): ?Vendor
    {
        return $this->findOneBy(['name' => $name]);
    }
}
