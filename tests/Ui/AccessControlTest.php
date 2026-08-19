<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The directory is public; acting on it is not.
 */
final class AccessControlTest extends WebTestCase
{
    #[DataProvider('publicRoutes')]
    public function testTheDirectoryStaysReadableWithoutSigningIn(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertResponseIsSuccessful(\sprintf('%s must not require a sign-in', $path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicRoutes(): iterable
    {
        yield 'listing' => ['/'];
        yield 'about' => ['/about'];
        yield 'ranking' => ['/ranking'];
        yield 'imprint' => ['/imprint'];
        yield 'sitemap' => ['/sitemap.xml'];
        yield 'robots' => ['/robots.txt'];
        yield 'composer repository' => ['/repo/packages.json'];
    }

    /**
     * Anything that changes the index needs an identity.
     */
    public function testTheMaintainerAreaRequiresAnIdentity(): void
    {
        $client = static::createClient();
        $client->request('GET', '/my/extensions');

        self::assertContains(
            $client->getResponse()->getStatusCode(),
            [401, 302],
            'the maintainer area must not be readable anonymously',
        );
    }

    /**
     * A signed-out visitor following a "verify" link must be offered a sign-in, not
     * handed an empty 401.
     *
     * The proof-file page tells people they will be asked to sign in first, and
     * before the firewall had an entry point that promise was simply false — the
     * link produced a blank 401 body.
     */
    public function testAProtectedPageSendsAnonymousVisitorsToSignIn(): void
    {
        $client = static::createClient();
        $client->request('GET', '/my/verify-file/some-extension');

        self::assertResponseRedirects();
        self::assertStringContainsString(
            '/auth/github',
            (string) $client->getResponse()->headers->get('Location'),
        );
    }

    /**
     * Moderation is set by hand in the database and no amount of GitHub standing
     * grants it. Ownership verification must not become a route into it: a verified
     * maintainer can act on their own extension and nothing else.
     */
    public function testTheModerationQueueIsNotReachableWithoutTheRole(): void
    {
        $client = static::createClient();
        $client->request('GET', '/moderate');

        self::assertContains(
            $client->getResponse()->getStatusCode(),
            [302, 401, 403],
            'the moderation queue must not be readable anonymously',
        );
    }

    /**
     * Reporting is deliberately open. A rights holder is a lawyer or a brand owner,
     * not a GitHub user, and a login wall in front of a complaint form looks like
     * evasion of the takedown policy.
     */
    public function testReportingNeedsNoAccount(): void
    {
        $client = static::createClient();
        $client->request('GET', '/report/does-not-exist');

        // 404 because the extension does not exist, not 401/403 — the route itself
        // is public.
        self::assertResponseStatusCodeSame(404);
    }

    public function testModerationActionsCannotHappenOverGet(): void
    {
        $client = static::createClient();
        $client->request('GET', '/moderate/some-extension/delist');

        self::assertContains($client->getResponse()->getStatusCode(), [302, 401, 403, 405]);
    }

    /**
     * Delisting is the destructive action in this application, so it is POST-only
     * and CSRF-protected. A GET that removes an extension would be triggerable by
     * a link in an email.
     */
    public function testDelistingCannotHappenOverGet(): void
    {
        $client = static::createClient();
        $client->request('GET', '/my/delist/frosh-tools');

        self::assertSame(405, $client->getResponse()->getStatusCode());
    }

    /**
     * The OAuth handshake must carry state, or an attacker can bind a victim's
     * session to their own GitHub account.
     */
    public function testSignInCarriesOauthState(): void
    {
        $client = static::createClient();
        $client->request('GET', '/auth/github');

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');

        self::assertStringStartsWith('https://github.com/login/oauth/authorize', $location);
        self::assertMatchesRegularExpression('/[?&]state=[a-f0-9]{32}/', $location);

        // No scopes: checking whether the signed-in user can write to a public
        // repository needs none, and asking for more than the job requires is how
        // an integration ends up holding rights it cannot justify.
        self::assertMatchesRegularExpression('/[?&]scope=(&|$)/', $location);
    }
}
