<?php

declare(strict_types=1);

namespace App\Submission\Entity;

use App\Catalog\Entity\Extension;
use App\Submission\Enum\ModerationActionType;
use App\Submission\Repository\ModerationActionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An append-only record of every consequential decision about an extension.
 *
 * Two rules in the brief are claims about history rather than about code. The legal obligations
 * requires a takedown procedure that was actually followed; the conflict-of-interest rule requires that no
 * vendor, including the one operated by this directory's maintainer, receives
 * treatment another vendor could not get. Neither can be demonstrated from current
 * state alone, only from a record of how the state was reached.
 *
 * So this table is written by the same code path for everyone, carries who acted
 * and why, and is never updated or deleted. The takedown policy promises the
 * record is kept internally rather than published, which is the balance between
 * being auditable and not turning every removal request into a public event.
 */
#[ORM\Entity(repositoryClass: ModerationActionRepository::class)]
#[ORM\Table(name: 'moderation_action')]
#[ORM\Index(name: 'idx_moderation_extension', columns: ['extension_id', 'created_at'])]
class ModerationAction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Extension::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Extension $extension;

    /**
     * Null when the actor was the system rather than a person, a crawler
     * correcting metadata, say. Recorded as null rather than as a fake user so the
     * log never implies a human made a decision they did not make.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    /** Kept as text so the log survives the account being deleted. */
    #[ORM\Column(length: 128)]
    private string $actorLabel;

    #[ORM\Column(length: 32, enumType: ModerationActionType::class)]
    private ModerationActionType $action;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Extension $extension,
        ModerationActionType $action,
        string $reason,
        ?User $actor = null,
    ) {
        $this->extension = $extension;
        $this->action = $action;
        $this->reason = $reason;
        $this->actor = $actor;
        $this->actorLabel = $actor?->getLogin() ?? 'system';
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExtension(): Extension
    {
        return $this->extension;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getActorLabel(): string
    {
        return $this->actorLabel;
    }

    public function getAction(): ModerationActionType
    {
        return $this->action;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
