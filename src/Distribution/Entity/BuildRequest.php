<?php

declare(strict_types=1);

namespace App\Distribution\Entity;

use App\Catalog\Entity\ExtensionRelease;
use App\Distribution\Enum\BuildState;
use App\Distribution\Repository\BuildRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A request for the isolated CI runner to build one release.
 *
 * Nothing here executes anything, and that separation is the whole point:
 * `composer install` and `npm install` run arbitrary scripts from untrusted
 * repositories, so they must never run on the host holding the database and the
 * credentials. This row is a message to an ephemeral runner and a record of what
 * came back.
 */
#[ORM\Entity(repositoryClass: BuildRequestRepository::class)]
#[ORM\Table(name: 'build_request')]
#[ORM\UniqueConstraint(name: 'uniq_build_release', columns: ['release_id'])]
#[ORM\Index(name: 'idx_build_state', columns: ['state'])]
class BuildRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ExtensionRelease::class)]
    #[ORM\JoinColumn(name: 'release_id', nullable: false, onDelete: 'CASCADE')]
    private ExtensionRelease $release;

    #[ORM\Column(length: 16, enumType: BuildState::class)]
    private BuildState $state = BuildState::Queued;

    /**
     * A single-use secret tying a callback to this request.
     *
     * The callback carries provenance that becomes a public trust claim, so the
     * app also re-verifies the workflow run against the GitHub API before
     * believing any of it. This token establishes *which* request is being
     * answered; it is not on its own evidence that the answer is true.
     */
    #[ORM\Column(length: 64)]
    private string $callbackToken;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $workflowRunId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dispatchedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(ExtensionRelease $release, string $callbackToken)
    {
        $this->release = $release;
        $this->callbackToken = $callbackToken;
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function markDispatched(string $workflowRunId): void
    {
        $this->state = BuildState::Dispatched;
        $this->workflowRunId = $workflowRunId;
        $this->dispatchedAt = new \DateTimeImmutable();
        ++$this->attempts;
    }

    public function markSucceeded(): void
    {
        $this->state = BuildState::Succeeded;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $reason): void
    {
        $this->state = BuildState::Failed;
        $this->failureReason = $reason;
        $this->completedAt = new \DateTimeImmutable();
    }

    /**
     * The licence gate refused this build. Recorded as a decision, not a fault,
     * and never retried.
     */
    public function markRejected(string $reason): void
    {
        $this->state = BuildState::Rejected;
        $this->failureReason = $reason;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRelease(): ExtensionRelease
    {
        return $this->release;
    }

    public function getState(): BuildState
    {
        return $this->state;
    }

    public function getCallbackToken(): string
    {
        return $this->callbackToken;
    }

    public function getWorkflowRunId(): ?string
    {
        return $this->workflowRunId;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }
}
