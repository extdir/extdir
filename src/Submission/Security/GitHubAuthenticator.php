<?php

declare(strict_types=1);

namespace App\Submission\Security;

use App\Submission\Entity\User;
use App\Submission\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Signs a maintainer in with GitHub.
 *
 * Standard OAuth authorisation-code flow, hand-rolled rather than pulled from a
 * bundle because it is about sixty lines and this is the one place where knowing
 * exactly what happens to a user's token is worth more than the convenience.
 *
 * The token is used twice — once to learn who the user is, once to check write
 * access to the repository they are claiming — and then dropped. It is never
 * written to the database or the session. A token that can push to every
 * repository its owner can push to is not something a directory should be storing,
 * and the only way to be sure it never leaks is to never keep it.
 */
final class GitHubAuthenticator extends AbstractAuthenticator
{
    public const PENDING_EXTENSION_KEY = 'extdir_pending_verification';
    public const STATE_KEY = 'extdir_oauth_state';
    public const ACCESS_TOKEN_KEY = 'extdir_github_access_token';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(GITHUB_APP_CLIENT_ID)%')]
        private readonly string $clientId,
        #[Autowire('%env(GITHUB_APP_CLIENT_SECRET)%')]
        private readonly string $clientSecret,
    ) {
    }

    public function supports(Request $request): bool
    {
        return 'auth_github_callback' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $session = $request->getSession();
        $expectedState = $session->get(self::STATE_KEY);
        $session->remove(self::STATE_KEY);

        // CSRF protection for the OAuth handshake. Without it, an attacker can
        // complete a login in a victim's browser using their own code and get the
        // victim's session bound to the attacker's GitHub account.
        $state = $request->query->get('state');
        if (!\is_string($state) || !\is_string($expectedState) || !hash_equals($expectedState, $state)) {
            throw new CustomUserMessageAuthenticationException('The sign-in request expired. Please try again.');
        }

        $code = $request->query->get('code');
        if (!\is_string($code) || '' === $code) {
            throw new CustomUserMessageAuthenticationException('GitHub did not return an authorisation code.');
        }

        $accessToken = $this->exchangeCodeForToken($code);
        $profile = $this->fetchProfile($accessToken);

        // Held only for the verification call that follows immediately, and
        // cleared by the controller the moment it is done with.
        $session->set(self::ACCESS_TOKEN_KEY, $accessToken);

        $githubId = (int) $profile['id'];
        $login = (string) $profile['login'];
        $avatar = \is_string($profile['avatar_url'] ?? null) ? $profile['avatar_url'] : null;

        return new SelfValidatingPassport(
            new UserBadge((string) $githubId, function () use ($githubId, $login, $avatar): User {
                $user = $this->users->findByGithubId($githubId);

                if (null === $user) {
                    $user = new User($githubId, $login);
                    $this->em->persist($user);
                }

                $user->recordLogin($login, $avatar);
                $this->em->flush();

                return $user;
            }),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        // Straight back to whatever the user was trying to do, which is almost
        // always verifying one specific extension.
        $pending = $request->getSession()->get(self::PENDING_EXTENSION_KEY);

        if (\is_string($pending)) {
            return new RedirectResponse($this->urls->generate('ownership_verify', ['slug' => $pending]));
        }

        return new RedirectResponse($this->urls->generate('my_extensions'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $this->logger->warning('GitHub sign-in failed', ['error' => $exception->getMessage()]);

        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $exception->getMessageKey());
        }

        return new RedirectResponse($this->urls->generate('home'));
    }

    private function exchangeCodeForToken(string $code): string
    {
        $response = $this->http->request('POST', 'https://github.com/login/oauth/access_token', [
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $code,
            ],
            'timeout' => 10,
        ]);

        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);
        $token = $data['access_token'] ?? null;

        if (!\is_string($token) || '' === $token) {
            throw new CustomUserMessageAuthenticationException('GitHub declined the sign-in.');
        }

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchProfile(string $accessToken): array
    {
        $response = $this->http->request('GET', 'https://api.github.com/user', [
            'headers' => [
                'Authorization' => 'Bearer '.$accessToken,
                'Accept' => 'application/vnd.github+json',
            ],
            'timeout' => 10,
        ]);

        /** @var array<string, mixed> $profile */
        $profile = $response->toArray(false);

        if (!isset($profile['id'], $profile['login'])) {
            throw new CustomUserMessageAuthenticationException('GitHub returned an unusable profile.');
        }

        return $profile;
    }
}
