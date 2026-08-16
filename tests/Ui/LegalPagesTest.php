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
