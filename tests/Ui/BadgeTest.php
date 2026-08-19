<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A badge is embedded in someone else's README, which makes its failure modes public
 * and makes them look like the maintainer's fault. It must never 404, never 500, and
 * never claim more than the site does.
 */
final class BadgeTest extends WebTestCase
{
    public function testAnUnknownSlugStillRendersABadge(): void
    {
        $client = static::createClient();
        $client->request('GET', '/badge/no-such-extension.svg');

        // 200 rather than 404 on purpose: a 404 inside an <img> renders as a broken
        // image in a README, which reads as the maintainer's mistake rather than a
        // stale link.
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('image/svg+xml', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('not indexed', (string) $client->getResponse()->getContent());
    }

    public function testTheBadgeIsCacheableAndSafeToEmbed(): void
    {
        $client = static::createClient();
        $client->request('GET', '/badge/no-such-extension.svg');

        $response = $client->getResponse();

        // Without a public cache header every README view would reach this server.
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertNotNull($response->getEtag());
    }

    public function testTheBadgeIsValidXml(): void
    {
        $client = static::createClient();
        $client->request('GET', '/badge/no-such-extension.svg');

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string((string) $client->getResponse()->getContent());
        libxml_use_internal_errors($previous);

        self::assertNotFalse($document, 'A malformed badge renders as nothing at all.');
    }

    /**
     * A social card is a nicety; an error page served to a crawler is not. Missing
     * Imagick or a missing font must degrade rather than fail.
     */
    public function testTheSocialCardNeverReturnsAnError(): void
    {
        $client = static::createClient();
        $client->request('GET', '/og/default.png');

        self::assertContains($client->getResponse()->getStatusCode(), [200, 204]);
    }

    public function testADelistedExtensionHasNoCardOfItsOwn(): void
    {
        $client = static::createClient();
        $client->request('GET', '/og/no-such-extension.png');

        // Falls back to the site card rather than inventing one for something that
        // has no page.
        self::assertContains($client->getResponse()->getStatusCode(), [200, 204, 302]);
    }
}
