<?php

declare(strict_types=1);

namespace App\Signals\Plumb;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads one package's latest scan from Plumb.
 *
 * Deliberately thin. It turns their response into the few fields this site stores and
 * does nothing else: no scoring, no interpretation, no mapping of their findings onto
 * our badges. The one judgement it makes is which fields to keep, and the reason for
 * each is written on PlumbScan.
 *
 * There is no bulk endpoint, so a sweep is one request per package. That is fine at
 * this size: the catalogue holds about 450 packages on Packagist and Plumb allows 120
 * reads a minute, so a full pass is a few minutes once a week. More often than weekly
 * would be pointless anyway, because Plumb rescans on a 168 hour cadence of its own
 * and would return the same answer.
 */
final class PlumbClient
{
    /**
     * Checks worth keeping out of the fifteen Plumb runs.
     *
     * Only the security category. The rest either duplicate what this site already
     * measures for itself or ask a question a Shopware extension should not be judged
     * on, and the full reasoning is on PlumbScan. Filtering here rather than in the
     * template means the unused findings are never stored, so nobody later mistakes
     * them for something this directory endorses.
     */
    private const string CATEGORY = 'security';

    public function __construct(
        #[Autowire(service: 'plumb.api')]
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The latest scan for a package, or null when Plumb has never scanned it.
     *
     * A 404 is an ordinary answer rather than a failure. Plumb is working through
     * Packagist and has not reached every package, so a third of this catalogue comes
     * back unknown today. The caller records that as "not scanned", which is a
     * different fact from "scanned and scored badly" and has to stay different all
     * the way to the page.
     *
     * @return array{scannedAt: \DateTimeImmutable, security: ?float, version: ?string, checks: list<array{id: string, category: string, title: string, status: string, weight: int, guide: ?string, evidence: ?string}>}|null
     *
     * @throws PlumbUnavailable when the service itself cannot be reached or refuses
     */
    public function latestScan(string $packageName): ?array
    {
        try {
            $response = $this->client->request('GET', 'packages/'.$packageName);
            $status = $response->getStatusCode();

            if (404 === $status) {
                return null;
            }

            if (429 === $status || $status >= 500) {
                // Not a per-package problem. Every remaining request in the sweep will
                // meet the same wall, so the caller is told to stop rather than to
                // log four hundred identical failures.
                throw new PlumbUnavailable(\sprintf('Plumb answered %d', $status));
            }

            /** @var array{data?: array<string, mixed>} $payload */
            $payload = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw new PlumbUnavailable($e->getMessage(), previous: $e);
        }

        $data = $payload['data'] ?? null;

        if (!\is_array($data)) {
            $this->unreadable($packageName, 'the response carried no data object');

            return null;
        }

        $scan = $data['latest_scan'] ?? null;

        if (null === $scan) {
            // Known to Plumb but never scanned. An ordinary state, not a surprise:
            // a scan can be requested and not yet have run. Nothing to show, and
            // nothing to report either.
            return null;
        }

        if (!\is_array($scan) || !\is_string($scan['scanned_at'] ?? null)) {
            $this->unreadable($packageName, 'the latest scan has no readable date');

            return null;
        }

        $scores = \is_array($scan['scores'] ?? null) ? $scan['scores'] : [];
        $security = $scores['security'] ?? null;

        return [
            'scannedAt' => new \DateTimeImmutable($scan['scanned_at']),
            'security' => \is_numeric($security) ? (float) $security : null,
            'version' => \is_string($scan['reference_version'] ?? null) ? $scan['reference_version'] : null,
            'checks' => $this->checks(\is_array($scan['check_results'] ?? null) ? $scan['check_results'] : []),
        ];
    }

    /**
     * A scan this site could not read, which is not the same as a scan that does not
     * exist, and is the one failure mode here that is otherwise invisible.
     *
     * Plumb's payload is a third party's shape and may change without warning. If it
     * does, every one of the few hundred scanned extensions quietly becomes "not
     * scanned yet" and the panels empty out with nothing anywhere saying why. A 404
     * needs no log, a shape surprise does.
     */
    private function unreadable(string $packageName, string $because): void
    {
        $this->logger->warning('Plumb returned a scan for {package} that could not be read: {because}. Their payload shape may have changed.', [
            'package' => $packageName,
            'because' => $because,
        ]);
    }

    /**
     * @param array<mixed> $results
     *
     * @return list<array{id: string, category: string, title: string, status: string, weight: int, guide: ?string, evidence: ?string}>
     */
    private function checks(array $results): array
    {
        $checks = [];

        foreach ($results as $result) {
            if (!\is_array($result) || self::CATEGORY !== ($result['category'] ?? null)) {
                continue;
            }

            $id = $result['check_id'] ?? null;

            if (!\is_string($id)) {
                continue;
            }

            $checks[] = [
                'id' => $id,
                'category' => self::CATEGORY,
                'title' => \is_string($result['title'] ?? null) ? $result['title'] : $id,
                'status' => \is_string($result['status'] ?? null) ? $result['status'] : 'not_applicable',
                'weight' => \is_numeric($result['weight'] ?? null) ? (int) $result['weight'] : 0,
                // Their published explanation of the check. This is what makes a
                // finding checkable rather than an assertion, so a check that
                // arrives without one is still stored and simply not linked.
                'guide' => \is_string($result['guide_url'] ?? null) ? $result['guide_url'] : null,
                'evidence' => $this->evidence($result['evidence'] ?? null),
            ];
        }

        return $checks;
    }

    /**
     * One short line of what was actually found, or null.
     *
     * Their evidence payloads differ per check and some carry long file lists. Only
     * the counts are kept: enough for a maintainer to see the shape of the problem,
     * with their guide one click away for the detail. Storing the lists would mean
     * republishing a third party's full findings about somebody else's repository,
     * which is more than this site needs and more than it should assume it may.
     */
    private function evidence(mixed $evidence): ?string
    {
        if (!\is_array($evidence)) {
            return null;
        }

        $total = $evidence['total'] ?? null;
        $pinned = $evidence['pinned'] ?? null;

        if (\is_numeric($total) && \is_numeric($pinned)) {
            return \sprintf('%d of %d pinned', (int) $pinned, (int) $total);
        }

        foreach (['open', 'count', 'total'] as $key) {
            if (\is_numeric($evidence[$key] ?? null)) {
                return \sprintf('%s: %d', $key, (int) $evidence[$key]);
            }
        }

        return null;
    }
}
