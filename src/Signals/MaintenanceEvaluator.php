<?php

declare(strict_types=1);

namespace App\Signals;

use App\Catalog\Entity\Extension;
use App\Compatibility\Entity\ShopwareVersion;
use App\Compatibility\Repository\ShopwareVersionRepository;
use App\Signals\Enum\MaintenanceStatus;

/**
 * Decides how well maintained an extension is, measured against Shopware's release
 * timeline rather than the calendar.
 *
 * The ranking guidance proposes an "abandoned after 18 months" badge. This replaces it,
 * because the calendar rule answers the wrong question. A finished, single-purpose
 * plugin can sit untouched for two years and work perfectly; what actually breaks
 * it is a core release. So the question is not "how old is this?" but "has anyone
 * touched it since the Shopware I run came out?", which is both more useful to a
 * merchant and far more defensible when a maintainer objects to their badge.
 */
final class MaintenanceEvaluator
{
    /**
     * The absolute floor, applied on top of the release-relative test.
     *
     * Shopware minors have shipped as little as five months and as much as two
     * years apart. Without a time floor, a burst of quick releases would sweep
     * healthy extensions into Dormant purely because two version numbers went by.
     */
    private const DORMANT_AFTER_MONTHS = 24;

    public function __construct(
        private readonly ShopwareVersionRepository $versions,
    ) {
    }

    public function evaluate(Extension $extension, ?\DateTimeImmutable $now = null): MaintenanceStatus
    {
        return $this->evaluateAgainst(
            $extension->getLastCommitAt(),
            $extension->isAbandoned(),
            $this->versions->findLatest(3),
            $now ?? new \DateTimeImmutable(),
        );
    }

    /**
     * The pure decision, separated so it can be tested against fixed timelines
     * without a database.
     *
     * @param list<ShopwareVersion> $latestVersions newest first
     */
    public function evaluateAgainst(
        ?\DateTimeImmutable $lastCommit,
        bool $abandoned,
        array $latestVersions,
        \DateTimeImmutable $now,
    ): MaintenanceStatus {
        // An explicit statement by the maintainer beats any inference we could
        // make from activity.
        if ($abandoned) {
            return MaintenanceStatus::Abandoned;
        }

        if (null === $lastCommit || [] === $latestVersions) {
            return MaintenanceStatus::Unknown;
        }

        $newest = $latestVersions[0];

        if ($lastCommit > $newest->getReleasedAt()) {
            return MaintenanceStatus::Current;
        }

        $previous = $latestVersions[1] ?? null;

        if (null === $previous || $lastCommit > $previous->getReleasedAt()) {
            return MaintenanceStatus::Lagging;
        }

        // Silent across two or more minors. Only call it dormant if it has also
        // been quiet long enough in absolute terms.
        $floor = $now->modify(\sprintf('-%d months', self::DORMANT_AFTER_MONTHS));

        return $lastCommit < $floor
            ? MaintenanceStatus::Dormant
            : MaintenanceStatus::Lagging;
    }
}
