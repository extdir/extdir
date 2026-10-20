<?php

declare(strict_types=1);

namespace App\Signals;

use App\Catalog\Entity\Extension;
use App\Signals\Entity\PlumbScan;
use App\Signals\Plumb\PlumbClient;
use App\Signals\Plumb\PlumbUnavailable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fills in Plumb's security findings, politely.
 *
 * Built on the same three habits as the Packagist sweep next door, for the same
 * reasons.
 *
 * Extensions that are not on Packagist are skipped without spending a request. Plumb
 * reads Packagist, so a GitHub-only repository will never be in it, and asking is a
 * guaranteed 404. That is nearly a third of the catalogue, about two minutes of
 * requests, avoided by one condition rather than by four hundred round trips.
 *
 * The sweep throttles, and stops dead when Plumb says stop. A 429 or a 500 is not a
 * per-package failure; it means every remaining request will fail too. A week without
 * fresh findings costs nothing, because what is already stored stays on the page with
 * the date it was taken. Being blocked costs the feature.
 *
 * And a package Plumb does not know is recorded rather than skipped. Writing down
 * "asked, not scanned" is what lets the extension page say that, instead of leaving a
 * blank a reader would fill in as a bad score.
 */
final class PlumbEnricher
{
    /**
     * Pause between requests.
     *
     * Plumb allows 120 reads a minute. Half a second is a quarter of that, and at
     * about 450 packages the whole sweep takes four minutes, once a week, in the
     * small hours. Going faster would shorten a job nobody is waiting for and spend
     * goodwill at a free service for it.
     */
    private const int DELAY_MICROSECONDS = 500_000;

    /** Persist periodically so a sweep interrupted late does not lose everything. */
    private const int FLUSH_EVERY = 50;

    public function __construct(
        private readonly PlumbClient $plumb,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<Extension> $extensions
     *
     * @return array{scanned: int, unscanned: int, absent: int, failed: int, stopped: bool}
     */
    public function enrich(array $extensions, ?int $limit = null): array
    {
        $targets = array_values(array_filter(
            $extensions,
            static fn (Extension $e): bool => $e->isOnPackagist(),
        ));

        $absent = \count($extensions) - \count($targets);

        if (null !== $limit) {
            $targets = \array_slice($targets, 0, $limit);
        }

        $scanned = 0;
        $unscanned = 0;
        $failed = 0;
        $stopped = false;
        $now = new \DateTimeImmutable();

        foreach ($targets as $index => $extension) {
            if ($index > 0) {
                usleep(self::DELAY_MICROSECONDS);
            }

            try {
                $result = $this->plumb->latestScan($extension->getPackageName());
            } catch (PlumbUnavailable $e) {
                $this->logger->warning('Plumb sweep stopped early: {reason}', [
                    'reason' => $e->getMessage(),
                    'after' => $index,
                ]);
                $stopped = true;
                break;
            } catch (\Throwable $e) {
                // One package failing is not the sweep failing.
                $this->logger->notice('Plumb lookup failed for {package}: {reason}', [
                    'package' => $extension->getPackageName(),
                    'reason' => $e->getMessage(),
                ]);
                ++$failed;
                continue;
            }

            $scan = $this->scanFor($extension);
            $scan->setCheckedAt($now);

            if (null === $result) {
                // Known absence. The row exists so the page can say "Plumb has not
                // looked at this one yet" rather than show nothing and let a reader
                // read the blank as a verdict.
                $scan->setScannedAt(null);
                $scan->setSecurityScore(null);
                $scan->setReferenceVersion(null);
                $scan->setChecks([]);
                ++$unscanned;

                continue;
            }

            $scan->setScannedAt($result['scannedAt']);
            $scan->setSecurityScore($result['security']);
            $scan->setReferenceVersion($result['version']);
            $scan->setChecks($result['checks']);
            ++$scanned;

            if (0 === ($index + 1) % self::FLUSH_EVERY) {
                $this->em->flush();
            }
        }

        $this->em->flush();

        return [
            'scanned' => $scanned,
            'unscanned' => $unscanned,
            'absent' => $absent,
            'failed' => $failed,
            'stopped' => $stopped,
        ];
    }

    private function scanFor(Extension $extension): PlumbScan
    {
        $existing = $this->em->getRepository(PlumbScan::class)
            ->findOneBy(['extension' => $extension]);

        if ($existing instanceof PlumbScan) {
            return $existing;
        }

        $scan = new PlumbScan($extension);
        $this->em->persist($scan);

        return $scan;
    }
}
