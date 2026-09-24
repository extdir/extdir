<?php

declare(strict_types=1);

namespace App\Ui\Cache;

use App\Catalog\Entity\Category;
use App\Catalog\Entity\Extension;
use App\Catalog\Entity\ExtensionRelease;
use App\Catalog\Entity\Vendor;
use App\Compatibility\Entity\CompatibilityClaim;
use App\Compatibility\Entity\ShopwareVersion;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Drops the cached statistics and leaderboards whenever the catalogue changes.
 *
 * Both pages are aggregates over the whole catalogue, cached for an hour because
 * recomputing them per visitor costs about a second. An hour of staleness is
 * tolerable for a release count. It is not tolerable for a takedown: section 4.1
 * says nothing delisted may be counted, and a delisted extension that goes on
 * appearing in a total for another hour is a takedown that only half happened.
 *
 * So the invalidation is driven by writes rather than by a clock or a schedule.
 *
 * The obvious alternative was to clear the pools at the end of the nightly crawl.
 * That does not work here, because there is no end: the catalogue is built by seven
 * separate cron jobs spread from three in the morning to half past six, and the ones
 * that matter most to these pages are the late ones, the signals refresh that settles
 * maintenance status and the classifier that assigns categories. Hanging the
 * invalidation off any one of them would leave the other six able to change the
 * numbers without anybody noticing, and the eighth job somebody adds next year would
 * miss it silently. Worse, it would do nothing at all for a moderator delisting an
 * extension at noon, which is the case that actually has a rule attached to it.
 *
 * Marking rather than clearing on the spot. Ingesting Packagist flushes hundreds of
 * times in a run, and clearing a pool hundreds of times to end in the same state as
 * clearing it once is waste. The flag is set during the flush and acted on when the
 * process ends, which for a web request is after the response has already gone out,
 * so the visitor who triggered it waits for nothing.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'commit')]
#[AsEventListener(event: 'console.terminate', method: 'commit')]
final class CatalogueCache
{
    /**
     * The entities these two pages count.
     *
     * Everything else in the schema may change without touching a chart: a session,
     * a submission awaiting moderation, an ownership claim, a complaint. Listing the
     * six deliberately rather than invalidating on any write at all, because the
     * point of the cache is that most requests do not pay for it.
     */
    private const array WATCHED = [
        Extension::class,
        ExtensionRelease::class,
        Category::class,
        Vendor::class,
        CompatibilityClaim::class,
        ShopwareVersion::class,
    ];

    private bool $stale = false;

    public function __construct(
        #[Autowire(service: 'cache.stats')]
        private readonly CacheItemPoolInterface $stats,
        #[Autowire(service: 'cache.boards')]
        private readonly CacheItemPoolInterface $boards,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->stale) {
            // Already condemned. Nothing later in this process can change that, and
            // the rest of this method is pure cost during a long ingestion run.
            return;
        }

        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ([
            $uow->getScheduledEntityInsertions(),
            $uow->getScheduledEntityUpdates(),
            $uow->getScheduledEntityDeletions(),
        ] as $scheduled) {
            foreach ($scheduled as $entity) {
                if ($this->isWatched($entity)) {
                    $this->stale = true;

                    return;
                }
            }
        }

        // Categories are a many-to-many, and the two halves of reclassifying do not
        // look the same from here. Adding one marks the owning extension dirty as
        // well as the collection, so the loop above would have caught it anyway.
        // Clearing them schedules a collection deletion and nothing else: no insert,
        // no update, no delete, nothing for that loop to see. The nightly classifier
        // clears before it reassigns, so without this an extension losing its last
        // category would leave the chart built from those join rows counting it.
        foreach ([
            $uow->getScheduledCollectionUpdates(),
            $uow->getScheduledCollectionDeletions(),
        ] as $collections) {
            foreach ($collections as $collection) {
                if ($this->isWatched($collection->getOwner())) {
                    $this->stale = true;

                    return;
                }
            }
        }
    }

    /**
     * Throw away both pages, once, at the end of whatever changed the catalogue.
     */
    public function commit(): void
    {
        if (!$this->stale) {
            return;
        }

        // Reset first. A pool that cannot be reached should not leave the flag up and
        // make every subsequent flush in a long run try again and fail again.
        $this->stale = false;

        $this->stats->clear();
        $this->boards->clear();
    }

    private function isWatched(?object $entity): bool
    {
        if (null === $entity) {
            return false;
        }

        foreach (self::WATCHED as $class) {
            // instanceof rather than a class-name lookup, because Doctrine hands back
            // lazy proxies whose class name is not the entity's.
            if ($entity instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
