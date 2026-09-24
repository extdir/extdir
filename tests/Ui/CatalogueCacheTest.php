<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Catalog\Entity\Category;
use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Catalog\Enum\IndexStatus;
use App\License\Enum\FindingSource;
use App\License\Enum\LicenseStatus;
use App\Submission\Entity\User;
use App\Ui\Cache\CatalogueCache;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The statistics and the leaderboards go stale the moment the catalogue moves.
 *
 * Both pages are cached for an hour because recomputing them per visitor costs
 * about a second each. An hour of drift is harmless for a release count and is not
 * harmless for a takedown: section 4.1 says nothing delisted may be counted, and an
 * extension that keeps appearing in a total for an hour after it was removed is a
 * takedown that only half happened.
 *
 * What is tested here is the invalidator rather than the pages. The two controllers
 * are covered where they live, in StatsTest and BoardsTest; this file is about the
 * pairing underneath them, that a write raises the flag and the end of the process
 * acts on it, neither half being any use alone.
 *
 * The pools are filled directly rather than by requesting the pages. Under test both
 * are array adapters, and an array adapter implements ResetInterface, so the test
 * kernel's service resetter empties it between requests: nothing cached in one
 * request is still there in the next, and a test that warmed the cache by asking for
 * the page would be asserting against the resetter instead of against this class.
 */
final class CatalogueCacheTest extends WebTestCase
{
    private const string SENTINEL = 'still-warm';

    /**
     * The case with a rule attached to it.
     */
    public function testDelistingAnExtensionThrowsAwayBothPages(): void
    {
        self::bootKernel();
        $extension = $this->seed();
        $this->warm();

        $extension->setIndexStatus(IndexStatus::Delisted);
        $this->em()->flush();
        $this->terminate();

        self::assertFalse($this->isWarm('cache.stats'), 'a takedown must not survive in a total');
        self::assertFalse($this->isWarm('cache.boards'));
    }

    public function testANewExtensionThrowsAwayBothPages(): void
    {
        self::bootKernel();
        $vendor = $this->seed()->getVendor();
        $this->warm();

        $arrival = new Extension($vendor, 'acme/late', 'acme-late', 'Late arrival');
        $arrival->setIndexStatus(IndexStatus::Listed);
        $arrival->forceLicense('MIT', LicenseStatus::Permissive, FindingSource::ComposerJson);
        $this->em()->persist($arrival);
        $this->em()->flush();
        $this->terminate();

        self::assertFalse($this->isWarm('cache.stats'));
        self::assertFalse($this->isWarm('cache.boards'));
    }

    /**
     * Stripping an extension's categories changes no entity at all.
     *
     * Categories are a many-to-many, and the two halves of reclassifying behave
     * differently in the unit of work. Adding one marks the owning extension dirty as
     * well as the collection, so an entity check alone would happen to catch it.
     * Clearing them schedules a collection deletion and nothing else: no insert, no
     * update, no delete. The nightly classifier clears before it reassigns, so an
     * extension that loses its last category is invisible to any listener watching
     * entities, and the chart built from those join rows would sit frozen with a
     * count that no longer exists.
     */
    public function testStrippingAnExtensionsCategoriesCountsAsAChange(): void
    {
        self::bootKernel();
        $extension = $this->seed();

        $category = new Category('payment', 'Payment');
        $this->em()->persist($category);
        $extension->addCategory($category);
        $this->em()->flush();
        $this->terminate();

        $this->warm();

        $extension->clearCategories();
        $this->em()->flush();
        $this->terminate();

        self::assertFalse($this->isWarm('cache.stats'), 'the category chart reads only the join rows');
    }

    /**
     * Most of the schema has nothing to do with either page.
     *
     * If every write invalidated, somebody signing in would throw away both pages and
     * the cache would stop being a cache on a busy day, which is when it is worth
     * having.
     */
    public function testAWriteThatChangesNoChartLeavesBothAlone(): void
    {
        self::bootKernel();
        $this->seed();
        $this->warm();

        $this->em()->persist(new User(4242, 'somebody'));
        $this->em()->flush();
        $this->terminate();

        self::assertTrue($this->isWarm('cache.stats'));
        self::assertTrue($this->isWarm('cache.boards'));
    }

    /**
     * The flag has to survive until the process ends.
     *
     * Ingesting Packagist flushes hundreds of times in a run. Clearing on every flush
     * would be hundreds of clears to reach the state one clear reaches, and doing it
     * inside a web request would make the moderator who triggered it wait for work
     * that helps only the next visitor.
     */
    public function testNothingIsClearedUntilTheProcessEnds(): void
    {
        self::bootKernel();
        $extension = $this->seed();
        $this->warm();

        $extension->setIndexStatus(IndexStatus::Delisted);
        $this->em()->flush();

        self::assertTrue($this->isWarm('cache.stats'), 'the flush marks, it does not clear');

        $this->terminate();

        self::assertFalse($this->isWarm('cache.stats'));
    }

    /**
     * A second terminate with nothing to do must not clear anything.
     *
     * Every request and every command ends, so commit runs constantly on processes
     * that changed nothing. If it cleared unconditionally the pools would be empty
     * almost always and the cache would be decoration.
     */
    public function testAnIdleProcessEndingClearsNothing(): void
    {
        self::bootKernel();
        $this->seed();
        $this->warm();

        $this->terminate();
        $this->terminate();

        self::assertTrue($this->isWarm('cache.stats'));
        self::assertTrue($this->isWarm('cache.boards'));
    }

    private function terminate(): void
    {
        $cache = static::getContainer()->get(CatalogueCache::class);
        self::assertInstanceOf(CatalogueCache::class, $cache);
        $cache->commit();
    }

    private function warm(): void
    {
        foreach (['cache.stats' => 'stats.payload', 'cache.boards' => 'boards.payload'] as $id => $key) {
            $pool = $this->pool($id);
            $pool->save($pool->getItem($key)->set(self::SENTINEL));
        }

        self::assertTrue($this->isWarm('cache.stats'));
        self::assertTrue($this->isWarm('cache.boards'));
    }

    private function isWarm(string $id): bool
    {
        $key = 'cache.stats' === $id ? 'stats.payload' : 'boards.payload';

        return $this->pool($id)->getItem($key)->isHit();
    }

    private function pool(string $id): CacheItemPoolInterface
    {
        $pool = static::getContainer()->get($id);
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        return $pool;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /**
     * Two listed extensions, so delisting one leaves a total that is not zero.
     */
    private function seed(): Extension
    {
        $vendor = new Vendor('acme', 'acme');
        $this->em()->persist($vendor);

        $first = $this->listed($vendor, 'one');
        $this->em()->persist($first);
        $this->em()->persist($this->listed($vendor, 'two'));

        $this->em()->flush();
        // The seed is itself a catalogue write, so the flag is up before any test has
        // begun. Cleared here, or every test would start from a state no real process
        // is ever in.
        $this->terminate();

        return $first;
    }

    private function listed(Vendor $vendor, string $name): Extension
    {
        $extension = new Extension($vendor, 'acme/'.$name, 'acme-'.$name, 'Acme '.$name);
        $extension->setIndexStatus(IndexStatus::Listed);
        $extension->forceLicense('MIT', LicenseStatus::Permissive, FindingSource::ComposerJson);

        return $extension;
    }
}
