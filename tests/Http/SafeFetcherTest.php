<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\HostResolver;
use App\Http\SafeFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The URL being fetched comes from Packagist metadata, which anyone can publish.
 * These tests are the reason the fetcher exists at all: without them a package
 * pointing at a metadata endpoint would turn a maintainer pressing "check now" into
 * a request issued from inside the hosting account.
 *
 * A refusal must happen before any request leaves. The MockHttpClient here is given
 * no responses at all, so if the fetcher ever tried to send one the test would fail
 * with "no more responses" rather than passing quietly.
 *
 * The resolver is stubbed rather than real, so every dangerous range is exercised
 * without a network and the suite does not depend on what public DNS happens to say
 * today.
 */
final class SafeFetcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedUrls(): iterable
    {
        yield 'plain http is never fetched' => ['http://example.com/file.txt'];
        yield 'loopback by name' => ['https://localhost/file.txt'];
        yield 'loopback literal' => ['https://127.0.0.1/file.txt'];
        yield 'loopback in another guise' => ['https://127.9.9.9/file.txt'];
        yield 'IPv6 loopback' => ['https://[::1]/file.txt'];
        yield 'private class A' => ['https://10.0.0.5/file.txt'];
        yield 'private class B' => ['https://172.16.4.1/file.txt'];
        yield 'private class C' => ['https://192.168.1.1/file.txt'];
        yield 'cloud metadata endpoint' => ['https://169.254.169.254/latest/meta-data/'];
        yield 'carrier-grade NAT' => ['https://100.64.0.1/file.txt'];
        yield 'IPv6 unique local' => ['https://[fd00::1]/file.txt'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'no host at all' => ['https:///file.txt'];
        yield 'not a url' => ['certainly not a url'];
    }

    #[DataProvider('refusedUrls')]
    public function testDangerousUrlsAreRefusedWithoutSendingARequest(string $url): void
    {
        $client = new MockHttpClient([]);
        // localhost is the one name in the list, and it must be refused on the
        // address it resolves to rather than on the string being recognisable.
        $fetcher = new SafeFetcher($client, self::resolvingTo(['127.0.0.1']), new NullLogger());

        self::assertNull($fetcher->fetch($url));
        self::assertSame(0, $client->getRequestsCount(), 'A refused URL must not reach the network.');
    }

    public function testAHostThatDoesNotResolveIsRefused(): void
    {
        $client = new MockHttpClient([]);
        $fetcher = new SafeFetcher($client, self::resolvingTo([]), new NullLogger());

        self::assertNull($fetcher->fetch('https://nx.example/file.txt'));
        self::assertSame(0, $client->getRequestsCount());
    }

    /**
     * A name answering with one public and one private address is not a misconfigured
     * host to be worked around, it is the shape of an attack, so the whole name is
     * refused rather than the good address being picked out.
     */
    public function testAHostResolvingToBothPublicAndPrivateIsRefusedEntirely(): void
    {
        $client = new MockHttpClient([]);
        $fetcher = new SafeFetcher($client, self::resolvingTo(['93.184.216.34', '10.0.0.1']), new NullLogger());

        self::assertNull($fetcher->fetch('https://split.example/file.txt'));
        self::assertSame(0, $client->getRequestsCount());
    }

    /**
     * The address checked must be the address connected to, or the name is free to
     * answer publicly for the check and privately a moment later.
     */
    public function testTheResolvedAddressIsPinnedForTheRequest(): void
    {
        $client = new MockHttpClient([static function (string $method, string $url, array $options): MockResponse {
            self::assertSame(
                ['example.test' => '93.184.216.34'],
                $options['resolve'] ?? null,
                'The request must be pinned to the address that was screened.',
            );
            self::assertSame(0, $options['max_redirects'] ?? null);

            return new MockResponse('ok');
        }]);

        $fetcher = new SafeFetcher($client, self::resolvingTo(['93.184.216.34']), new NullLogger());

        self::assertSame('ok', $fetcher->fetch('https://example.test/file.txt'));
    }

    /**
     * @param list<string> $addresses
     */
    private static function resolvingTo(array $addresses): HostResolver
    {
        return new class($addresses) implements HostResolver {
            /** @param list<string> $addresses */
            public function __construct(private readonly array $addresses)
            {
            }

            public function resolve(string $host): array
            {
                return $this->addresses;
            }
        };
    }

    public function testAPublicUrlIsFetched(): void
    {
        $client = new MockHttpClient([new MockResponse('extdir-ownership-verification')]);
        $fetcher = new SafeFetcher($client, self::resolvingTo(['93.184.216.34']), new NullLogger());

        self::assertSame('extdir-ownership-verification', $fetcher->fetch('https://bitbucket.org/acme/x/raw/main/f.txt'));
    }

    public function testANonOkStatusIsTreatedAsAbsent(): void
    {
        $client = new MockHttpClient([new MockResponse('nope', ['http_code' => 404])]);
        $fetcher = new SafeFetcher($client, self::resolvingTo(['93.184.216.34']), new NullLogger());

        self::assertNull($fetcher->fetch('https://bitbucket.org/acme/x/raw/main/f.txt'));
    }

    /**
     * A hostile server can answer with as much data as it likes. The proof file is
     * under a hundred bytes, so reading until the stream ends would be a gift.
     */
    public function testAnOversizedResponseIsCapped(): void
    {
        $client = new MockHttpClient([new MockResponse(str_repeat('a', 5_000_000))]);
        $fetcher = new SafeFetcher($client, self::resolvingTo(['93.184.216.34']), new NullLogger());

        $body = $fetcher->fetch('https://bitbucket.org/acme/x/raw/main/f.txt');

        self::assertNotNull($body);
        self::assertLessThanOrEqual(8192, \strlen($body));
    }

    /**
     * Following a redirect would undo every check above: a public host is allowed to
     * answer 302 and point anywhere it likes, including inside the network.
     */
    public function testRedirectsAreNotFollowed(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://169.254.169.254/']]),
        ]);
        $fetcher = new SafeFetcher($client, self::resolvingTo(['93.184.216.34']), new NullLogger());

        self::assertNull($fetcher->fetch('https://bitbucket.org/acme/x/raw/main/f.txt'));
        self::assertSame(1, $client->getRequestsCount());
    }
}
