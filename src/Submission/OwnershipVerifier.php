<?php

declare(strict_types=1);

namespace App\Submission;

use App\Catalog\Entity\Extension;
use App\Signals\RepositoryEnricher;
use App\Submission\Entity\ModerationAction;
use App\Submission\Entity\OwnershipClaim;
use App\Submission\Entity\User;
use App\Submission\Enum\ModerationActionType;
use App\Submission\Enum\VerificationMethod;
use App\Submission\Repository\OwnershipClaimRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Decides whether a signed-in person controls an extension's repository.
 *
 * The question is deliberately not "who are you?" but "can you write to this
 * repository?" — because the second has an external, checkable answer and the
 * first would require us to adjudicate identity. GitHub already knows who has
 * push access; asking it is both more reliable than anything we could build and
 * impossible to quietly bend.
 *
 * That property is what the conflict-of-interest rule is really asking for. A rule with no
 * judgement in it cannot acquire an exception for the maintainer's own vendor,
 * and OwnershipVerifierTest asserts that no vendor name appears in this class or
 * anywhere on the verification path.
 *
 * The user's access token is used for exactly one request and never stored. It can
 * push to every repository that person can push to, and a directory has no reason
 * to hold that.
 */
final class OwnershipVerifier
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly OwnershipClaimRepository $claims,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Verifies against GitHub using the signed-in user's own token.
     *
     * @param string $userAccessToken used for this request only, never persisted
     */
    public function verifyWithGitHub(User $user, Extension $extension, string $userAccessToken): VerificationResult
    {
        $repo = RepositoryEnricher::parseRepository($extension->getRepositoryUrl());

        if (null === $repo) {
            return VerificationResult::unavailable(
                'This extension is not hosted on GitHub, so write access cannot be checked automatically. '
                .'Use the verification file instead.',
            );
        }

        try {
            $response = $this->http->request('GET', \sprintf('https://api.github.com/repos/%s/%s', $repo[0], $repo[1]), [
                'headers' => [
                    'Authorization' => 'Bearer '.$userAccessToken,
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
                'timeout' => 10,
            ]);

            if (200 !== $response->getStatusCode()) {
                return VerificationResult::denied('GitHub did not recognise that repository for your account.');
            }

            /** @var array<string, mixed> $data */
            $data = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            // A transport failure is not a denial. Saying "you do not own this"
            // when GitHub simply timed out would be both wrong and insulting.
            return VerificationResult::unavailable('GitHub could not be reached: '.$e->getMessage());
        }

        $permissions = \is_array($data['permissions'] ?? null) ? $data['permissions'] : [];
        $admin = true === ($permissions['admin'] ?? false);
        $push = true === ($permissions['push'] ?? false);

        // Read access proves nothing — every public repository grants it to
        // everyone. Only write access distinguishes a maintainer from a visitor.
        if (!$admin && !$push) {
            return VerificationResult::denied(
                'Your GitHub account does not have write access to that repository.',
            );
        }

        $claim = $this->record(
            $user,
            $extension,
            VerificationMethod::GitHubPermission,
            \sprintf(
                'GitHub reported %s permission for %s on %s/%s.',
                $admin ? 'admin' : 'push',
                $user->getLogin(),
                $repo[0],
                $repo[1],
            ),
        );

        return VerificationResult::verified($claim);
    }

    /**
     * Records or refreshes a claim, with an audit entry.
     *
     * Re-verification updates the existing claim rather than adding another, so
     * the claim table answers "does this hold now" while the moderation log keeps
     * the history of when it was established.
     */
    public function record(
        User $user,
        Extension $extension,
        VerificationMethod $method,
        string $evidence,
    ): OwnershipClaim {
        $claim = $this->claims->findFor($user, $extension);

        if (null === $claim) {
            $claim = new OwnershipClaim($user, $extension, $method, $evidence);
            $this->em->persist($claim);
        } else {
            $claim->reconfirm($method, $evidence);
        }

        $this->em->persist(new ModerationAction(
            $extension,
            ModerationActionType::OwnershipVerified,
            $evidence,
            $user,
        ));

        $this->em->flush();

        return $claim;
    }

    /**
     * Whether this user may act on this extension right now.
     *
     * Moderators are included because someone has to be able to act on a rights
     * complaint from a person who is not the maintainer — but they are included by
     * role, which is recorded in the log like everything else.
     */
    public function mayActOn(User $user, Extension $extension): bool
    {
        if ($user->isModerator()) {
            return true;
        }

        return $this->claims->findFor($user, $extension)?->isActive() ?? false;
    }
}
