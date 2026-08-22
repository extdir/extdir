<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\Repository\VendorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VendorRepository::class)]
#[ORM\Table(name: 'vendor')]
#[ORM\UniqueConstraint(name: 'uniq_vendor_name', columns: ['name'])]
#[ORM\UniqueConstraint(name: 'uniq_vendor_slug', columns: ['slug'])]
class Vendor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    /** The Packagist vendor segment, e.g. `swagger` in `swagger/plugin`. */
    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\Column(length: 128)]
    private string $slug;

    /**
     * Marks the vendor operated by the person who runs extdir (`runio`).
     *
     * The conflict-of-interest rule requires these packages to carry a visible disclosure badge.
     * It is a display flag and nothing more, deliberately not consulted anywhere
     * in ranking or verification, and there is a test asserting exactly that. The
     * whole credibility of a directory whose maintainer also publishes extensions
     * rests on this staying inert.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $maintainerOperated = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $homepage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Extension> */
    #[ORM\OneToMany(targetEntity: Extension::class, mappedBy: 'vendor')]
    private Collection $extensions;

    public function __construct(string $name, string $slug)
    {
        $this->name = $name;
        $this->slug = $slug;
        $this->createdAt = new \DateTimeImmutable();
        $this->extensions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function isMaintainerOperated(): bool
    {
        return $this->maintainerOperated;
    }

    public function setMaintainerOperated(bool $maintainerOperated): void
    {
        $this->maintainerOperated = $maintainerOperated;
    }

    public function getHomepage(): ?string
    {
        return $this->homepage;
    }

    public function setHomepage(?string $homepage): void
    {
        $this->homepage = $homepage;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Extension> */
    public function getExtensions(): Collection
    {
        return $this->extensions;
    }
}
