<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Catalog\Repository\ExtensionRepository;
use App\Compatibility\Repository\ShopwareVersionRepository;
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
        $lastCrawl = $this->connection->fetchOne('SELECT MAX(last_crawled_at) FROM extension');

        if (!\is_string($lastCrawl)) {
            return ['ok' => false, 'detail' => 'no crawl has ever completed'];
        }

        $hours = (time() - strtotime($lastCrawl)) / 3600;

        return [
            'ok' => $hours < self::STALE_AFTER_HOURS,
            'detail' => \sprintf('last crawl %.1f hours ago', $hours),
        ];
    }

    /**
     * Without the Shopware release timeline there is no compatibility matrix and
     * no maintenance status — the site would render, and every answer on it would
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
                : 'no current Shopware version set — run app:shopware:sync-versions',
        ];
    }
}
