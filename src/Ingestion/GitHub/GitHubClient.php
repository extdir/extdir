<?php

declare(strict_types=1);

namespace App\Ingestion\GitHub;

use App\Ingestion\GitHub\Repository\GitHubTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Authenticated access to the GitHub API for enrichment.
 *
 * Holds the token lifecycle so callers never think about it: an access token lasts
 * eight hours, a crawl can outlive that, and the refresh must be transparent to the
 * worker halfway through a run.
 */
final class GitHubClient
{
    public function __construct(
        #[Autowire(service: 'github.api')]
        private readonly HttpClientInterface $api,
        #[Autowire(service: 'github.oauth')]
        private readonly HttpClientInterface $oauth,
        private readonly GitHubTokenRepository $tokens,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(GITHUB_APP_CLIENT_ID)%')]
        private readonly string $clientId,
        #[Autowire('%env(GITHUB_APP_CLIENT_SECRET)%')]
        private readonly string $clientSecret,
    ) {
    }

    public function isAuthorised(): bool
    {
        return null !== $this->tokens->findCurrent();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function repository(string $fullName): ?array
    {
        return $this->get('repos/'.$fullName);
    }

    public function authenticatedLogin(): ?string
    {
        $login = $this->get('user')['login'] ?? null;

        return \is_string($login) ? $login : null;
    }

    public function rateLimit(): ?int
    {
        $limit = $this->get('rate_limit')['rate']['limit'] ?? null;

        return \is_int($limit) ? $limit : null;
    }

    /**
     * Null means the request failed; an empty array means GitHub answered with one.
     * The distinction matters for the canary check in app:github:authorize, where
     * "could not read" and "read nothing" lead to opposite conclusions.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $path): ?array
    {
        $token = $this->currentAccessToken();
        if (null === $token) {
            return null;
        }

        try {
            $response = $this->api->request('GET', $path, [
                'headers' => ['Authorization' => 'Bearer '.$token],
            ]);

            if (200 !== $response->getStatusCode()) {
                $this->logger->warning('GitHub request failed', [
                    'path' => $path,
                    'status' => $response->getStatusCode(),
                ]);

                return null;
            }

            /** @var array<string, mixed> $data */
            $data = $response->toArray(false);

            return $data;
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning('GitHub request errored', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Runs a GraphQL query and returns the `data` block.
     *
     * GraphQL rather than REST for enrichment, per the discovery plan. The difference is
     * not cosmetic: REST needs roughly four calls per repository (repo, commits,
     * open issues, closed issues), so 422 extensions would cost ~1,700 of the
     * 5,000 hourly requests. One GraphQL query carrying 25 aliased repositories
     * costs a single request, which turns the whole corpus into about 17.
     *
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed>|null
     */
    public function graphql(string $query, array $variables = []): ?array
    {
        $token = $this->currentAccessToken();
        if (null === $token) {
            return null;
        }

        try {
            $response = $this->api->request('POST', 'graphql', [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'json' => ['query' => $query, 'variables' => $variables],
            ]);

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('GitHub GraphQL request failed', ['error' => $e->getMessage()]);

            return null;
        }

        // GraphQL answers 200 with an errors block rather than an HTTP error code,
        // so a naive status check would treat a failed query as a successful one
        // and silently write empty signals over good data.
        if (isset($payload['errors']) && \is_array($payload['errors'])) {
            $messages = array_map(
                static fn (mixed $e): string => \is_array($e) && \is_string($e['message'] ?? null)
                    ? $e['message']
                    : 'unknown',
                $payload['errors'],
            );

            // A repository that no longer resolves is routine, not a fault. This
            // corpus spans a decade: vendors rename, projects go private, accounts
            // close, and Packagist keeps serving the old path either way. Seven of
            // the 423 indexed extensions are in that state right now.
            //
            // Logging that at error level every night is worse than not logging it.
            // It buries genuine failures — an expired token, a rate limit, a broken
            // query — under noise, and once an alert fires on every run people stop
            // reading it. Separated so the two can be told apart at a glance and
            // alerted on differently.
            $gone = array_values(array_filter(
                $messages,
                static fn (string $m): bool => str_contains($m, 'Could not resolve to a Repository'),
            ));
            $realFailures = array_values(array_diff($messages, $gone));

            if ([] !== $gone) {
                $this->logger->warning('GitHub no longer resolves some repositories', [
                    'count' => \count($gone),
                    'repositories' => $gone,
                ]);
            }

            if ([] !== $realFailures) {
                $this->logger->error('GitHub GraphQL returned errors', ['errors' => $realFailures]);
            }
        }

        $data = $payload['data'] ?? null;

        return \is_array($data) ? $data : null;
    }

    /**
     * A usable access token, refreshed first if it is close to expiring.
     */
    private function currentAccessToken(): ?string
    {
        $token = $this->tokens->findCurrent();

        if (null === $token) {
            $this->logger->error('GitHub is not authorised. Run app:github:authorize.');

            return null;
        }

        if (!$token->isExpired()) {
            return $token->getAccessToken();
        }

        if (!$token->canRefresh()) {
            $this->logger->error('The GitHub token expired and cannot be refreshed. Re-run app:github:authorize.');

            return null;
        }

        return $this->refresh($token->getRefreshToken()) ?? null;
    }

    /**
     * Exchanges a refresh token for a new pair.
     *
     * Unlike the device-code request, this one does require the client secret.
     */
    private function refresh(?string $refreshToken): ?string
    {
        if (null === $refreshToken || '' === $this->clientSecret) {
            $this->logger->error(
                'Cannot refresh the GitHub token: GITHUB_APP_CLIENT_SECRET is not set. '
                .'Generate a client secret on the App settings page.',
            );

            return null;
        }

        try {
            $response = $this->oauth->request('POST', 'login/oauth/access_token', [
                'body' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ],
            ]);

            /** @var array<string, mixed> $data */
            $data = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('GitHub token refresh failed', ['error' => $e->getMessage()]);

            return null;
        }

        $accessToken = $data['access_token'] ?? null;
        if (!\is_string($accessToken) || '' === $accessToken) {
            $this->logger->error('GitHub token refresh returned no token', [
                'error' => $data['error'] ?? 'unknown',
            ]);

            return null;
        }

        $token = $this->tokens->findCurrent();
        $token?->update(
            $accessToken,
            isset($data['refresh_token']) ? (string) $data['refresh_token'] : $refreshToken,
            $this->expiryFrom($data['expires_in'] ?? null),
            $this->expiryFrom($data['refresh_token_expires_in'] ?? null),
        );
        $this->em->flush();

        return $accessToken;
    }

    private function expiryFrom(mixed $seconds): ?\DateTimeImmutable
    {
        return is_numeric($seconds)
            ? new \DateTimeImmutable(\sprintf('+%d seconds', (int) $seconds))
            : null;
    }
}
