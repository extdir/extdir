<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The submission form's guards.
 *
 * Everything asserted here is checked *before* GitHub is contacted, which is what
 * makes the tests runnable without network: a bad URL, a missing token or an exhausted
 * limiter must all be refused on our side. The GitHub-dependent half, assembling a
 * real repository, is covered by the command, which does have an API token.
 */
final class SubmissionTest extends WebTestCase
{
    public function testTheFormIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/submit');

        self::assertResponseIsSuccessful();
    }

    /**
     * Submitting is not claiming. The page has to say so, because the two are easy to
     * confuse and only one of them carries a rights assertion.
     */
    public function testTheFormSeparatesSubmissionFromOwnership(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/submit');

        $text = $crawler->filter('.prose')->text();

        self::assertStringContainsString('not a claim of ownership', strtolower($text));
        self::assertStringContainsString('shopware-platform-plugin', $text);
    }

    /**
     * An expired token must not bounce an anonymous visitor into a GitHub sign-in.
     * That is what Symfony does with an AccessDeniedException when nobody is logged
     * in, and it would contradict the page's own promise that no account is needed.
     */
    public function testAnExpiredTokenAsksThemToTryAgainRatherThanToLogIn(): void
    {
        $client = static::createClient();
        $client->request('POST', '/submit', ['url' => 'https://github.com/acme/widget']);

        self::assertResponseRedirects('/submit');

        $crawler = $client->followRedirect();
        self::assertStringContainsString('had expired', $crawler->filter('body')->text());
    }

    /**
     * Rejected before any request leaves the server, so these run offline.
     */
    #[DataProvider('unusableUrls')]
    public function testUnusableAddressesAreRefusedWithAnExplanation(string $url): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/submit');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        // Its own IP with a reset budget, following the convention the imprint tests
        // set: the limiter's storage outlives a single run, so sharing the default
        // client address makes a later assertion fail on a 429 it never asked about.
        $ip = self::withFreshLimiterBudget('198.51.100.21');

        $client->request('POST', '/submit', ['url' => $url, '_token' => $token], [], ['REMOTE_ADDR' => $ip]);

        self::assertResponseRedirects('/submit');

        $crawler = $client->followRedirect();
        self::assertStringContainsString('GitHub repository address', $crawler->filter('body')->text());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'not a url' => ['nonsense'];
        yield 'gitlab' => ['https://gitlab.com/acme/widget'];
        yield 'bitbucket' => ['https://bitbucket.org/acme/widget'];
        yield 'gitea' => ['https://codeberg.org/acme/widget'];
        yield 'github but no repository' => ['https://github.com/acme'];
        yield 'github root' => ['https://github.com/'];

        // Not a forge at all. The point is that nothing is fetched from it.
        yield 'someone elses host' => ['https://example.com/acme/widget'];
        yield 'file scheme' => ['file:///etc/passwd'];
    }

    /**
     * The ceiling exists because each accepted submission spends GitHub requests and
     * can write to the catalogue.
     */
    public function testTooManySubmissionsFromOneNetworkAreRefused(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/submit');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $ip = self::withFreshLimiterBudget('198.51.100.22');

        $limiter = static::getContainer()->get('limiter.submission');
        while ($limiter->create($ip)->consume(1)->isAccepted()) {
        }

        $client->request('POST', '/submit', ['url' => 'https://github.com/acme/widget', '_token' => $token], [], ['REMOTE_ADDR' => $ip]);

        self::assertResponseRedirects('/submit');
        self::assertStringContainsString('Too many submissions', $client->followRedirect()->filter('body')->text());
    }

    private static function withFreshLimiterBudget(string $ip): string
    {
        static::getContainer()->get('limiter.submission')->create($ip)->reset();

        return $ip;
    }

    /**
     * The footer link is how anyone finds this page at all.
     */
    public function testTheFormIsReachableFromEveryPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertGreaterThan(
            0,
            $crawler->filter('footer a[href="/submit"]')->count(),
            'The submission page is not linked from the footer.'
        );
    }
}
