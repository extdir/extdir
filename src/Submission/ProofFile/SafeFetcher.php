<?php

declare(strict_types=1);

namespace App\Submission\ProofFile;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a small file from a URL the application does not control.
 *
 * This exists because of where the URL comes from. `repository_url` is read from
 * Packagist metadata, and anyone may publish a package with any repository field
 * they like. Without the checks below, a package pointing at
 * `http://169.254.169.254/latest/meta-data/` would turn a maintainer pressing
 * "check now" into an HTTP request issued from inside the hosting account, with the
 * pass/fail answer acting as an oracle for whatever came back. That is server-side
 * request forgery, and the on-demand nature makes it worse than the crawler's own
 * fetching: an attacker chooses the moment and reads the result.
 *
 * Every rule here is load-bearing:
 *
 * - **https only.** http invites a downgrade; file:// and friends are not network
 *   requests at all.
 * - **Address filtering.** Loopback, private, link-local, unique-local and the
 *   carrier-grade NAT range are refused, for IPv4 and IPv6 alike. The link-local
 *   range is the one that matters most: 169.254.169.254 is the cloud metadata
 *   endpoint on essentially every provider.
 * - **Pinned resolution.** The address is resolved once and then pinned for the
 *   request. Checking DNS and then letting the client resolve again independently is
 *   a rebinding hole — the name can answer with a public address for the check and a
 *   private one microseconds later.
 * - **No redirects.** A redirect is precisely how a public host launders a request
 *   into a private one, and following it would bypass every check above.
 * - **Byte cap.** The proof file is under a hundred bytes. Reading a stream until it
 *   ends invites being fed gigabytes by a hostile server.
 *
 * The caller never receives the response body for display. It gets a string it is
 * expected to test for a token and discard, which is what stops a failed check from
 * becoming a way to read arbitrary internal pages.
 */
final readonly class SafeFetcher
{
    private const int MAX_BYTES = 8192;

    public function __construct(
        private HttpClientInterface $http,
        private HostResolver $resolver,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return string|null the body, capped, or null if the URL was refused or unreachable
     */
    public function fetch(string $url): ?string
    {
        $parts = parse_url($url);

        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if ('https' !== strtolower($parts['scheme'])) {
            return null;
        }

        $host = $parts['host'];
        $address = $this->resolveToPublicAddress($host);

        if (null === $address) {
            return null;
        }

        try {
            $response = $this->http->request('GET', $url, [
                'timeout' => 5,
                'max_duration' => 10,
                'max_redirects' => 0,
                // Pinning the address the check was performed against. Without this
                // the client performs its own lookup and the name is free to answer
                // differently the second time.
                'resolve' => [$host => $address],
                'headers' => ['Accept' => 'text/plain, */*'],
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $body = '';

            foreach ($this->http->stream($response) as $chunk) {
                $body .= $chunk->getContent();

                if (\strlen($body) >= self::MAX_BYTES) {
                    // Everything needed is in the first chunk of any honest response.
                    // Cancelling releases the connection rather than politely reading
                    // out whatever a hostile server wants to send.
                    $response->cancel();
                    break;
                }
            }

            return substr($body, 0, self::MAX_BYTES);
        } catch (HttpExceptionInterface $e) {
            // A missing file, a self-hosted forge that is down, a certificate that
            // expired — all routine, none worth an error-level log for a check the
            // user is about to see the result of anyway.
            $this->logger->info('Proof-file fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Resolves a hostname to a single address that is safe to connect to.
     *
     * Returns null if the name does not resolve, or if any address it resolves to is
     * one we refuse. Rejecting on *any* bad address rather than picking a good one is
     * deliberate: a name answering with both a public and a private address is not a
     * configuration to be worked around, it is the shape of an attack.
     */
    private function resolveToPublicAddress(string $host): ?string
    {
        // A literal address skips resolution but not the filter.
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return $this->isPublic($host) ? $host : null;
        }

        $addresses = $this->resolver->resolve($host);

        if ([] === $addresses) {
            return null;
        }

        foreach ($addresses as $address) {
            if (!$this->isPublic($address)) {
                $this->logger->warning('Refused a proof-file host resolving to a non-public address', [
                    'host' => $host,
                    'address' => $address,
                ]);

                return null;
            }
        }

        return $addresses[0];
    }

    /**
     * PHP's own filter knows the private and reserved ranges, which spares us
     * hand-written CIDR tables that go stale. It does not know about carrier-grade
     * NAT, so that one is checked separately.
     */
    private function isPublic(string $address): bool
    {
        $public = filter_var(
            $address,
            \FILTER_VALIDATE_IP,
            \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE,
        );

        if (false === $public) {
            return false;
        }

        // 100.64.0.0/10. Routable in the sense that a socket will connect, but it
        // addresses the provider's own infrastructure rather than the internet.
        if (1 === preg_match('/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $address)) {
            return false;
        }

        return true;
    }
}
