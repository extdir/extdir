<?php

declare(strict_types=1);

namespace App\Ingestion\GitHub;

use App\Ingestion\GitHub\Entity\GitHubToken;
use App\Ingestion\GitHub\Repository\GitHubTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Obtains a GitHub user access token through the OAuth device flow.
 *
 * The device flow is chosen over the standard web flow for a specific reason: the
 * crawler is a background process, not a web session. The web flow needs a browser
 * redirect into a running callback endpoint, which means the app must be deployed
 * and reachable before it can crawl anything. The device flow needs neither — it
 * runs from the console, the operator authorises in whatever browser they have
 * open, and the resulting token is stored for the workers.
 *
 * Requires "Enable Device Flow" on the GitHub App settings page. It is off by
 * default, and GitHub returns `device_flow_disabled` if it has not been enabled.
 */
final class DeviceFlowAuthenticator
{
    private const DEVICE_CODE_PATH = 'login/device/code';
    private const ACCESS_TOKEN_PATH = 'login/oauth/access_token';
    private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:device_code';

    public function __construct(
        #[Autowire(service: 'github.oauth')]
        private readonly HttpClientInterface $oauth,
        private readonly GitHubTokenRepository $tokens,
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(GITHUB_APP_CLIENT_ID)%')]
        private readonly string $clientId,
    ) {
    }

    /**
     * Step one: ask GitHub for a code the operator types into their browser.
     */
    public function requestDeviceCode(): DeviceCode
    {
        if ('' === $this->clientId) {
            throw new \RuntimeException('GITHUB_APP_CLIENT_ID is not set. Copy the Client ID from the GitHub App settings page into .env.local.');
        }

        $response = $this->oauth->request('POST', self::DEVICE_CODE_PATH, [
            'body' => ['client_id' => $this->clientId],
        ]);

        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);

        if (isset($data['error'])) {
            throw new \RuntimeException($this->explain((string) $data['error']));
        }

        return new DeviceCode(
            deviceCode: (string) ($data['device_code'] ?? ''),
            userCode: (string) ($data['user_code'] ?? ''),
            verificationUri: (string) ($data['verification_uri'] ?? 'https://github.com/login/device'),
            interval: (int) ($data['interval'] ?? 5),
            expiresIn: (int) ($data['expires_in'] ?? 900),
        );
    }

    /**
     * Step two: poll until the operator finishes authorising in the browser.
     *
     * GitHub's documented polling contract is specific and worth honouring exactly:
     * `authorization_pending` simply means "keep waiting", while `slow_down` means
     * we polled too fast and must add five seconds to the interval — ignoring it
     * escalates to being rate limited out of the flow entirely.
     *
     * @param callable(string):void|null $onWaiting called on each pending poll
     */
    public function pollForToken(DeviceCode $code, ?callable $onWaiting = null): GitHubToken
    {
        $interval = $code->interval;
        $deadline = time() + $code->expiresIn;

        while (time() < $deadline) {
            sleep($interval);

            $response = $this->oauth->request('POST', self::ACCESS_TOKEN_PATH, [
                'body' => [
                    'client_id' => $this->clientId,
                    'device_code' => $code->deviceCode,
                    'grant_type' => self::GRANT_TYPE,
                ],
            ]);

            /** @var array<string, mixed> $data */
            $data = $response->toArray(false);
            $error = isset($data['error']) ? (string) $data['error'] : null;

            if ('authorization_pending' === $error) {
                if (null !== $onWaiting) {
                    $onWaiting('waiting for authorisation');
                }

                continue;
            }

            if ('slow_down' === $error) {
                $interval += 5;
                if (null !== $onWaiting) {
                    $onWaiting(\sprintf('polling too fast, backing off to %ds', $interval));
                }

                continue;
            }

            if (null !== $error) {
                throw new \RuntimeException($this->explain($error));
            }

            return $this->store($data);
        }

        throw new \RuntimeException('The device code expired before it was authorised.');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function store(array $data): GitHubToken
    {
        $accessToken = (string) ($data['access_token'] ?? '');
        if ('' === $accessToken) {
            throw new \RuntimeException('GitHub returned no access token.');
        }

        $token = $this->tokens->findCurrent();
        if (null === $token) {
            $token = new GitHubToken($accessToken);
            $this->em->persist($token);
        }

        $token->update(
            $accessToken,
            isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            $this->expiryFrom($data['expires_in'] ?? null),
            $this->expiryFrom($data['refresh_token_expires_in'] ?? null),
        );

        $this->em->flush();

        return $token;
    }

    private function expiryFrom(mixed $seconds): ?\DateTimeImmutable
    {
        if (!is_numeric($seconds)) {
            return null;
        }

        return new \DateTimeImmutable(\sprintf('+%d seconds', (int) $seconds));
    }

    /**
     * GitHub's error codes are terse and the fix is rarely obvious from them.
     */
    private function explain(string $error): string
    {
        return match ($error) {
            'device_flow_disabled' => 'Device flow is not enabled for this GitHub App. '
                .'Tick "Enable Device Flow" on the App settings page and try again.',
            'incorrect_client_credentials' => 'GITHUB_APP_CLIENT_ID is wrong. It looks like '
                .'"Iv23li..." and is shown on the App settings page — note that it is not the App ID.',
            'expired_token' => 'The device code expired before it was authorised. Run the command again.',
            'access_denied' => 'Authorisation was declined in the browser.',
            'unsupported_grant_type' => 'GitHub rejected the grant type; the app registration may be an '
                .'OAuth App rather than a GitHub App.',
            default => \sprintf('GitHub returned "%s".', $error),
        };
    }
}
