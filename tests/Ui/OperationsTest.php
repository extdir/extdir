<?php

declare(strict_types=1);

namespace App\Tests\Ui;

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
     * Health is the one endpoint whose answer must never come from a cache — a
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
     * dangerous failure for a directory is not the site going down — it is the
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
}
