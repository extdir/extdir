<?php

declare(strict_types=1);

namespace App\Signals;

use App\Catalog\Entity\Extension;
use App\Ingestion\Packagist\PackagistClient;
use App\Ingestion\Packagist\PackagistRateLimited;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fills in Packagist install counts, politely.
 *
 * There is no bulk endpoint. `list.json` accepts a `fields[]` parameter and quietly
 * ignores `downloads` — it answers with `repository` whatever you ask for — so counts
 * cost one request per package, and they come from packagist.org rather than the p2
 * host that exists to be hammered.
 *
 * Two things follow from that, and they are the whole design:
 *
 * The listing is fetched once and intersected with what we hold, so not a single
 * request is spent discovering that a GitHub-only extension is not on Packagist. That
 * is 170 of 595 — nearly a third of the sweep, avoided by one call.
 *
 * And the sweep throttles, then stops dead on a 429. A rate limit is not a per-package
 * failure to log and step over; it means every remaining request will fail too. Missing
 * a week of download counts costs nothing. Being blocked by the service the entire
 * directory is seeded from costs everything.
 */
final class PackagistEnricher
{
    /**
     * Pause between requests.
     *
     * Roughly two per second against an endpoint whose own client configuration is
     * annotated "rate limited far more tightly". At 425 packages the whole sweep takes
     * about three and a half minutes, once a week, in the small hours — small enough
     * that being any faster would buy nothing worth the risk.
     */
    private const int DELAY_MICROSECONDS = 500_000;

    /** Persist periodically so a sweep interrupted late does not lose everything. */
    private const int FLUSH_EVERY = 50;

    public function __construct(
        private readonly PackagistClient $packagist,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<Extension> $extensions
     *
     * @return array{updated: int, absent: int, unreadable: int, rateLimited: bool}
     */
    public function enrich(array $extensions, ?int $limit = null): array
    {
        $onPackagist = array_flip($this->packagist->listPackageNames());

        $targets = array_values(array_filter(
            $extensions,
            static fn (Extension $e): bool => isset($onPackagist[$e->getPackageName()]),
        ));

        $absent = \count($extensions) - \count($targets);

        if (null !== $limit) {
            $targets = \array_slice($targets, 0, $limit);
        }

        $updated = 0;
        $unreadable = 0;
        $rateLimited = false;
        $checkedAt = new \DateTimeImmutable();

        foreach ($targets as $index => $extension) {
            if ($index > 0) {
                usleep(self::DELAY_MICROSECONDS);
            }

            try {
                $stats = $this->packagist->fetchPackageStats($extension->getPackageName());
            } catch (PackagistRateLimited $e) {
                $this->logger->warning('Packagist rate limit reached, stopping the sweep', [
                    'after' => $updated,
                    'error' => $e->getMessage(),
                ]);
                $rateLimited = true;
                break;
            }

            if (null === $stats) {
                ++$unreadable;
                continue;
            }

            $extension->setPackagistDownloads($stats['total'], $stats['monthly'], $checkedAt);
            ++$updated;

            if (0 === $updated % self::FLUSH_EVERY) {
                $this->em->flush();
            }
        }

        $this->em->flush();

        return [
            'updated' => $updated,
            'absent' => $absent,
            'unreadable' => $unreadable,
            'rateLimited' => $rateLimited,
        ];
    }
}
