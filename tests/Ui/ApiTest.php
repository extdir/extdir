<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Catalog\Enum\IndexStatus;
use App\License\Enum\FindingSource;
use App\License\Enum\LicenseStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The JSON API.
 *
 * What matters here is not that it returns data but that it cannot mislead something
 * reading it without a page to look at. An agent has no licence badge to notice and no
 * compatibility legend to read; it has the field names and nothing else.
 */
final class ApiTest extends WebTestCase
{
    public function testTheListReturnsExtensions(): void
    {
        $client = static::createClient();
        $this->seed();

        $client->request('GET', '/api/extensions');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $payload = $this->decode($client);

        self::assertSame(2, $payload['total']);
        self::assertCount(2, $payload['extensions']);
    }

    /**
     * The API and the pages must be the same search.
     *
     * A second implementation of "licence=permissive" would agree with the first until
     * the day it did not, and nothing would say which was right.
     */
    public function testAFilterMeansTheSameThingAsOnTheWebsite(): void
    {
        $client = static::createClient();
        $this->seed();

        $client->request('GET', '/api/extensions?licence=permissive');
        $payload = $this->decode($client);

        self::assertSame(1, $payload['total']);
        self::assertSame('acme/open', $payload['extensions'][0]['package']);
        self::assertSame(['licence' => 'permissive'], $payload['filters']);
    }

    /**
     * The field an installer has to read.
     *
     * 109 indexed packages declare a proprietary licence. Anything that reads an SPDX
     * string it does not recognise and assumes the best would install one.
     */
    public function testAnUnlicensedExtensionSaysItIsNotRedistributable(): void
    {
        $client = static::createClient();
        $this->seed();

        $client->request('GET', '/api/extensions/acme-closed');
        $payload = $this->decode($client);

        self::assertFalse($payload['licence']['redistributable']);
        self::assertSame('rejected', $payload['licence']['status']);
    }

    /**
     * Named for what it is: a maintainer's declaration, not a test result.
     */
    public function testCompatibilityIsCalledWhatItIs(): void
    {
        $client = static::createClient();
        $this->seed();

        $client->request('GET', '/api/extensions/acme-open');
        $payload = $this->decode($client);

        self::assertArrayHasKey('declaresSupportFor', $payload);
        self::assertArrayNotHasKey('worksWith', $payload, 'nothing here has been installed against a running Shopware');
    }

    public function testEveryRecordLinksBackToItsPage(): void
    {
        $client = static::createClient();
        $this->seed();

        $client->request('GET', '/api/extensions');

        foreach ($this->decode($client)['extensions'] as $record) {
            self::assertStringContainsString('/extension/', (string) $record['url']);
        }
    }

    /**
     * A takedown that leaves the data reachable through a second door is not a takedown.
     */
    public function testDelistedWorkIsGoneFromTheApiToo(): void
    {
        $client = static::createClient();
        $this->seed();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vendor = new Vendor('ghost', 'ghost');
        $removed = new Extension($vendor, 'ghost/removed', 'ghost-removed', 'Removed');
        $removed->setIndexStatus(IndexStatus::Delisted);
        $em->persist($vendor);
        $em->persist($removed);
        $em->flush();

        $client->request('GET', '/api/extensions/ghost-removed');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    /**
     * An error an agent can parse, in the format the rest of the API speaks.
     */
    public function testAnUnknownSlugAnswersWithAProblemDocument(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/extensions/no-such-extension');

        self::assertResponseStatusCodeSame(404);

        $payload = $this->decode($client);

        self::assertSame(404, $payload['status']);
        self::assertArrayHasKey('detail', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function seed(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vendor = new Vendor('acme', 'acme');
        $em->persist($vendor);

        $open = new Extension($vendor, 'acme/open', 'acme-open', 'Acme Open');
        $open->setIndexStatus(IndexStatus::Listed);
        $open->forceLicense('MIT', LicenseStatus::Permissive, FindingSource::ComposerJson);

        // Public repository, proprietary declaration: indexed and linked, never
        // redistributed. The API has to say so.
        $closed = new Extension($vendor, 'acme/closed', 'acme-closed', 'Acme Closed');
        $closed->setIndexStatus(IndexStatus::IndexOnly);
        $closed->forceLicense(null, LicenseStatus::Rejected, FindingSource::ComposerJson);

        $em->persist($open);
        $em->persist($closed);
        $em->flush();
    }
}
