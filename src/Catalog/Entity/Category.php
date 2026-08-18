<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\Repository\CategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A category in extdir's own taxonomy.
 *
 * Deliberately not a mirror of the official Shopware Store tree: copying it would
 * couple the directory to someone else's product decisions and edge closer to the
 * trademark caution. Assignment is by deterministic keyword rules
 * over composer keywords, `extra.label` and descriptions, so that categorisation
 * stays auditable in the same way ranking is (the conflict-of-interest rule).
 */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Table(name: 'category')]
#[ORM\UniqueConstraint(name: 'uniq_category_key', columns: ['category_key'])]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'category_key', length: 64)]
    private string $key;

    #[ORM\Column(length: 128)]
    private string $label;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    public function __construct(string $key, string $label, ?string $description = null, int $sortOrder = 0)
    {
        $this->key = $key;
        $this->label = $label;
        $this->description = $description;
        $this->sortOrder = $sortOrder;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }
}
