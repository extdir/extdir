<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The policy half of the same guarantee. Consent is worthless if the page never says
 * who would be contacted or where the answer is kept, and both languages are served
 * so both have to carry it.
 */
final class RemoteMediaDisclosureTest extends WebTestCase
{
    public function testPrivacyPolicyDisclosesTheOptInInBothLanguages(): void
    {
        $client = static::createClient();
        $client->request('GET', '/privacy');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertGreaterThanOrEqual(
            2,
            substr_count($html, 'extdir-remote-media'),
            'The localStorage key is disclosed in only one language.'
        );
        self::assertStringContainsString('raw.githubusercontent.com', $html);

        // Both policies must name the hosts that are deliberately NOT loaded, since
        // that is the part a reader cannot verify for themselves.
        foreach (['imgur', 'giphy', 'Cloudinary'] as $excluded) {
            self::assertGreaterThanOrEqual(
                2,
                substr_count($html, $excluded),
                \sprintf('%s is only mentioned in one language.', $excluded)
            );
        }
    }

    /**
     * Withdrawal has to be as reachable as consent, and it is only on every page
     * because it lives in the footer.
     */
    public function testEveryPageOffersBothDirectionsOfTheChoice(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertSame(1, $crawler->filter('[data-remote-media-target="enable"]')->count());
        self::assertSame(1, $crawler->filter('[data-remote-media-target="disable"]')->count());

        // Served in the "off" state: the page must not claim icons are loading to a
        // reader who has never opted in.
        $status = $crawler->filter('[data-remote-media-target="status"]')->text();
        self::assertStringContainsString('are not loaded', $status);

        // The permission covers screenshots as well as icons, so the control has to
        // say so, consent to one is not consent to the other.
        self::assertStringContainsString('screenshots', $status);
    }
}
