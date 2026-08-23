<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Catalog\Enum\IndexStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * llms.txt, security.txt, the feed, and the content signals in robots.txt.
 */
final class MachineReadableTest extends WebTestCase
{
    /**
     * RFC 9116 treats a past Expires as invalid, which is the whole reason this file is
     * generated rather than written by hand: a typed date keeps serving long after it
     * stopped being true.
     */
    public function testSecurityTxtHasAnExpiryInTheFuture(): void
    {
        $client = static::createClient();
        $client->request('GET', '/.well-known/security.txt');

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        self::assertMatchesRegularExpression('/^Contact: mailto:.+@.+$/m', $body);
        self::assertMatchesRegularExpression('/^Expires: (.+)$/m', $body);

        preg_match('/^Expires: (.+)$/m', $body, $matches);
        $declared = $matches[1] ?? null;

        self::assertNotNull($declared, 'security.txt without Expires is invalid under RFC 9116');

        $expires = new \DateTimeImmutable($declared);

        self::assertGreaterThan(new \DateTimeImmutable(), $expires, 'an expired security.txt is an invalid one');
    }

    /**
     * The two things an agent must not get wrong, stated in the file itself.
     *
     * Something reading llms.txt has no badge to notice and no legend to read, so the
     * caveats have to be in the text rather than implied by the site around it.
     */
    public function testLlmsTxtStatesTheTwoCaveatsThatMatter(): void
    {
        $client = static::createClient();
        $client->request('GET', '/llms.txt');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('declares support for', $body, 'compatibility is declared, never tested');
        self::assertStringContainsString('licence.redistributable', $body, 'a public repository is not permission to install');
        self::assertStringContainsString('/api/extensions', $body, 'a signpost that points nowhere is clutter');
    }

    /**
     * Not an objection to AI. A statement of standing: the descriptions here belong to
     * the maintainers who wrote them.
     */
    public function testRobotsDeclaresTheContentSignals(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt');

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('search=yes', $body);
        self::assertStringContainsString('ai-input=yes', $body);
        self::assertStringContainsString('ai-train=no', $body);
    }

    public function testTheFeedIsValidAtom(): void
    {
        $client = static::createClient();
        $this->seed();

        $client->request('GET', '/feed.atom');

        self::assertResponseIsSuccessful();

        $xml = simplexml_load_string((string) $client->getResponse()->getContent());

        self::assertNotFalse($xml, 'the feed must parse');
        self::assertSame('feed', $xml->getName());
        self::assertGreaterThan(0, $xml->entry->count());
    }

    /**
     * A feed whose updated stamp moves on every fetch teaches aggregators to ignore it.
     */
    public function testTheFeedTimestampComesFromTheContentNotTheClock(): void
    {
        $client = static::createClient();
        $this->seed();

        self::assertSame($this->feedTimestamp($client), $this->feedTimestamp($client));
    }

    private function feedTimestamp(KernelBrowser $client): string
    {
        $client->request('GET', '/feed.atom');

        $xml = simplexml_load_string((string) $client->getResponse()->getContent());
        self::assertNotFalse($xml);

        return (string) $xml->updated;
    }

    private function seed(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vendor = new Vendor('acme', 'acme');
        $em->persist($vendor);

        foreach (range(1, 3) as $i) {
            $extension = new Extension($vendor, 'acme/plugin-'.$i, 'acme-plugin-'.$i, 'Acme Plugin '.$i);
            $extension->setIndexStatus(IndexStatus::Listed);
            $em->persist($extension);
        }

        $em->flush();
    }
}
