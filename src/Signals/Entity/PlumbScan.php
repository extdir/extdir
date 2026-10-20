<?php

declare(strict_types=1);

namespace App\Signals\Entity;

use App\Catalog\Entity\Extension;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What Plumb found when it last looked at an extension's repository.
 *
 * Plumb scores PHP packages on supply-chain security and publishes a guide for every
 * check it runs. This is the one thing the directory has never been able to say
 * anything about: extdir measures whether an extension works with your Shopware,
 * whether its licence permits use, and whether anybody is still maintaining it, and
 * none of those answer whether its release pipeline can be hijacked.
 *
 * Three rules govern this table, and they are the reason it is a separate entity
 * rather than a handful of columns on extension.
 *
 * **Nothing here is ever ranked.** These are a third party's findings, produced by an
 * algorithm this project does not control and cannot publish as its own. The
 * conflict-of-interest rule requires the ranking algorithm to be public, so a score
 * from elsewhere may be displayed and may never be scored. This is exactly how
 * install counts are already treated, and RankingScore cannot accept any of these
 * fields without changing its signature. A test fails the build if it ever does.
 *
 * **The evidence is kept, not just the number.** A score with nothing behind it is
 * the black box section 13 says nobody should have to trust. Every check comes back
 * with its own finding, its weight and a link to Plumb's published guide, and those
 * are what the extension page shows. The number is the summary, not the claim.
 *
 * **Absence is recorded as carefully as presence.** checkedAt is always set and
 * scannedAt is null when Plumb has no scan, because "we asked and Plumb has not
 * looked at this one yet" and "we never asked" are different facts, and so is "this
 * extension is not on Packagist at all, so Plumb will never see it". Roughly three
 * in ten of the catalogue is in that last group permanently.
 */
#[ORM\Entity]
#[ORM\Table(name: 'plumb_scan')]
#[ORM\Index(name: 'idx_plumb_security', columns: ['security_score'])]
class PlumbScan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Extension::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Extension $extension;

    /** When extdir last asked Plumb about this package. Always set. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $checkedAt;

    /** When Plumb last scanned it. Null means Plumb does not have it. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scannedAt = null;

    /**
     * Plumb's security score out of a hundred.
     *
     * Only the security axis is kept. Their maintenance axis measures what this site
     * already measures for itself, against Shopware release dates rather than against
     * nothing in particular, and a second number that disagrees with the first would
     * leave no way to tell a reader which one is right. Their ecosystem axis asks
     * about Laravel and about the current Symfony release, neither of which is the
     * question a Shopware extension should be judged on, and their composite blends
     * all three into one figure that would look precise and mean something else.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $securityScore = null;

    /**
     * The version Plumb actually scanned, so the finding can be checked against it.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $referenceVersion = null;

    /**
     * Every check Plumb ran, as returned, including the ones that did not apply.
     *
     * @var list<array{id: string, category: string, title: string, status: string, weight: int, guide: ?string, evidence: ?string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $checks = [];

    public function __construct(Extension $extension)
    {
        $this->extension = $extension;
        $this->checkedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExtension(): Extension
    {
        return $this->extension;
    }

    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function setCheckedAt(\DateTimeImmutable $checkedAt): void
    {
        $this->checkedAt = $checkedAt;
    }

    public function getScannedAt(): ?\DateTimeImmutable
    {
        return $this->scannedAt;
    }

    public function setScannedAt(?\DateTimeImmutable $scannedAt): void
    {
        $this->scannedAt = $scannedAt;
    }

    public function getSecurityScore(): ?float
    {
        return $this->securityScore;
    }

    public function setSecurityScore(?float $securityScore): void
    {
        $this->securityScore = $securityScore;
    }

    public function getReferenceVersion(): ?string
    {
        return $this->referenceVersion;
    }

    public function setReferenceVersion(?string $referenceVersion): void
    {
        $this->referenceVersion = $referenceVersion;
    }

    /**
     * @return list<array{id: string, category: string, title: string, status: string, weight: int, guide: ?string, evidence: ?string}>
     */
    public function getChecks(): array
    {
        return $this->checks;
    }

    /**
     * @param list<array{id: string, category: string, title: string, status: string, weight: int, guide: ?string, evidence: ?string}> $checks
     */
    public function setChecks(array $checks): void
    {
        $this->checks = $checks;
    }

    /**
     * Whether Plumb has actually looked at this package.
     */
    public function isScanned(): bool
    {
        return null !== $this->scannedAt;
    }
}
