<?php

declare(strict_types=1);

namespace App\Compatibility\Entity;

use App\Compatibility\Repository\ShopwareVersionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Reference data: one row per Shopware 6 minor, with the date it shipped.
 *
 * This table is what turns two otherwise-mushy features into precise ones.
 *
 * For compatibility, it supplies the version ranges a declared constraint is tested
 * against. Note the ranges are stored as bounds rather than a single representative
 * version, because testing `>=6.6.5` against a stand-in "6.6.0.0" would wrongly
 * report the extension as incompatible with 6.6. What we actually want to know is
 * whether the declared constraint *intersects* the minor's range at all.
 *
 * For maintenance signals, `releasedAt` is the yardstick that replaces the calendar:
 * "no commits since 6.7 shipped" is a statement a merchant can act on, where "no
 * commits in 18 months" is not (see MaintenanceStatus).
 */
#[ORM\Entity(repositoryClass: ShopwareVersionRepository::class)]
#[ORM\Table(name: 'shopware_version')]
#[ORM\UniqueConstraint(name: 'uniq_shopware_version_minor', columns: ['major_minor'])]
class ShopwareVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    /** e.g. "6.6" */
    #[ORM\Column(length: 16)]
    private string $majorMinor;

    /** Inclusive lower bound, e.g. "6.6.0.0". Shopware uses four-part versions. */
    #[ORM\Column(length: 32)]
    private string $lowerBound;

    /** Exclusive upper bound, e.g. "6.7.0.0". */
    #[ORM\Column(length: 32)]
    private string $upperBound;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $releasedAt;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endOfLifeAt = null;

    /** Whether this is the newest minor. Exactly one row should carry it. */
    #[ORM\Column(options: ['default' => false])]
    private bool $current = false;

    /** Whether to render a column for this minor in the public matrix. */
    #[ORM\Column(options: ['default' => true])]
    private bool $shownInMatrix = true;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    public function __construct(
        string $majorMinor,
        string $lowerBound,
        string $upperBound,
        \DateTimeImmutable $releasedAt,
        int $sortOrder = 0,
    ) {
        $this->majorMinor = $majorMinor;
        $this->lowerBound = $lowerBound;
        $this->upperBound = $upperBound;
        $this->releasedAt = $releasedAt;
        $this->sortOrder = $sortOrder;
    }

    /**
     * The half-open range as a composer/semver constraint string. Half-open so that
     * adjacent minors never both claim the same version.
     */
    public function toConstraintString(): string
    {
        return \sprintf('>=%s <%s', $this->lowerBound, $this->upperBound);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMajorMinor(): string
    {
        return $this->majorMinor;
    }

    public function getLowerBound(): string
    {
        return $this->lowerBound;
    }

    public function getUpperBound(): string
    {
        return $this->upperBound;
    }

    public function getReleasedAt(): \DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function getEndOfLifeAt(): ?\DateTimeImmutable
    {
        return $this->endOfLifeAt;
    }

    public function setEndOfLifeAt(?\DateTimeImmutable $endOfLifeAt): void
    {
        $this->endOfLifeAt = $endOfLifeAt;
    }

    public function isCurrent(): bool
    {
        return $this->current;
    }

    public function setCurrent(bool $current): void
    {
        $this->current = $current;
    }

    public function isShownInMatrix(): bool
    {
        return $this->shownInMatrix;
    }

    public function setShownInMatrix(bool $shownInMatrix): void
    {
        $this->shownInMatrix = $shownInMatrix;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }
}
