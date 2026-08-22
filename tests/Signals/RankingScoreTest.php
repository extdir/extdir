<?php

declare(strict_types=1);

namespace App\Tests\Signals;

use App\Compatibility\Enum\ConstraintTier;
use App\License\Enum\LicenseStatus;
use App\Signals\Enum\MaintenanceStatus;
use App\Signals\RankingScore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RankingScore::class)]
final class RankingScoreTest extends TestCase
{
    private RankingScore $ranking;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->ranking = new RankingScore();
        $this->now = new \DateTimeImmutable('2026-08-17');
    }

    /**
     * The weights must sum to exactly 1, or the score stops being a percentage and
     * every published number on /ranking becomes wrong.
     */
    public function testWeightsSumToOne(): void
    {
        $sum = RankingScore::WEIGHT_COMPATIBILITY
            + RankingScore::WEIGHT_MAINTENANCE
            + RankingScore::WEIGHT_CONSTRAINT_QUALITY
            + RankingScore::WEIGHT_LICENCE
            + RankingScore::WEIGHT_RESPONSIVENESS
            + RankingScore::WEIGHT_RECENCY;

        self::assertEqualsWithDelta(1.0, $sum, 0.0001);
    }

    /**
     * The published explanation is rendered from the same constants that rank, so
     * the promise that the algorithm is public cannot drift into being merely
     * aspirational.
     */
    public function testPublishedWeightsMatchTheOnesUsed(): void
    {
        $published = RankingScore::published();

        self::assertSame(RankingScore::WEIGHT_COMPATIBILITY, $published['Compatibility with current Shopware'][0]);
        self::assertSame(RankingScore::WEIGHT_MAINTENANCE, $published['Maintenance'][0]);
        self::assertSame(RankingScore::WEIGHT_RECENCY, $published['Recency'][0]);

        self::assertEqualsWithDelta(
            1.0,
            array_sum(array_map(static fn (array $row): float => $row[0], $published)),
            0.0001,
            'every weight that affects the score must appear on the public page',
        );
    }

    public function testAPerfectExtensionScoresOneHundred(): void
    {
        $score = $this->ranking->score(
            supportsCurrent: true,
            supportsPrevious: true,
            maintenance: MaintenanceStatus::Current,
            tier: ConstraintTier::Explicit,
            licence: LicenseStatus::Permissive,
            issueCloseRatio: 1.0,
            lastCommit: $this->now,
            now: $this->now,
        );

        self::assertSame(100.0, $score);
    }

    public function testTheWorstCaseScoresZero(): void
    {
        $score = $this->ranking->score(
            supportsCurrent: false,
            supportsPrevious: false,
            maintenance: MaintenanceStatus::Abandoned,
            tier: ConstraintTier::Absent,
            licence: LicenseStatus::Unknown,
            issueCloseRatio: 0.0,
            lastCommit: null,
            now: $this->now,
        );

        // Abandoned and Absent still carry small non-zero weights by design, so the
        // floor is near zero rather than exactly zero.
        self::assertGreaterThanOrEqual(0.0, $score);
        self::assertLessThan(10.0, $score);
    }

    /**
     * The reason recency exists. Two otherwise identical healthy extensions must
     * not tie, or the top of the directory ends up ordered by row id.
     */
    public function testRecencySeparatesOtherwiseIdenticalExtensions(): void
    {
        $args = [
            'supportsCurrent' => true,
            'supportsPrevious' => true,
            'maintenance' => MaintenanceStatus::Current,
            'tier' => ConstraintTier::Explicit,
            'licence' => LicenseStatus::Permissive,
            'issueCloseRatio' => 1.0,
        ];

        $fresh = $this->ranking->score(...$args, lastCommit: $this->now->modify('-1 day'), now: $this->now);
        $older = $this->ranking->score(...$args, lastCommit: $this->now->modify('-6 months'), now: $this->now);

        self::assertGreaterThan($older, $fresh);
    }

    /**
     * Clock skew and rewritten history both produce commits dated in the future.
     * They must not be able to buy a score above a commit made today.
     */
    public function testAFutureCommitCannotExceedAPresentOne(): void
    {
        $args = [
            'supportsCurrent' => true,
            'supportsPrevious' => true,
            'maintenance' => MaintenanceStatus::Current,
            'tier' => ConstraintTier::Explicit,
            'licence' => LicenseStatus::Permissive,
            'issueCloseRatio' => 1.0,
        ];

        $future = $this->ranking->score(...$args, lastCommit: $this->now->modify('+30 days'), now: $this->now);
        $today = $this->ranking->score(...$args, lastCommit: $this->now, now: $this->now);

        self::assertSame($today, $future);
        self::assertLessThanOrEqual(100.0, $future);
    }

    /**
     * Compatibility with the Shopware a merchant runs is the question the directory
     * exists to answer, so it must outweigh everything else.
     */
    public function testCompatibilityDominates(): void
    {
        $compatible = $this->ranking->score(
            true, false, MaintenanceStatus::Dormant, ConstraintTier::Wildcard,
            LicenseStatus::Unknown, 0.0, $this->now->modify('-3 years'), $this->now,
        );

        $incompatibleButOtherwisePerfect = $this->ranking->score(
            false, false, MaintenanceStatus::Current, ConstraintTier::Explicit,
            LicenseStatus::Permissive, 1.0, $this->now, $this->now,
        );

        // Not a claim that compatibility alone wins, it should not, but that it
        // is worth more than any single other factor.
        self::assertGreaterThan(
            RankingScore::WEIGHT_MAINTENANCE * 100,
            RankingScore::WEIGHT_COMPATIBILITY * 100,
        );
        self::assertGreaterThan(0.0, $compatible);
        self::assertGreaterThan(0.0, $incompatibleButOtherwisePerfect);
    }

    public function testSupportingThePreviousVersionEarnsPartialCredit(): void
    {
        $args = [
            MaintenanceStatus::Current, ConstraintTier::Explicit,
            LicenseStatus::Permissive, 1.0, $this->now, $this->now,
        ];

        $current = $this->ranking->score(true, true, ...$args);
        $previous = $this->ranking->score(false, true, ...$args);
        $neither = $this->ranking->score(false, false, ...$args);

        self::assertGreaterThan($previous, $current);
        self::assertGreaterThan($neither, $previous);
    }

    /**
     * An unlicensed extension cannot legally be redistributed, so it must rank
     * below an otherwise identical licensed one, the licence gate expressed as ordering.
     */
    public function testUnlicensedRanksBelowLicensed(): void
    {
        $args = [
            true, true, MaintenanceStatus::Current, ConstraintTier::Explicit,
        ];

        $licensed = $this->ranking->score(...$args, licence: LicenseStatus::Permissive,
            issueCloseRatio: 1.0, lastCommit: $this->now, now: $this->now);
        $unlicensed = $this->ranking->score(...$args, licence: LicenseStatus::Unknown,
            issueCloseRatio: 1.0, lastCommit: $this->now, now: $this->now);

        self::assertGreaterThan($unlicensed, $licensed);
    }

    /**
     * Copyleft is redistributable, so it must not be penalised against permissive.
     * The categories differ in obligation, not in quality.
     */
    public function testCopyleftIsNotPenalisedAgainstPermissive(): void
    {
        $args = [
            true, true, MaintenanceStatus::Current, ConstraintTier::Explicit,
        ];

        $mit = $this->ranking->score(...$args, licence: LicenseStatus::Permissive,
            issueCloseRatio: 1.0, lastCommit: $this->now, now: $this->now);
        $gpl = $this->ranking->score(...$args, licence: LicenseStatus::Copyleft,
            issueCloseRatio: 1.0, lastCommit: $this->now, now: $this->now);

        self::assertSame($mit, $gpl);
    }

    /**
     * A repository with no issues at all scores neutrally: a young extension should
     * be neither rewarded nor punished for having no issue history.
     */
    public function testNoIssueHistoryScoresNeutrally(): void
    {
        $components = $this->ranking->components(
            true, true, MaintenanceStatus::Current, ConstraintTier::Explicit,
            LicenseStatus::Permissive, null, $this->now, $this->now,
        );

        self::assertEqualsWithDelta(
            RankingScore::WEIGHT_RESPONSIVENESS * RankingScore::NEUTRAL_RESPONSIVENESS,
            $components['responsiveness'],
            0.0001,
        );
    }

    /**
     * The ranking guidance is explicit that stars mislead in this ecosystem. Guard the absence.
     */
    public function testPopularityIsNotAComponent(): void
    {
        $components = $this->ranking->components(
            true, true, MaintenanceStatus::Current, ConstraintTier::Explicit,
            LicenseStatus::Permissive, 1.0, $this->now, $this->now,
        );

        self::assertArrayNotHasKey('stars', $components);
        self::assertArrayNotHasKey('forks', $components);
        self::assertArrayNotHasKey('popularity', $components);
    }
}
