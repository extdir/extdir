<?php

declare(strict_types=1);

namespace App\Submission\Entity;

use App\Submission\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A maintainer who has signed in with GitHub.
 *
 * Stores an identity and nothing more. There is no password, because there is no
 * registration — the only way in is GitHub, which already knows who owns which
 * repository.
 *
 * Notably absent: the user's access token. Verification needs it for exactly one
 * API call, and keeping it afterwards would turn this table into a store of
 * credentials that can push to other people's repositories. A directory has no
 * business holding that, so the token is used during the request and discarded;
 * what survives is the *conclusion* plus the evidence for it.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_github_id', columns: ['github_id'])]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    /** GitHub's numeric id, which survives a rename; the login does not. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $githubId;

    #[ORM\Column(length: 128)]
    private string $login;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatarUrl = null;

    /**
     * Set by hand for the few people who moderate. Deliberately not derivable from
     * anything GitHub says — being popular on GitHub does not make someone a
     * moderator here.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $moderator = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastLoginAt;

    public function __construct(int $githubId, string $login)
    {
        $this->githubId = $githubId;
        $this->login = $login;
        $this->createdAt = new \DateTimeImmutable();
        $this->lastLoginAt = new \DateTimeImmutable();
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->githubId;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];

        if ($this->moderator) {
            $roles[] = 'ROLE_MODERATOR';
        }

        return $roles;
    }

    public function eraseCredentials(): void
    {
        // Nothing to erase: no credential is ever stored on this object.
    }

    public function recordLogin(string $login, ?string $avatarUrl): void
    {
        // GitHub logins can be changed and reused, so the display name is refreshed
        // from the identity provider on each sign-in rather than trusted from the
        // first one.
        $this->login = $login;
        $this->avatarUrl = $avatarUrl;
        $this->lastLoginAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGithubId(): int
    {
        return $this->githubId;
    }

    public function getLogin(): string
    {
        return $this->login;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function isModerator(): bool
    {
        return $this->moderator;
    }

    public function setModerator(bool $moderator): void
    {
        $this->moderator = $moderator;
    }

    public function getLastLoginAt(): \DateTimeImmutable
    {
        return $this->lastLoginAt;
    }
}
