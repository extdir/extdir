<?php

declare(strict_types=1);

namespace App\Signals\Entity;

use App\Catalog\Entity\Extension;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A point-in-time reading of a repository's health indicators.
 *
 * Stored as a time series rather than overwritten because the interesting questions
 * are about direction, not level. "Twelve stars" says nothing in an ecosystem where
 * an excellent extension from a German agency has twelve stars (the ranking guidance);
 * "issues have gone from 4 open to 40 open since the last core release" says a
 * great deal. Keeping history also means a ranking change can be evaluated against
 * past data instead of only against whatever the crawler last wrote.
 */
#[ORM\Entity]
#[ORM\Table(name: 'repository_snapshot')]
#[ORM\Index(name: 'idx_snapshot_extension', columns: ['extension_id', 'captured_at'])]
class RepositorySnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Extension::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Extension $extension;

    #[ORM\Column(options: ['default' => 0])]
    private int $stars = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $forks = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $openIssues = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $closedIssues = 0;

    /**
     * Median time to first maintainer response on issues, in hours.
     *
     * The single most useful "is anyone home?" signal, and the one merchants care
     * about when an extension breaks mid-migration. Null until enough issues exist
     * to make a median meaningful.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $medianResponseHours = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastCommitAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $defaultBranch = null;

    /** Latest CI conclusion on the default branch: success, failure, none. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $ciStatus = null;

    /** GitHub's own archive flag — an explicit statement, worth more than inference. */
    #[ORM\Column(options: ['default' => false])]
    private bool $archived = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $capturedAt;

    public function __construct(Extension $extension)
    {
        $this->extension = $extension;
        $this->capturedAt = new \DateTimeImmutable();
    }

    /**
     * Share of issues that have been closed. Used as a responsiveness proxy for
     * repositories too young or too quiet to yield a response-time median.
     */
    public function issueCloseRatio(): ?float
    {
        $total = $this->openIssues + $this->closedIssues;

        return 0 === $total ? null : $this->closedIssues / $total;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExtension(): Extension
    {
        return $this->extension;
    }

    public function getStars(): int
    {
        return $this->stars;
    }

    public function getForks(): int
    {
        return $this->forks;
    }

    public function getOpenIssues(): int
    {
        return $this->openIssues;
    }

    public function getClosedIssues(): int
    {
        return $this->closedIssues;
    }

    public function setCounts(int $stars, int $forks, int $openIssues, int $closedIssues): self
    {
        $this->stars = $stars;
        $this->forks = $forks;
        $this->openIssues = $openIssues;
        $this->closedIssues = $closedIssues;

        return $this;
    }

    public function getMedianResponseHours(): ?float
    {
        return $this->medianResponseHours;
    }

    public function setMedianResponseHours(?float $medianResponseHours): self
    {
        $this->medianResponseHours = $medianResponseHours;

        return $this;
    }

    public function getLastCommitAt(): ?\DateTimeImmutable
    {
        return $this->lastCommitAt;
    }

    public function setLastCommitAt(?\DateTimeImmutable $lastCommitAt): self
    {
        $this->lastCommitAt = $lastCommitAt;

        return $this;
    }

    public function getDefaultBranch(): ?string
    {
        return $this->defaultBranch;
    }

    public function setDefaultBranch(?string $defaultBranch): self
    {
        $this->defaultBranch = $defaultBranch;

        return $this;
    }

    public function getCiStatus(): ?string
    {
        return $this->ciStatus;
    }

    public function setCiStatus(?string $ciStatus): self
    {
        $this->ciStatus = $ciStatus;

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): self
    {
        $this->archived = $archived;

        return $this;
    }

    public function getCapturedAt(): \DateTimeImmutable
    {
        return $this->capturedAt;
    }
}
