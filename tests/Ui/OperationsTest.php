<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Catalog\Entity\Category;
use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Catalog\Enum\IndexStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The operational surfaces: discovery and monitoring.
 */
final class OperationsTest extends WebTestCase
{
    public function testRobotsPointsAtTheSitemap(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Sitemap:', $body);
        self::assertStringContainsString('/sitemap.xml', $body);
    }

    /**
     * Sort orderings return the same set in a different order. Left crawlable they
     * multiply identical content across a crawl budget that is better spent on the
     * 484 extension pages.
     */
    public function testRobotsKeepsCrawlersOutOfSortPermutations(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt');

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Disallow: /*?*sort=', $body);
        self::assertStringNotContainsString("Disallow: /\n", $body, 'the site itself must stay crawlable');
    }

    /**
     * Action endpoints are not pages, and a crawler following one wastes a request on
     * a redirect it can never complete.
     *
     * Not hypothetical: Search Console reported a server error on
     * /auth/github?extension=..., which Googlebot only ever saw because the "Verify
     * ownership" link on every extension page was crawlable.
     */
    #[DataProvider('pathsNoCrawlerShouldFollow')]
    public function testRobotsKeepsCrawlersOffActionEndpoints(string $rule): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt');

        self::assertStringContainsString(
            'Disallow: '.$rule,
            (string) $client->getResponse()->getContent(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsNoCrawlerShouldFollow(): iterable
    {
        yield 'oauth' => ['/auth/'];
        yield 'maintainer area' => ['/my/'];
        yield 'complaint form' => ['/report/'];
        yield 'moderation' => ['/moderate'];
        // The compact layout is the same results in a different shape, exactly like
        // sort. 182 of these were crawled before it was excluded.
        yield 'layout toggle' => ['/*?*view='];
    }

    /**
     * The sitemap and the pages it submits must agree about what is indexable.
     *
     * Submitting a URL that then tells the crawler not to index it is a reported
     * error. The two decisions live in different files and nothing but this stops
     * them drifting, so it seeds one category big enough to qualify and one too
     * small, then checks both ends: the sitemap carries the first and not the
     * second, and the small one's page says noindex.
     */
    public function testTheSitemapAndTheListingAgreeOnWhatIsIndexable(): void
    {
        $client = static::createClient();
        $this->seedCategories();

        $client->request('GET', '/sitemap.xml');
        $sitemap = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('category=roomy', $sitemap, 'a category with enough extensions belongs in the sitemap');
        self::assertStringNotContainsString('category=lonely', $sitemap, 'a category with one extension is too thin to submit');

        // And the thin one says so itself, rather than leaving Google to work it out
        // and pick its own canonical.
        $client->request('GET', '/?category=lonely');
        self::assertStringContainsString('noindex', (string) $client->getResponse()->getContent());

        $client->request('GET', '/?category=roomy');
        self::assertStringNotContainsString('noindex', (string) $client->getResponse()->getContent());
    }

    /**
     * Stacked filters are a path through the UI, not a page somebody searched for.
     */
    public function testADeepFilterCombinationIsNotIndexable(): void
    {
        $client = static::createClient();
        $this->seedCategories();

        $client->request('GET', '/?shopware=6.3&category=roomy&licence=copyleft&maintenance=lagging');

        self::assertStringContainsString(
            'noindex',
            (string) $client->getResponse()->getContent(),
            'four stacked facets is the shape Search Console reported as a duplicate',
        );
    }

    private function seedCategories(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $roomy = new Category('roomy', 'Roomy');
        $lonely = new Category('lonely', 'Lonely');
        $vendor = new Vendor('acme', 'acme');
        $em->persist($roomy);
        $em->persist($lonely);
        $em->persist($vendor);

        foreach (range(1, 4) as $i) {
            $extension = new Extension($vendor, 'acme/roomy-'.$i, 'acme-roomy-'.$i, 'Roomy '.$i);
            $extension->setIndexStatus(IndexStatus::Listed);
            $extension->addCategory($roomy);
            $em->persist($extension);
        }

        $only = new Extension($vendor, 'acme/lonely', 'acme-lonely', 'Lonely');
        $only->setIndexStatus(IndexStatus::Listed);
        $only->addCategory($lonely);
        $em->persist($only);

        $em->flush();
    }

    public function testTheSitemapIsValidXmlAndListsExtensions(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();

        $xml = simplexml_load_string((string) $client->getResponse()->getContent());

        self::assertNotFalse($xml, 'the sitemap must be parsable XML');
        self::assertSame('urlset', $xml->getName());
        self::assertGreaterThan(0, $xml->count());
    }

    /**
     * Health is the one endpoint whose answer must never come from a cache, a
     * cached health check reports the past, which is precisely what it exists to
     * avoid.
     */
    public function testHealthIsNeverCached(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertStringContainsString(
            'no-store',
            (string) $client->getResponse()->headers->get('Cache-Control'),
        );
    }

    /**
     * A degraded system must answer with a status code a monitor reacts to. The
     * dangerous failure for a directory is not the site going down, it is the
     * site staying up while serving data that stopped being refreshed, so
     * staleness has to surface as 503 rather than as a field nobody reads.
     */
    public function testHealthReportsAStatusCodeAMonitorCanAlertOn(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        $status = $client->getResponse()->getStatusCode();

        self::assertContains($status, [200, 503]);

        /** @var array{status: string, checks: array<string, array{ok: bool}>} $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertArrayHasKey('checks', $payload);
        self::assertSame(
            200 === $status,
            'ok' === $payload['status'],
            'the status code and the body must agree about whether the system is healthy',
        );

        foreach (['database', 'catalog', 'freshness', 'reference_data'] as $check) {
            self::assertArrayHasKey($check, $payload['checks']);
        }
    }

    /**
     * The URL an uptime monitor watches to learn that last night's crawl did not run.
     *
     * A heartbeat monitor is the usual way to check a cron job, and UptimeRobot only
     * sells it on a paid tier. This asks the same question with an ordinary HTTP check,
     * which every plan has, and answers it better: a heartbeat proves a command
     * exited, while this proves the catalogue actually got fresher.
     */
    public function testTheCrawlEndpointAnswersWithAStatusCode(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health/crawl');

        // The test database holds no extensions, so no crawl has ever completed and
        // the honest answer is 503, which is the alerting path, exercised.
        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertIsArray($payload);
        self::assertSame('overdue', $payload['status']);
        self::assertSame('no crawl has ever completed', $payload['detail']);
    }

    /**
     * A cached answer reports the past, which is the one thing a monitor must not be
     * told.
     */
    public function testTheCrawlEndpointIsNeverCached(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health/crawl');

        self::assertStringContainsString(
            'no-store',
            (string) $client->getResponse()->headers->get('Cache-Control'),
        );
    }

    /**
     * The health endpoints report degradation in plain words, and the monitor that
     * watches them is a public status page, so they are discoverable even though
     * nothing links to them. JSON cannot carry a meta tag, so the instruction has to
     * travel as a header.
     */
    #[DataProvider('operationalEndpoints')]
    public function testOperationalEndpointsAreNotIndexable(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertStringContainsString(
            'noindex',
            (string) $client->getResponse()->headers->get('X-Robots-Tag'),
            $path.' is reachable and machine-readable, so it must say it is not for an index',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function operationalEndpoints(): iterable
    {
        yield 'health' => ['/health'];
        yield 'crawl freshness' => ['/health/crawl'];
        yield 'imprint contact details' => ['/imprint/contact-details.json'];
    }

    /**
     * Two pages a person can act on, the way in for an extension no crawler found,
     * and the instructions for pointing Composer here. Both were reachable and
     * neither was listed, which for the submission page is exactly backwards: it
     * exists for maintainers who arrive from a search.
     */
    public function testTheSitemapListsThePagesAVisitorCanActOn(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('/submit<', $body);
        self::assertStringContainsString('/repo<', $body);
    }

    /**
     * Every page we ask a search engine to index must nominate itself, and no other
     * URL, as the one to keep.
     *
     * This generalises a real defect. The application was reachable a second time
     * under /public/, /public/about, /public/vendors, the lot, because the
     * front-controller rewrite has to exempt that prefix from itself or loop. Symfony
     * derives the base URL from SCRIPT_NAME, so each duplicate emitted a canonical
     * pointing at itself rather than at the real page: two complete copies of the
     * site, each nominating the wrong one. Apache serves those paths without ever
     * entering the kernel, so no test here can request them, but the invariant they
     * broke is the one asserted below, and it holds for every route the kernel does
     * answer.
     */
    #[DataProvider('indexablePages')]
    public function testAnIndexablePageDeclaresItselfCanonical(string $path): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();

        $canonical = $crawler->filter('link[rel="canonical"]');

        self::assertCount(1, $canonical, $path.' must declare exactly one canonical URL');
        self::assertSame(
            'http://localhost'.$path,
            $canonical->attr('href'),
            $path.' must nominate itself, not a duplicate of itself',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function indexablePages(): iterable
    {
        // Every static page the sitemap advertises. Two of these carried a
        // canonical already; the other eight were being submitted for indexing
        // while saying nothing about which URL to keep, which is the same silence
        // that let the /public/ duplicates speak for themselves.
        yield 'home' => ['/'];
        yield 'about' => ['/about'];
        yield 'boards' => ['/boards'];
        yield 'ranking' => ['/ranking'];
        yield 'vendors' => ['/vendors'];
        yield 'submit' => ['/submit'];
        yield 'repo' => ['/repo'];
        yield 'imprint' => ['/imprint'];
        yield 'privacy' => ['/privacy'];
        yield 'terms' => ['/terms'];
        yield 'takedown' => ['/takedown'];
    }
}
