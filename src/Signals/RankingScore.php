<?php

declare(strict_types=1);

namespace App\Signals;

use App\Compatibility\Enum\ConstraintTier;
use App\License\Enum\LicenseStatus;
use App\Signals\Enum\MaintenanceStatus;

/**
 * The ranking formula, in one place, as public constants.
 *
 * The conflict-of-interest rule requires the ranking algorithm to be public, and this class is how
 * that promise is kept literally rather than aspirationally: the /ranking page
 * renders these same constants, so the published explanation cannot drift from the
 * code that ranks. There is no manual boosting, no featured slot and no editorial
 * override anywhere in the pipeline, and, per the conflict-of-interest rule, no vendor is consulted here at
 * all, including the maintainer's own.
 *
 * **Stars and forks are deliberately absent.** the ranking guidance is blunt that they mislead in this
 * ecosystem: an excellent extension from a German agency may have twelve stars,
 * while an abandoned toy has hundreds. Ranking on popularity would systematically
 * bury exactly the agency-built extensions merchants most need to find. Sorting the
 * corpus by stars is still offered in the UI, because a visitor may genuinely want
 * it, it just is not the default and does not feed the score.
 */
final class RankingScore
{
    /**
     * Does it work with the Shopware you are running? This is the question the
     * directory exists to answer, so it dominates the score.
     */
    public const WEIGHT_COMPATIBILITY = 0.35;

    /** Is anyone still looking after it? */
    public const WEIGHT_MAINTENANCE = 0.22;

    /** How much can the compatibility claim be trusted (see ConstraintTier)? */
    public const WEIGHT_CONSTRAINT_QUALITY = 0.15;

    /** Can it legally be used and redistributed, or is the licence unclear? */
    public const WEIGHT_LICENCE = 0.10;

    /** Do issues get closed? A weak proxy for "is anyone home?". */
    public const WEIGHT_RESPONSIVENESS = 0.08;

    /**
     * Continuous decay on time since the last commit.
     *
     * Exists to break ties. Every other component is categorical, so a healthy
     * extension saturates them all and lands on exactly 100, the first scoring run
     * produced ten extensions tied at the top, which means the most valuable
     * position in the directory was being ordered by row id. A tie-break has to
     * come from somewhere, and resolving it by insertion order is not a public
     * algorithm in any meaningful sense.
     *
     * Recency is the right tie-breaker because it measures the same thing the
     * directory is for. Stars would also break ties, and are deliberately not used:
     * The ranking guidance is explicit that popularity misleads here, and it would mislead just as
     * much at the top of the list as anywhere else.
     */
    public const WEIGHT_RECENCY = 0.10;

    /**
     * Days after which recency has decayed to 1/e (~0.37). A year is roughly the
     * gap between Shopware minors, so an extension untouched for a full release
     * cycle loses most of this component.
     */
    public const RECENCY_HALF_LIFE_DAYS = 365;

    /**
     * Credit for supporting the Shopware version before the current one. Partial
     * rather than zero: a merchant on the previous minor is a real user, and an
     * extension that supports them is genuinely more useful than one supporting
     * neither.
     */
    public const CREDIT_PREVIOUS_VERSION = 0.5;

    /**
     * Used when a repository has no issues at all, so no ratio can be computed.
     * Neutral by design, a young extension should be neither rewarded nor
     * punished for having no issue history.
     */
    public const NEUTRAL_RESPONSIVENESS = 0.5;

    /**
     * @return float score in [0, 100]
     */
    public function score(
        bool $supportsCurrent,
        bool $supportsPrevious,
        MaintenanceStatus $maintenance,
        ConstraintTier $tier,
        LicenseStatus $licence,
        ?float $issueCloseRatio,
        ?\DateTimeImmutable $lastCommit = null,
        ?\DateTimeImmutable $now = null,
    ): float {
        $components = $this->components(
            $supportsCurrent,
            $supportsPrevious,
            $maintenance,
            $tier,
            $licence,
            $issueCloseRatio,
            $lastCommit,
            $now,
        );

        return round(array_sum($components) * 100, 2);
    }

    /**
     * The weighted contribution of each factor, for the transparency page and for
     * explaining a specific extension's position to whoever asks.
     *
     * @return array<string, float>
     */
    public function components(
        bool $supportsCurrent,
        bool $supportsPrevious,
        MaintenanceStatus $maintenance,
        ConstraintTier $tier,
        LicenseStatus $licence,
        ?float $issueCloseRatio,
        ?\DateTimeImmutable $lastCommit = null,
        ?\DateTimeImmutable $now = null,
    ): array {
        $compatibility = match (true) {
            $supportsCurrent => 1.0,
            $supportsPrevious => self::CREDIT_PREVIOUS_VERSION,
            default => 0.0,
        };

        return [
            'compatibility' => self::WEIGHT_COMPATIBILITY * $compatibility,
            'maintenance' => self::WEIGHT_MAINTENANCE * $maintenance->rankingWeight(),
            'constraint_quality' => self::WEIGHT_CONSTRAINT_QUALITY * $tier->rankingWeight(),
            'licence' => self::WEIGHT_LICENCE * ($licence->isRedistributable() ? 1.0 : 0.0),
            'responsiveness' => self::WEIGHT_RESPONSIVENESS
                * ($issueCloseRatio ?? self::NEUTRAL_RESPONSIVENESS),
            'recency' => self::WEIGHT_RECENCY * $this->recency($lastCommit, $now),
        ];
    }

    /**
     * Exponential decay on days since the last commit, in [0, 1].
     *
     * Never negative and never above 1, so a repository with a commit dated in the
     * future, clock skew and rewritten history both produce these, cannot buy
     * itself a higher score than one committed today.
     */
    private function recency(?\DateTimeImmutable $lastCommit, ?\DateTimeImmutable $now): float
    {
        if (null === $lastCommit) {
            return 0.0;
        }

        $now ??= new \DateTimeImmutable();
        $days = ($now->getTimestamp() - $lastCommit->getTimestamp()) / 86400;

        if ($days <= 0) {
            return 1.0;
        }

        return exp(-$days / self::RECENCY_HALF_LIFE_DAYS);
    }

    /**
     * The weights as rendered on the public /ranking page.
     *
     * @return array<string, array{float, string}>
     */
    public static function published(): array
    {
        return [
            'Compatibility with current Shopware' => [
                self::WEIGHT_COMPATIBILITY,
                'Whether a stable release declares support for the newest Shopware minor. '
                .'Half credit for the one before it.',
            ],
            'Maintenance' => [
                self::WEIGHT_MAINTENANCE,
                'Measured against Shopware release dates, not the calendar. Has the repository '
                .'been touched since the current Shopware shipped?',
            ],
            'Quality of the compatibility claim' => [
                self::WEIGHT_CONSTRAINT_QUALITY,
                'A bounded constraint such as ~6.6.0 states which versions were considered. An '
                .'open one such as ^6.5 claims every future minor, and scores lower.',
            ],
            'Licence clarity' => [
                self::WEIGHT_LICENCE,
                'An extension with no detectable licence cannot be redistributed, whatever its '
                .'code is like.',
            ],
            'Responsiveness' => [
                self::WEIGHT_RESPONSIVENESS,
                'The share of issues closed. Repositories with no issues score neutrally.',
            ],
            'Recency' => [
                self::WEIGHT_RECENCY,
                'How long ago the last commit landed, decaying over about a year. The tie-breaker: '
                .'without it every healthy extension scores the same.',
            ],
        ];
    }
}
