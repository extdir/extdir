<?php

declare(strict_types=1);

namespace App\Moderation\Entity;

use App\Catalog\Entity\Extension;
use App\Moderation\Enum\ComplaintKind;
use App\Moderation\Enum\ComplaintStatus;
use App\Moderation\Repository\ComplaintRepository;
use App\Submission\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A report about an indexed extension.
 *
 * Until now these arrived at legal@extdir.com and lived in an inbox. That is not a
 * process: nobody could say how many were open, how old the oldest was, or whether
 * the seven days the takedown policy promises had already passed. The legal
 * obligations section is explicit that a notice-and-takedown procedure has to exist
 * before launch rather than after the first complaint, and an inbox is not one.
 *
 * Deliberately storable without an account. A rights holder is a lawyer or a brand
 * owner, not a GitHub user, and requiring them to sign in before they can object
 * would be a barrier in exactly the place where a barrier looks like evasion.
 */
#[ORM\Entity(repositoryClass: ComplaintRepository::class)]
#[ORM\Table(name: 'complaint')]
#[ORM\Index(name: 'idx_complaint_queue', columns: ['status', 'created_at'])]
class Complaint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Extension::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Extension $extension;

    #[ORM\Column(length: 16, enumType: ComplaintKind::class)]
    private ComplaintKind $kind;

    #[ORM\Column(length: 16, enumType: ComplaintStatus::class)]
    private ComplaintStatus $status = ComplaintStatus::Open;

    /**
     * How to reach the complainant. Free text rather than a validated email, because
     * a rights notice may name a law firm, a postal address or a case reference, and
     * refusing a complaint for failing a format check would be indefensible.
     */
    #[ORM\Column(length: 255)]
    private string $reporter;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    /** Why it was upheld or rejected. Empty while the complaint is open. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $resolution = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $resolvedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct(Extension $extension, ComplaintKind $kind, string $reporter, string $body)
    {
        $this->extension = $extension;
        $this->kind = $kind;
        $this->reporter = $reporter;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function resolve(ComplaintStatus $status, string $resolution, ?User $actor): void
    {
        if (ComplaintStatus::Open === $status) {
            throw new \InvalidArgumentException('Resolving a complaint means closing it.');
        }

        $this->status = $status;
        $this->resolution = $resolution;
        $this->resolvedBy = $actor;
        $this->resolvedAt = new \DateTimeImmutable();
    }

    /**
     * Whole days since it arrived, for showing an open complaint against the
     * seven-day commitment. A promise nobody can see the clock on is a promise that
     * gets missed.
     */
    public function ageInDays(): int
    {
        return (int) $this->createdAt->diff(new \DateTimeImmutable())->days;
    }

    public function isOverdue(): bool
    {
        return !$this->status->isClosed() && $this->kind->isUrgent() && $this->ageInDays() >= 7;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExtension(): Extension
    {
        return $this->extension;
    }

    public function getKind(): ComplaintKind
    {
        return $this->kind;
    }

    public function getStatus(): ComplaintStatus
    {
        return $this->status;
    }

    public function getReporter(): string
    {
        return $this->reporter;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getResolution(): ?string
    {
        return $this->resolution;
    }

    public function getResolvedBy(): ?User
    {
        return $this->resolvedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }
}
