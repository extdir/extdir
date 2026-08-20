<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Composer repository, from both sides of the contract.
 *
 * Composer itself never requests /repo — given a `composer` repository it appends
 * /packages.json — so that address went unserved and answered with the generic 404.
 * The instruction was correct and the site looked broken to anyone who checked it,
 * which is the one thing a person should be encouraged to do before adding a
 * stranger's repository to their project.
 */
final class ComposerRepositoryTest extends WebTestCase
{
    public function testTheAddressInTheInstructionAnswers(): void
    {
        $client = static::createClient();
        $client->request('GET', '/repo');

        self::assertResponseIsSuccessful();
    }

    /**
     * Composer's actual entry point, one path segment further down.
     */
    public function testComposerSOwnEntryPointIsJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/repo/packages.json');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertIsArray($payload);
        self::assertSame('/repo/p2/%package%.json', $payload['metadata-url']);
        self::assertArrayHasKey('available-packages', $payload);
    }

    /**
     * The landing page must print the URL Composer actually needs. A trailing
     * /packages.json here would be copied into a config that then resolves
     * /repo/packages.json/packages.json.
     */
    public function testTheLandingPagePrintsTheUrlComposerNeeds(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/repo');

        $command = $crawler->filter('pre')->first()->text();

        self::assertStringContainsString('composer config repositories.extdir composer', $command);
        self::assertStringContainsString('/repo', $command);
        self::assertStringNotContainsString('packages.json', $command);
    }

    /**
     * Only extensions Packagist does not already serve. Publishing the rest would put
     * extdir inside the dependency resolution of installs that do not need it there.
     */
    public function testOnlyPackagesMissingFromPackagistAreOffered(): void
    {
        $client = static::createClient();
        $client->request('GET', '/repo/packages.json');

        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertIsArray($payload['available-packages']);

        foreach ($payload['available-packages'] as $name) {
            self::assertMatchesRegularExpression('~^[a-z0-9._-]+/[a-z0-9._-]+$~', (string) $name);
        }
    }
}
