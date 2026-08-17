<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Ui\Controller\LegalController;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Guards the pages docs/brief.md §12 treats as launch blockers.
 *
 * These are not smoke tests for their own sake. A missing or defective Impressum
 * is a live Abmahnung risk in Germany, and the realistic way it breaks is not that
 * someone deletes the page — it is that a template edit quietly drops the postal
 * address while the page still returns 200. So the assertions are about the
 * required *content*, not the status code.
 */
final class LegalPagesTest extends WebTestCase
{
    #[DataProvider('legalRoutes')]
    public function testLegalPagesAreReachable(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function legalRoutes(): iterable
    {
        yield 'imprint' => ['/imprint'];
        yield 'privacy' => ['/privacy'];
        yield 'terms' => ['/terms'];
        yield 'takedown' => ['/takedown'];
    }

    /**
     * § 5 DDG requires the operator's name and a full postal address to be
     * present and directly reachable. This asserts each field individually so a
     * failure names exactly what went missing.
     */
    public function testImprintCarriesEveryLegallyRequiredField(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/imprint');

        self::assertResponseIsSuccessful();

        $text = $crawler->filter('body')->text();

        foreach (['name', 'street', 'postalCity', 'country', 'email'] as $field) {
            self::assertStringContainsString(
                LegalController::OPERATOR[$field],
                $text,
                \sprintf('The imprint must show the operator %s.', $field),
            );
        }
    }

    /**
     * The address must be in the HTML itself, not fetched by JavaScript. § 5
     * requires it to be "unmittelbar erreichbar und ständig verfügbar", and a
     * script-gated reveal is neither for a visitor without JavaScript.
     */
    public function testTheAddressIsInTheMarkupRatherThanLoadedLater(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/imprint');

        self::assertGreaterThan(
            0,
            $crawler->filter('address')->count(),
            'The postal address must be served as plain markup.',
        );
        self::assertStringContainsString(
            LegalController::OPERATOR['street'],
            $crawler->filter('address')->first()->html(),
        );
    }

    /**
     * §4.5 requires the non-affiliation disclaimer from day one, on every page —
     * it lives in the base layout, so this checks it is actually rendering.
     */
    #[DataProvider('legalRoutes')]
    public function testTheNonAffiliationDisclaimerIsAlwaysPresent(string $path): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $path);

        self::assertStringContainsString(
            'Not affiliated with Shopware AG',
            $crawler->filter('body')->text(),
        );
    }

    /**
     * The site is written in English, but it is operated from Germany and § 5 DDG
     * is a German obligation — so the operative text is German, with the English
     * version offered as a translation. A well-meaning cleanup that dropped the
     * German would quietly remove the part that actually satisfies the law.
     */
    public function testTheImprintKeepsItsGermanLegalBasis(): void
    {
        $client = static::createClient();
        $text = $client->request('GET', '/imprint')->filter('body')->text();

        self::assertStringContainsString('§ 5 DDG', $text, 'the DDG basis must be named');
        self::assertStringContainsString('§ 18 Abs. 2 MStV', $text, 'editorial responsibility must be named');
        self::assertStringContainsString('Haftung für Inhalte', $text);
        self::assertStringContainsString('Haftung für Links', $text);
    }

    /**
     * Likewise for the privacy policy: the legal bases have to be stated, and
     * Art. 21 objection rights are the section readers most often look for.
     */
    public function testThePrivacyPolicyStatesItsLegalBases(): void
    {
        $client = static::createClient();
        $text = $client->request('GET', '/privacy')->filter('body')->text();

        self::assertStringContainsString('Art. 6 Abs. 1 lit. f DSGVO', $text);
        self::assertStringContainsString('Widerspruchsrecht', $text);
        self::assertStringContainsString('Aufsichtsbehörde', $text);
    }

    /**
     * The policy claims no cookies and no tracking. That claim is only safe while
     * it stays true, so it is worth failing loudly if a banner or analytics ever
     * appears without the policy being updated with it.
     */
    public function testNoCookiesAreSetOnAnyPublicPage(): void
    {
        $client = static::createClient();

        foreach (['/imprint', '/privacy', '/terms', '/takedown'] as $path) {
            $client->request('GET', $path);

            self::assertCount(
                0,
                $client->getResponse()->headers->getCookies(),
                \sprintf('%s set a cookie, but the privacy policy states none are used.', $path),
            );
        }
    }

    /**
     * The takedown route must explain how to reach a human, or the policy is
     * decorative.
     */
    public function testTakedownPolicyPublishesAContactAddress(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/takedown');

        self::assertStringContainsString(LegalController::OPERATOR['email'], $crawler->filter('body')->text());
    }
}
