<?php

declare(strict_types=1);

namespace App\Compatibility\Entity;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\ExtensionRelease;
use App\Compatibility\Enum\ConstraintTier;
use App\Compatibility\Repository\CompatibilityClaimRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Release R declares support for Shopware minor V.".
 *
 * Named a claim rather than a compatibility *fact* because that is exactly what it
 * is: the maintainer's own assertion, parsed. Nothing here has been tested against
 * a running Shopware installation, and the wording throughout the UI ("declares
 * support for", never "works with") follows from that. Overstating this would make
 * the directory's headline feature quietly dishonest.
 *
 * The extension is denormalised onto the row so the faceted list can filter by
 * "supports 6.7" with a single join instead of walking every release.
 */
#[ORM\Entity(repositoryClass: CompatibilityClaimRepository::class)]
#[ORM\Table(name: 'compatibility_claim')]
#[ORM\UniqueConstraint(name: 'uniq_claim_release_version', columns: ['release_id', 'shopware_version_id'])]
#[ORM\Index(name: 'idx_claim_lookup', columns: ['extension_id', 'shopware_version_id', 'satisfied'])]
class CompatibilityClaim
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ExtensionRelease::class)]
    #[ORM\JoinColumn(name: 'release_id', nullable: false, onDelete: 'CASCADE')]
    private ExtensionRelease $release;

    #[ORM\ManyToOne(targetEntity: Extension::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Extension $extension;

    #[ORM\ManyToOne(targetEntity: ShopwareVersion::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ShopwareVersion $shopwareVersion;

    #[ORM\Column]
    private bool $satisfied;

    /** Copied from the release so a query can weight results without a second join. */
    #[ORM\Column(length: 16, enumType: ConstraintTier::class)]
    private ConstraintTier $tier;

    public function __construct(
        ExtensionRelease $release,
        ShopwareVersion $shopwareVersion,
        bool $satisfied,
    ) {
        $this->release = $release;
        $this->extension = $release->getExtension();
        $this->shopwareVersion = $shopwareVersion;
        $this->satisfied = $satisfied;
        $this->tier = $release->getConstraintTier();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRelease(): ExtensionRelease
    {
        return $this->release;
    }

    public function getExtension(): Extension
    {
        return $this->extension;
    }

    public function getShopwareVersion(): ShopwareVersion
    {
        return $this->shopwareVersion;
    }

    public function isSatisfied(): bool
    {
        return $this->satisfied;
    }

    public function getTier(): ConstraintTier
    {
        return $this->tier;
    }
}
