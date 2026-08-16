<?php

declare(strict_types=1);

namespace App\Catalog\Repository;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\ExtensionRelease;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExtensionRelease>
 */
class ExtensionReleaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExtensionRelease::class);
    }

    /**
     * Existing releases for an extension, keyed by normalised version.
     *
     * @return array<string, ExtensionRelease>
     */
    public function findKeyedByVersion(Extension $extension): array
    {
        $keyed = [];
        foreach ($this->findBy(['extension' => $extension]) as $release) {
            $keyed[$release->getVersion()] = $release;
        }

        return $keyed;
    }
}
