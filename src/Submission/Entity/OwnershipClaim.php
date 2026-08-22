<?php

declare(strict_types=1);

namespace App\Submission\Entity;

use App\Catalog\Entity\Extension;
use App\Submission\Enum\VerificationMethod;
use App\Submission\Repository\OwnershipClaimRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A confirmed link between a signed-in maintainer and an extension.
 *
 * Verification is deliberately re-checkable rather than permanent. People leave
 * organisations, repositories change hands, and a claim that was true in March is
 * not evidence about today, so the claim records when it was last confirmed, and
 * anything consequential re-verifies rather than trusting the row.
 */
#[ORM\Entity(repositoryClass: OwnershipClaimRepository::class)]
#[ORM\Table(name: 'ownership_claim')]
#[ORM\UniqueConstraint(name: 'uniq_claim_user_extension', columns: ['user_id', 'extension_id'])]
class OwnershipClaim
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Extension::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Extension $extension;

    #[ORM\Column(length: 32, enumType: VerificationMethod::class)]
    private VerificationMethod $method;

    /**
     * What was actually observed, in words, "GitHub reported admin permission",
     * "token found at .extdir-verification on main". A verification nobody can
     * inspect afterwards is indistinguishable from one that never happened.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $evidence;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $verifiedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        User $user,
        Extension $extension,
        VerificationMethod $method,
        string $evidence,
    ) {
        $this->user = $user;
        $this->extension = $extension;
        $this->method = $method;
        $this->evidence = $evidence;
        $this->verifiedAt = new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return null === $this->revokedAt;
    }

    public function reconfirm(VerificationMethod $method, string $evidence): void
    {
        $this->method = $method;
        $this->evidence = $evidence;
        $this->verifiedAt = new \DateTimeImmutable();
        $this->revokedAt = null;
    }

    public function revoke(): void
    {
        $this->revokedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getExtension(): Extension
    {
        return $this->extension;
    }

    public function getMethod(): VerificationMethod
    {
        return $this->method;
    }

    public function getEvidence(): string
    {
        return $this->evidence;
    }

    public function getVerifiedAt(): \DateTimeImmutable
    {
        return $this->verifiedAt;
    }
}
