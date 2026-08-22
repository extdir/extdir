<?php

declare(strict_types=1);

namespace App\Tests\Signals;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Http\HostResolver;
use App\Http\SafeFetcher;
use App\Signals\Forge\BitbucketClient;
use App\Signals\Forge\GiteaClient;
use App\Signals\Forge\GitLabClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The forge clients exist to close 42 rows that showed no maintenance signal at all.
 * What matters is that they read the right field from each API, refuse to invent the
 * fields a forge does not publish, and treat an unreachable instance as unknown
 * rather than as inactive, because "we could not look" and "nobody has touched this
 * in three years" would rank very differently and mean opposite things.
 */
final class ForgeClientTest extends TestCase
{
    public function testGitLabReadsActivityAndCounts(): void
    {
        [$client, $http] = $this->gitlab('{"last_activity_at":"2026-07-01T09:00:00.000Z","star_count":42,"forks_count":7,"archived":false}');

        $signals = $client->fetch($this->extension('https://gitlab.com/acme/plugin'));

        self::assertNotNull($signals);
        self::assertSame('2026-07-01', $signals->lastActivityAt?->format('Y-m-d'));
        self::assertSame(42, $signals->stars);
        self::assertSame(7, $signals->forks);
        self::assertFalse($signals->archived);
        self::assertSame(1, $http->getRequestsCount());
    }

    /**
     * GitLab groups nest arbitrarily deep, and a third of the GitLab extensions here
     * are on self-hosted instances. Splitting the path on the first slash builds a
     * URL for a project that does not exist.
     */
    public function testGitLabEncodesNestedGroupsOnSelfHostedInstances(): void
    {
        $seen = null;
        $http = new MockHttpClient(function (string $method, string $url) use (&$seen): MockResponse {
            $seen = $url;

            return new MockResponse('{"last_activity_at":"2026-01-01T00:00:00Z"}');
        });

        $client = new GitLabClient(new SafeFetcher($http, $this->resolver(), new NullLogger()));
        $client->fetch($this->extension('https://gitlab.jonathan-martz.de/fyrst/shopware/OrderStates'));

        self::assertIsString($seen);
        self::assertStringContainsString('gitlab.jonathan-martz.de/api/v4/projects/', $seen);
        self::assertStringContainsString('fyrst%2Fshopware%2FOrderStates', $seen);
    }

    /**
     * Bitbucket counts watchers, not stars. Putting that number in the same column as
     * a GitHub star count would invite a comparison between two different
     * measurements, so it stays absent.
     */
    public function testBitbucketReportsActivityButNeverInventsStars(): void
    {
        $http = new MockHttpClient([new MockResponse('{"updated_on":"2025-06-14T11:22:33+00:00","watchers":190}')]);
        $client = new BitbucketClient(new SafeFetcher($http, $this->resolver(), new NullLogger()));

        $signals = $client->fetch($this->extension('https://bitbucket.org/acme/plugin'));

        self::assertNotNull($signals);
        self::assertSame('2025-06-14', $signals->lastActivityAt?->format('Y-m-d'));
        self::assertNull($signals->stars, 'Watchers are not stars.');
        self::assertNull($signals->forks);
    }

    public function testTheHostClassificationTheClientsRelyOn(): void
    {
        self::assertSame('gitlab', $this->extension('https://gitlab.jonathan-martz.de/a/b')->getSourceHost()->value);
        self::assertSame('gitea', $this->extension('https://codeberg.org/a/b')->getSourceHost()->value);
        // Bitbucket has no case of its own, which is why BitbucketClient matches on
        // the hostname rather than on the enum.
        self::assertSame('other', $this->extension('https://bitbucket.org/a/b')->getSourceHost()->value);
    }

    public function testBitbucketDoesNotClaimUnrelatedForges(): void
    {
        $client = new BitbucketClient(new SafeFetcher(new MockHttpClient([]), $this->resolver(), new NullLogger()));

        self::assertTrue($client->supports($this->extension('https://bitbucket.org/a/b')));
        self::assertFalse($client->supports($this->extension('https://git.schubwerk.com/a/b')));
        self::assertFalse($client->supports($this->extension('https://gitlab.com/a/b')));
    }

    public function testGiteaReadsCodeberg(): void
    {
        $http = new MockHttpClient([new MockResponse('{"updated_at":"2026-03-03T00:00:00Z","stars_count":12,"forks_count":3,"archived":true}')]);
        $client = new GiteaClient(new SafeFetcher($http, $this->resolver(), new NullLogger()));

        $signals = $client->fetch($this->extension('https://codeberg.org/acme/plugin'));

        self::assertNotNull($signals);
        self::assertSame(12, $signals->stars);
        self::assertTrue($signals->archived);
    }

    /**
     * The distinction that matters most. An instance being down must not be recorded
     * as "no activity", because the maintenance score would then mark a healthy
     * extension abandoned on the strength of a network timeout.
     */
    public function testAnUnreachableInstanceYieldsNothingRatherThanZero(): void
    {
        $http = new MockHttpClient([new MockResponse('', ['http_code' => 502])]);
        $client = new GitLabClient(new SafeFetcher($http, $this->resolver(), new NullLogger()));

        self::assertNull($client->fetch($this->extension('https://gitlab.example/acme/plugin')));
    }

    public function testNonsenseJsonIsTreatedAsUnavailable(): void
    {
        $http = new MockHttpClient([new MockResponse('<html>login required</html>')]);
        $client = new GitLabClient(new SafeFetcher($http, $this->resolver(), new NullLogger()));

        self::assertNull($client->fetch($this->extension('https://gitlab.example/acme/plugin')));
    }

    public function testAMissingRepositoryUrlIsNotAnError(): void
    {
        $client = new GitLabClient(new SafeFetcher(new MockHttpClient([]), $this->resolver(), new NullLogger()));

        self::assertNull($client->fetch($this->extension(null)));
    }

    /**
     * @return array{GitLabClient, MockHttpClient}
     */
    private function gitlab(string $body): array
    {
        $http = new MockHttpClient([new MockResponse($body)]);

        return [new GitLabClient(new SafeFetcher($http, $this->resolver(), new NullLogger())), $http];
    }

    private function resolver(): HostResolver
    {
        return new class implements HostResolver {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        };
    }

    /**
     * The host is not passed in: setRepositoryUrl derives it, so building fixtures
     * this way exercises the real classification rather than asserting against a
     * hand-set value that production never produces.
     */
    private function extension(?string $url): Extension
    {
        $extension = new Extension(new Vendor('acme', 'acme'), 'acme/plugin', 'acme-plugin', 'Plugin');
        $extension->setRepositoryUrl($url);

        return $extension;
    }
}
