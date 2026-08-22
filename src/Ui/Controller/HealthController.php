<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Catalog\Repository\ExtensionRepository;
use App\Compatibility\Repository\ShopwareVersionRepository;
use App\Ui\Health\CrawlFreshness;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness and freshness for external monitoring.
 *
 * Deliberately reports two different things. "Is the process up" is what a monitor
 * usually asks, but for a directory the failure that actually matters is silent:
 * the site stays up, serves every page, and quietly shows data that stopped being
 * refreshed three weeks ago because a worker died or a token expired. Nobody
 * notices until a merchant acts on a stale compatibility claim.
 *
 * So staleness is a failure state here, not a metric.
 */
final class HealthController extends AbstractController
{
    /**
     * Crawls run daily. Two days without one is a fault rather than a slow day.
     */
    private const STALE_AFTER_HOURS = 48;

    /**
     * The tolerance for "did last night's crawl actually run".
     *
     * The nightly ingest starts at 03:23, so in normal operation the gap peaks just
     * under 24 hours right before the next one. 26 leaves two hours of grace and still
     * reports a missed night the following morning, rather than a day and a half later
     * like the staleness check above.
     */
    private const CRAWL_OVERDUE_AFTER_HOURS = 26;

    public function __construct(
        private readonly Connection $connection,
        private readonly ExtensionRepository $extensions,
        private readonly ShopwareVersionRepository $shopwareVersions,
    ) {
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'catalog' => $this->checkCatalog(),
            'freshness' => $this->checkFreshness(),
            'reference_data' => $this->checkReferenceData(),
        ];

        $healthy = !\in_array(false, array_column($checks, 'ok'), true);

        $response = new JsonResponse(
            ['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks],
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );

        // A cached health check reports the past, which is the one thing it must
        // not do.
        $response->headers->set('Cache-Control', 'no-store, max-age=0');

        // An operational endpoint, and one that reports degradation in plain words.
        // Nothing links to it, but the monitor watching it is a public status page,
        // so it is discoverable, and "extdir degraded" is not a search result the
        // project should own. JSON cannot carry a meta tag, so it goes in a header.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * "Did last night's crawl run?", as a status code.
     *
     * Exists because the answer /health gives is deliberately slow: it tolerates 48
     * hours, which is right for "is this data still trustworthy" and a poor way to
     * learn that a job died last night. This asks the tighter question on its own URL,
     * so an ordinary HTTP monitor can watch it, no heartbeat feature, no plan tier,
     * no second service.
     *
     * Pull rather than push, and better for it. A heartbeat proves a command exited;
     * this proves the catalogue actually got fresher, which is the thing anyone
     * cares about. A crawl that ran, failed, and exited 0 would satisfy a heartbeat
     * and fail here, correctly.
     */
    #[Route('/health/crawl', name: 'health_crawl', methods: ['GET'])]
    public function crawl(): JsonResponse
    {
        $check = CrawlFreshness::check($this->crawlAgeHours(), self::CRAWL_OVERDUE_AFTER_HOURS);

        $response = new JsonResponse(
            ['status' => $check['ok'] ? 'ok' : 'overdue', 'detail' => $check['detail']],
            $check['ok'] ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );

        $response->headers->set('Cache-Control', 'no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function checkDatabase(): array
    {
        try {
            $this->connection->executeQuery('SELECT 1');

            return ['ok' => true, 'detail' => 'reachable'];
        } catch (\Throwable $e) {
            // On Uberspace the classic cause is DATABASE_URL pointing at
            // 127.0.0.1: each user sits in its own network namespace, so php-fpm
            // and supervisord services cannot reach MySQL over loopback and must
            // use $HOSTNAME instead.
            return ['ok' => false, 'detail' => 'unreachable: '.$e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function checkCatalog(): array
    {
        $count = $this->extensions->count([]);

        return [
            'ok' => $count > 0,
            'detail' => \sprintf('%d extensions indexed', $count),
        ];
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function checkFreshness(): array
    {
        return CrawlFreshness::check($this->crawlAgeHours(), self::STALE_AFTER_HOURS);
    }

    /**
     * Hours since the last completed crawl, or null if none ever has.
     */
    private function crawlAgeHours(): ?float
    {
        $lastCrawl = $this->connection->fetchOne('SELECT MAX(last_crawled_at) FROM extension');

        if (!\is_string($lastCrawl)) {
            return null;
        }

        return (time() - strtotime($lastCrawl)) / 3600;
    }

    /**
     * Without the Shopware release timeline there is no compatibility matrix and
     * no maintenance status, the site would render, and every answer on it would
     * be wrong.
     *
     * @return array{ok: bool, detail: string}
     */
    private function checkReferenceData(): array
    {
        $current = $this->shopwareVersions->findCurrent();

        return [
            'ok' => null !== $current,
            'detail' => null !== $current
                ? 'current Shopware '.$current->getMajorMinor()
                : 'no current Shopware version set, run app:shopware:sync-versions',
        ];
    }
}
