<?php

declare(strict_types=1);

namespace App\Ingestion\GitHub\Entity;

use App\Ingestion\GitHub\Repository\GitHubTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The crawler's GitHub user access token, with its refresh token.
 *
 * Stored in the database rather than in an environment variable because it is not
 * configuration — it rotates. Access tokens last 8 hours and refresh tokens 6
 * months, so whichever worker refreshes first must be able to publish the new pair
 * to all the others. An env var cannot do that without a redeploy every 8 hours.
 *
 * A single row: the crawler authenticates as one identity.
 */
#[ORM\Entity(repositoryClass: GitHubTokenRepository::class)]
#[ORM\Table(name: 'github_token')]
class GitHubToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $accessToken;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $refreshToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $refreshExpiresAt = null;

    /** The GitHub login that authorised the app, shown so it is obvious whose quota is being spent. */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $login = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $accessToken)
    {
        $this->accessToken = $accessToken;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Treats a token as expired slightly early.
     *
     * A token that passes this check must survive the request that follows it, and
     * a crawl request can sit in a queue behind a slow response. The margin is what
     * stops a long-running worker from picking up a token with four seconds left.
     */
    public function isExpired(int $marginSeconds = 300): bool
    {
        if (null === $this->expiresAt) {
            // Expiry is optional on the App. No expiry set means it does not lapse.
            return false;
        }

        return $this->expiresAt <= new \DateTimeImmutable(\sprintf('+%d seconds', $marginSeconds));
    }

    public function canRefresh(): bool
    {
        if (null === $this->refreshToken) {
            return false;
        }

        return null === $this->refreshExpiresAt || $this->refreshExpiresAt > new \DateTimeImmutable();
    }

    public function update(
        string $accessToken,
        ?string $refreshToken,
        ?\DateTimeImmutable $expiresAt,
        ?\DateTimeImmutable $refreshExpiresAt,
    ): void {
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
        $this->expiresAt = $expiresAt;
        $this->refreshExpiresAt = $refreshExpiresAt;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getLogin(): ?string
    {
        return $this->login;
    }

    public function setLogin(?string $login): void
    {
        $this->login = $login;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
