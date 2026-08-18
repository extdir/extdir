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
        yield 'ranking' => ['/ranking'];
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
