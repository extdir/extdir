<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Catalog\Enum\IndexStatus;
use App\Catalog\Repository\ExtensionRepository;
use App\Catalog\Repository\VendorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The leaderboards, and the promises attached to them.
 *
 * A board is a placement surface, so most of what is worth testing here is not
 * "does it render" but the conflict-of-interest rule's requirements: the rule is
 * published, nothing is hidden, and the maintainer's own vendor gets neither a
 * boost nor a free pass on disclosure.
 */
final class BoardsTest extends WebTestCase
{
    public function testTheBoardsPageRenders(): void
    {
        $client = static::createClient();
        $this->seed();

        $crawler = $client->request('GET', '/boards');

        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(6, $crawler->filter('.board')->count(), 'six boards are expected');
    }

    /**
     * The rule is what makes a leaderboard an algorithm rather than an opinion.
     *
     * Every board has to carry its own; a board without one is an editorial pick
     * wearing a number, which is precisely what the conflict-of-interest rule forbids.
     */
    public function testEveryBoardPublishesTheRuleThatProducedIt(): void
    {
        $client = static::createClient();
        $this->seed();

        $crawler = $client->request('GET', '/boards');

        self::assertSame(
            $crawler->filter('.board')->count(),
            $crawler->filter('.board .board-rule')->count(),
            'every board must print the expression behind it',
        );
    }

    /**
     * The maintainer's own vendor may win a board. It may not do so quietly.
     */
    public function testTheMaintainersVendorCarriesItsDisclosureOnABoard(): void
    {
        $client = static::createClient();
        $this->seed(maintainerOperated: true);

        $client->request('GET', '/boards');

        self::assertStringContainsString(
            'extdir maintainer',
            (string) $client->getResponse()->getContent(),
            'a maintainer-operated vendor on a board must be disclosed as one',
        );
    }

    /**
     * An extension that is not on Packagist has no install count, which is a
     * different fact from having none.
     *
     * Without this the download boards would fill up with zeroes drawn from the 170
     * extensions Packagist cannot see, and read as though nobody installs them.
     */
    public function testUnmeasuredExtensionsStayOffTheDownloadBoards(): void
    {
        self::bootKernel();
        $this->seed();

        $extensions = static::getContainer()->get(ExtensionRepository::class);

        foreach ($extensions->topByDownloads(10) as $extension) {
            self::assertTrue(
                $extension->hasPackagistStats(),
                $extension->getPackageName().' has no Packagist figure and must not appear on an install board',
            );
        }
    }

    /**
     * Boards show what the catalogue shows, and nothing more.
     *
     * A delisted extension is one a takedown removed; surfacing it on a leaderboard
     * would be the takedown only half happening, in the most visible place on the site.
     */
    public function testDelistedWorkNeverReachesABoard(): void
    {
        self::bootKernel();
        $this->seed();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vendor = new Vendor('ghost', 'ghost');
        $delisted = new Extension($vendor, 'ghost/removed', 'ghost-removed', 'Removed');
        $delisted->setIndexStatus(IndexStatus::Delisted);
        $delisted->setPopularity(99999, 0);
        $delisted->setPackagistDownloads(99999, 9999, new \DateTimeImmutable());
        $em->persist($vendor);
        $em->persist($delisted);
        $em->flush();

        $extensions = static::getContainer()->get(ExtensionRepository::class);
        $vendors = static::getContainer()->get(VendorRepository::class);

        foreach ([$extensions->topByDownloads(10), $extensions->topByStars(10)] as $board) {
            foreach ($board as $extension) {
                self::assertNotSame('ghost/removed', $extension->getPackageName());
            }
        }

        foreach ($vendors->topByExtensionCount(10) as $row) {
            self::assertNotSame('ghost', $row['vendor']->getName());
        }
    }

    /**
     * Depth is the sum of the published per-extension score, and nothing else.
     *
     * Stated as an arithmetic check rather than trusted, because this is the one
     * board that makes a claim about quality, and the only reason it is allowed to
     * is that it introduces no formula of its own.
     */
    public function testDepthIsTheSumOfThePublishedScore(): void
    {
        self::bootKernel();
        $this->seed();

        $rows = static::getContainer()->get(VendorRepository::class)->topByAggregateScore(10);

        self::assertNotEmpty($rows);
        // acme: two extensions scoring 90 and 60 → 1.5. beta: one at 80 → 0.8.
        self::assertSame('acme', $rows[0]['vendor']->getName());
        self::assertEqualsWithDelta(1.5, $rows[0]['value'], 0.001);
    }

    private function seed(bool $maintainerOperated = false): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $acme = new Vendor('acme', 'acme');
        $acme->setMaintainerOperated($maintainerOperated);
        $beta = new Vendor('beta', 'beta');

        $widget = new Extension($acme, 'acme/widget', 'acme-widget', 'Acme Widget');
        $widget->setIndexStatus(IndexStatus::Listed);
        $widget->setRankScore(90.0);
        $widget->setPopularity(120, 4);
        $widget->setPackagistDownloads(500_000, 9_000, new \DateTimeImmutable());

        $gadget = new Extension($acme, 'acme/gadget', 'acme-gadget', 'Acme Gadget');
        $gadget->setIndexStatus(IndexStatus::Listed);
        $gadget->setRankScore(60.0);
        $gadget->setPopularity(30, 1);
        $gadget->setPackagistDownloads(1_000, 400, new \DateTimeImmutable());

        // On a forge but not on Packagist: stars only, and no install figure at all.
        $forgeOnly = new Extension($beta, 'beta/forge-only', 'beta-forge-only', 'Beta Forge Only');
        $forgeOnly->setIndexStatus(IndexStatus::Listed);
        $forgeOnly->setRankScore(80.0);
        $forgeOnly->setPopularity(900, 12);

        foreach ([$acme, $beta, $widget, $gadget, $forgeOnly] as $entity) {
            $em->persist($entity);
        }

        $em->flush();
    }
}
