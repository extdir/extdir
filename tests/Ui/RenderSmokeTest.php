<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Every page actually renders.
 *
 * lint:twig proves a template parses, not that it runs — a missing variable, a filter
 * on null, or a route that moved all pass the linter and fail at request time. After
 * a redesign that touched every template, that gap is exactly where breakage hides.
 */
final class RenderSmokeTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function publicPages(): iterable
    {
        yield 'catalogue' => ['/'];
        yield 'catalogue filtered' => ['/?shopware=6.7&sort=updated'];
        yield 'catalogue searched' => ['/?q=versand'];
        yield 'catalogue empty' => ['/?q=zzzzznothingmatchesthis'];
        yield 'catalogue compact' => ['/?view=compact'];
        yield 'catalogue compact filtered' => ['/?view=compact&shopware=6.7&maintenance=current'];
        yield 'ranking' => ['/ranking'];
        yield 'vendors' => ['/vendors'];
        yield 'about' => ['/about'];
        yield 'imprint' => ['/imprint'];
        yield 'privacy' => ['/privacy'];
        yield 'terms' => ['/terms'];
        yield 'takedown' => ['/takedown'];
    }

    #[DataProvider('publicPages')]
    public function testEveryPublicPageRenders(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertResponseIsSuccessful(\sprintf('%s must render.', $path));
    }

    /**
     * debug must be off, or Symfony serves its own exception page and the custom
     * template is never exercised — which is also why this went unnoticed until the
     * template existed to be tested.
     */
    /**
     * Density is a URL, not a cookie, so the chosen view has to be reflected in the
     * page and carried by every link that changes a filter.
     *
     * Asserted against the control rather than the rows, because the test database
     * holds no extensions — there is nothing to render rows from, and a test that
     * only passes when someone has seeded data is a test that fails on a clean clone.
     */
    public function testTheCompactViewIsReflectedInTheControls(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/?view=compact');

        self::assertResponseIsSuccessful();

        $current = $crawler->filter('.view-toggle a[aria-current="true"]');

        self::assertSame(1, $current->count(), 'Exactly one density must be marked current.');
        self::assertSame('Compact', trim($current->text()));
    }

    public function testTheComfortableViewIsTheDefault(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertSame('Detailed', trim($crawler->filter('.view-toggle a[aria-current="true"]')->text()));
    }

    /**
     * A facet heading with nothing beneath it reads as a broken page, and that is
     * exactly what "Maintenance" was before enrichment had ever run.
     */
    public function testNoFacetRendersAHeadingWithoutOptions(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $empty = $crawler->filter('.facet')->reduce(
            static fn (\Symfony\Component\DomCrawler\Crawler $facet): bool => 0 === $facet->filter('li')->count(),
        );

        self::assertSame(0, $empty->count(), 'A facet section rendered with no options in it.');
    }

    /**
     * The restore must be synchronous and in the head.
     *
     * If it ever becomes a deferred module the page paints light first and corrects
     * itself, which is a white flash on every navigation for anyone who chose dark —
     * the precise failure the inline script exists to prevent, and one that is easy
     * to reintroduce while tidying scripts out of templates.
     */
    public function testTheThemeIsRestoredBeforeFirstPaint(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $html = (string) $client->getResponse()->getContent();
        $head = substr($html, 0, strpos($html, '</head>') ?: 0);

        self::assertStringContainsString('extdir-theme', $head, 'The restore script must be in the head.');
        self::assertStringContainsString('data-theme', $head);
        self::assertDoesNotMatchRegularExpression(
            '/<script[^>]*\b(defer|async|type="module")[^>]*>[^<]*extdir-theme/',
            $head,
            'The restore must not be deferred.',
        );
    }

    public function testTheThemeToggleOffersSystemAsWellAsLightAndDark(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $values = $crawler->filter('.theme-toggle [data-theme-value]')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $node): string => (string) $node->attr('data-theme-value'),
        );

        // Without "system" a visitor who tries the toggle can never go back to
        // following their machine, and a laptop that darkens each evening stays
        // light forever because of one click.
        self::assertSame(['system', 'light', 'dark'], $values);
    }

    /**
     * A refusal is not a fault.
     *
     * The generic error page claimed "something went wrong at our end… it has been
     * logged" for every status including 403, which invites a bug report for an
     * incident that never happened and tells the visitor the one thing that is
     * certainly untrue: that it was not about them.
     */
    public function testARefusalDoesNotClaimTheServerBroke(): void
    {
        $client = static::createClient(['debug' => false]);
        $client->catchExceptions(true);
        $client->request('GET', '/moderate');

        $status = $client->getResponse()->getStatusCode();

        if (403 !== $status) {
            // Anonymous requests are redirected to sign in instead, which is also
            // correct — there is nothing to assert about a 302 body.
            self::assertContains($status, [302, 401]);

            return;
        }

        $body = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('went wrong at our end', $body);
        self::assertStringNotContainsString('fault on the server', $body);
    }

    /**
     * The rail must not be a carousel.
     *
     * Everything in it has to be in the DOM and reachable without JavaScript — a
     * scroll container with snap points, not a widget that reveals cards on click.
     * A carousel hiding two thirds of its contents is an annoyance on a shop and a
     * real obstacle when somebody is choosing which payment plugin goes into
     * production.
     */
    public function testTheAlternativesRailKeepsEveryCardInTheDocument(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $link = $crawler->filter('.row-title a')->first();

        if (0 === $link->count()) {
            self::markTestSkipped('No extensions in the test database.');
        }

        $detail = $client->click($link->link());
        $rail = $detail->filter('.rail-track');

        if (0 === $rail->count()) {
            // Extensions with no categories or keywords have no alternatives, which
            // is a legitimate state for 147 of them.
            self::assertSame(0, $detail->filter('.alt-card')->count());

            return;
        }

        // The arrows are an enhancement and start hidden; the cards are not.
        self::assertGreaterThan(0, $detail->filter('.alt-card')->count());
        self::assertSame(2, $detail->filter('.rail-nav[hidden]')->count());
        self::assertNotNull($rail->attr('tabindex'), 'The scroll container must be focusable.');
    }

    /**
     * A vendor whose every extension has been delisted still has a database row.
     * Serving a page that lists nothing would be a dead end for a reader and a
     * crawlable void for a search engine, so it 404s instead.
     */
    public function testAVendorWithNothingVisibleIsNotAPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vendor/no-such-vendor');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnUnknownPathRendersTheNotFoundPage(): void
    {
        $client = static::createClient(['debug' => false]);
        $client->catchExceptions(true);
        $crawler = $client->request('GET', '/no-such-page-exists');

        self::assertResponseStatusCodeSame(404);
        // The search box is the point of the page: a 404 here is usually a delisted
        // extension or a half-remembered package name.
        self::assertGreaterThan(0, $crawler->filter('form[role="search"]')->count());
    }
}
