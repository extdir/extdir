<?php

declare(strict_types=1);

namespace App\Signals;

use App\Catalog\Repository\ExtensionRepository;
use App\Compatibility\Repository\ShopwareVersionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recomputes maintenance status and ranking for every extension.
 *
 * Works purely from stored data, so the ranking formula can be changed and the
 * whole corpus re-scored in seconds without touching GitHub or Packagist. That
 * matters more than it sounds: the conflict-of-interest rule requires a written rationale for ranking
 * changes that would benefit the maintainer's own vendor, and being able to run
 * "before and after" cheaply is what makes that reviewable rather than theoretical.
 *
 * The aggregates are fetched as three bulk queries rather than per extension. At
 * 422 extensions, 5,783 releases and 11,437 claims the naive version is thousands
 * of round trips for data that fits comfortably in memory.
 */
final class SignalsRecomputer
{
    public function __construct(
        private readonly ExtensionRepository $extensions,
        private readonly ShopwareVersionRepository $versions,
        private readonly MaintenanceEvaluator $maintenance,
        private readonly RankingScore $ranking,
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{scored: int, statuses: array<string, int>}
     */
    public function recompute(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        $latest = $this->versions->findLatest(3);
        $currentId = isset($latest[0]) ? (int) $latest[0]->getId() : 0;
        $previousId = isset($latest[1]) ? (int) $latest[1]->getId() : 0;

        $supported = $this->supportedVersionIds();
        $bestTier = $this->bestConstraintTierPerExtension();
        $responsiveness = $this->latestIssueRatioPerExtension();

        $statuses = [];
        $scored = 0;

        foreach ($this->extensions->findBy([]) as $extension) {
            $id = (int) $extension->getId();

            $status = $this->maintenance->evaluateAgainst(
                $extension->getLastCommitAt(),
                $extension->isAbandoned(),
                $latest,
                $now,
            );
            $extension->setMaintenanceStatus($status);
            $statuses[$status->value] = ($statuses[$status->value] ?? 0) + 1;

            $extension->setRankScore($this->ranking->score(
                isset($supported[$id][$currentId]),
                isset($supported[$id][$previousId]),
                $status,
                $bestTier[$id] ?? \App\Compatibility\Enum\ConstraintTier::Absent,
                $extension->getLicenseStatus(),
                $responsiveness[$id] ?? null,
                $extension->getLastCommitAt(),
                $now,
            ));

            ++$scored;
        }

        $this->em->flush();

        arsort($statuses);

        return ['scored' => $scored, 'statuses' => $statuses];
    }

    /**
     * extension id => set of Shopware version ids any stable release supports.
     *
     * @return array<int, array<int, true>>
     */
    private function supportedVersionIds(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT c.extension_id, c.shopware_version_id
             FROM compatibility_claim c
             JOIN extension_release r ON r.id = c.release_id
             WHERE c.satisfied = 1 AND r.stable = 1',
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['extension_id']][(int) $row['shopware_version_id']] = true;
        }

        return $map;
    }

    /**
     * extension id => strongest constraint tier across its stable releases.
     *
     * The best tier is used rather than the latest release's, because a maintainer
     * who pinned precisely in an older release and loosened later has still
     * demonstrated they think about compatibility.
     *
     * @return array<int, \App\Compatibility\Enum\ConstraintTier>
     */
    private function bestConstraintTierPerExtension(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT extension_id, constraint_tier FROM extension_release WHERE stable = 1',
        );

        $rank = ['explicit' => 0, 'caret' => 1, 'wildcard' => 2, 'absent' => 3];
        $best = [];

        foreach ($rows as $row) {
            $id = (int) $row['extension_id'];
            $tier = (string) $row['constraint_tier'];

            if (!isset($best[$id]) || $rank[$tier] < $rank[$best[$id]]) {
                $best[$id] = $tier;
            }
        }

        return array_map(
            static fn (string $tier) => \App\Compatibility\Enum\ConstraintTier::from($tier),
            $best,
        );
    }

    /**
     * extension id => closed-issue ratio from the most recent snapshot.
     *
     * @return array<int, float>
     */
    private function latestIssueRatioPerExtension(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.extension_id, s.open_issues, s.closed_issues
             FROM repository_snapshot s
             JOIN (
                 SELECT extension_id, MAX(captured_at) AS latest
                 FROM repository_snapshot GROUP BY extension_id
             ) newest ON newest.extension_id = s.extension_id AND newest.latest = s.captured_at',
        );

        $ratios = [];
        foreach ($rows as $row) {
            $total = (int) $row['open_issues'] + (int) $row['closed_issues'];
            if ($total > 0) {
                $ratios[(int) $row['extension_id']] = (int) $row['closed_issues'] / $total;
            }
        }

        return $ratios;
    }
}
