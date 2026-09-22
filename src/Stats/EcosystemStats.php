<?php

declare(strict_types=1);

namespace App\Stats;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Aggregates over the whole catalogue, for the statistics page.
 *
 * Raw DBAL rather than the ORM, for the same reason ExtensionSearch is: every query
 * here is a GROUP BY that returns scalars, and hydrating a few thousand entities to
 * count them would be absurd. The results are plain arrays, so the geometry layer and
 * the templates never touch a database row.
 *
 * Two rules govern everything in this class.
 *
 * Delisted extensions are excluded everywhere, at the same gate every other aggregate
 * in the codebase applies. A takedown that still shows up in a total is a takedown that
 * only half happened, and a total is the easiest place for one to survive unnoticed.
 *
 * Every method has a matching rule string on the page, because section 4.6 forbids
 * ordering a reader cannot check. If you change a query here, change the rule with it,
 * or the page starts claiming something the SQL no longer does.
 */
final readonly class EcosystemStats
{
    /**
     * Repeated rather than imported from ExtensionSearch::VISIBLE_STATUSES, which is
     * private. Two copies of one list is a real risk, so a test asserts they agree.
     */
    private const array VISIBLE = ['listed', 'index_only'];

    /**
     * The catalogue holds tags back to 2019, but the first Shopware 6 release was
     * October 2019 and the handful of earlier tags are Shopware 5 heritage that
     * happens to sit in the same repository. Starting the charts here keeps a
     * five-tag year from occupying an eighth of the x-axis.
     */
    private const int FIRST_YEAR = 2019;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * The headline numbers, for the row above the charts.
     *
     * @return array{extensions: int, releases: int, vendors: int, onCurrent: int, redistributable: int}
     */
    public function headline(): array
    {
        $visible = $this->visibleCondition();

        $row = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*) AS extensions,
                SUM(CASE WHEN e.license_status IN ('permissive','copyleft') THEN 1 ELSE 0 END) AS redistributable
             FROM extension e WHERE {$visible}",
            ['visible' => self::VISIBLE],
            ['visible' => ArrayParameterType::STRING],
        ) ?: [];

        return [
            'extensions' => (int) ($row['extensions'] ?? 0),
            'redistributable' => (int) ($row['redistributable'] ?? 0),
            'releases' => $this->scalar(
                "SELECT COUNT(*) FROM extension_release r JOIN extension e ON e.id = r.extension_id WHERE {$visible}",
            ),
            'vendors' => $this->scalar(
                "SELECT COUNT(DISTINCT e.vendor_id) FROM extension e WHERE {$visible}",
            ),
            'onCurrent' => $this->scalar(
                "SELECT COUNT(DISTINCT c.extension_id)
                 FROM compatibility_claim c
                 JOIN extension e ON e.id = c.extension_id
                 JOIN extension_release r ON r.id = c.release_id AND r.stable = 1
                 JOIN shopware_version sv ON sv.id = c.shopware_version_id AND sv.current = 1
                 WHERE c.satisfied = 1 AND {$visible}",
            ),
        ];
    }

    /**
     * When each extension first shipped a tagged release.
     *
     * The honest answer to "how many were published that year", and deliberately not
     * extension.first_seen_at, which records when this crawler found a package. Every
     * row of that column is the week extdir started, so a chart of it would draw our
     * own crawl schedule and read as ecosystem growth.
     *
     * Truncated for repositories that are not on Packagist: those are ingested from
     * GitHub tags, capped at the newest thirty, so an old repository with more than
     * thirty tags reports a first release later than the truth. The page says so.
     *
     * @return list<array{label: string, value: int, partial: bool}>
     */
    public function newExtensionsPerYear(): array
    {
        $visible = $this->visibleCondition();

        return $this->years(
            "SELECT YEAR(f.first_release) AS yr, COUNT(*) AS n FROM (
                SELECT r.extension_id, MIN(r.released_at) AS first_release
                FROM extension_release r
                JOIN extension e ON e.id = r.extension_id
                WHERE r.released_at IS NOT NULL AND {$visible}
                GROUP BY r.extension_id
             ) f
             WHERE YEAR(f.first_release) >= :firstYear
             GROUP BY yr ORDER BY yr",
        );
    }

    /**
     * Tagged stable releases per year, across the whole catalogue.
     *
     * A different question from the one above: that chart counts extensions arriving,
     * this one counts work being done on the ones already here.
     *
     * @return list<array{label: string, value: int, partial: bool}>
     */
    public function releasesPerYear(): array
    {
        $visible = $this->visibleCondition();

        return $this->years(
            "SELECT YEAR(r.released_at) AS yr, COUNT(*) AS n
             FROM extension_release r
             JOIN extension e ON e.id = r.extension_id
             WHERE r.released_at IS NOT NULL AND r.stable = 1
               AND YEAR(r.released_at) >= :firstYear AND {$visible}
             GROUP BY yr ORDER BY yr",
        );
    }

    /**
     * Releases by calendar month, every year stacked together.
     *
     * Seasonality rather than trend. Agencies take August off and freeze before
     * Christmas, and the shape of this chart is the only place the catalogue says so.
     * Twelve buckets, so a month with no releases in any year would still be drawn.
     *
     * @return list<array{label: string, value: int, partial: bool}>
     */
    public function releasesByCalendarMonth(): array
    {
        $visible = $this->visibleCondition();

        $rows = $this->connection->fetchAllAssociative(
            "SELECT MONTH(r.released_at) AS m, COUNT(*) AS n
             FROM extension_release r
             JOIN extension e ON e.id = r.extension_id
             WHERE r.released_at IS NOT NULL AND r.stable = 1 AND {$visible}
             GROUP BY m",
            ['visible' => self::VISIBLE],
            ['visible' => ArrayParameterType::STRING],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['m']] = (int) $row['n'];
        }

        $months = [];
        foreach (range(1, 12) as $month) {
            $months[] = [
                // Built from a fixed date rather than mktime, which reports failure
                // by returning false and would hand a chart the label "Thu".
                'label' => (new \DateTimeImmutable(\sprintf('2001-%02d-01', $month)))->format('M'),
                'value' => $counts[$month] ?? 0,
                'partial' => false,
            ];
        }

        return $months;
    }

    /**
     * How quickly extensions came to declare support for each Shopware minor.
     *
     * The one chart here that answers a question nobody else in this ecosystem can.
     * The x axis is months since that Shopware version shipped rather than calendar
     * time, which is what makes the lines comparable: it shows whether 6.7 is being
     * adopted faster than 6.6 was, rather than simply later.
     *
     * Month zero is not an adoption event. It holds every extension whose constraint
     * already covered the version on the day it shipped, usually an open-ended caret
     * that was never tested against it. That height is worth reading on its own, and
     * the page says what it means rather than letting it pass as enthusiasm.
     *
     * @return list<array{version: string, releasedAt: string, points: list<array{month: int, value: int}>}>
     */
    public function shopwareAdoption(int $months = 24): array
    {
        $visible = $this->visibleCondition();

        $rows = $this->connection->fetchAllAssociative(
            "SELECT sv.major_minor AS ver,
                    sv.released_at AS released_at,
                    GREATEST(0, TIMESTAMPDIFF(MONTH, sv.released_at, f.first_support)) AS offset_months,
                    COUNT(*) AS n
             FROM (
                SELECT c.shopware_version_id AS svid, c.extension_id AS eid, MIN(r.released_at) AS first_support
                FROM compatibility_claim c
                JOIN extension_release r ON r.id = c.release_id AND r.stable = 1
                JOIN extension e ON e.id = c.extension_id
                WHERE c.satisfied = 1 AND r.released_at IS NOT NULL AND {$visible}
                GROUP BY c.shopware_version_id, c.extension_id
             ) f
             JOIN shopware_version sv ON sv.id = f.svid AND sv.shown_in_matrix = 1
             GROUP BY ver, released_at, offset_months
             ORDER BY sv.sort_order, offset_months",
            ['visible' => self::VISIBLE],
            ['visible' => ArrayParameterType::STRING],
        );

        /** @var array<string, array{version: string, releasedAt: string, buckets: array<int, int>}> $byVersion */
        $byVersion = [];
        foreach ($rows as $row) {
            $version = (string) $row['ver'];
            $byVersion[$version] ??= [
                'version' => $version,
                'releasedAt' => (string) $row['released_at'],
                'buckets' => [],
            ];
            $byVersion[$version]['buckets'][(int) $row['offset_months']] = (int) $row['n'];
        }

        $now = new \DateTimeImmutable();
        $series = [];

        foreach ($byVersion as $entry) {
            // A line may only run as far as the version has actually existed. Drawing
            // 6.7 flat out to month 24 would claim adoption stalled, when the truth is
            // that those months have not happened yet.
            $shipped = new \DateTimeImmutable($entry['releasedAt']);
            $since = $shipped->diff($now);
            $elapsed = $since->y * 12 + $since->m;

            $running = 0;
            $points = [];

            foreach (range(0, min($months, $elapsed)) as $month) {
                $running += $entry['buckets'][$month] ?? 0;
                $points[] = ['month' => $month, 'value' => $running];
            }

            $series[] = [
                'version' => $entry['version'],
                'releasedAt' => $entry['releasedAt'],
                'points' => $points,
            ];
        }

        return $series;
    }

    /**
     * How precisely the catalogue declares compatibility, one row per extension.
     *
     * Counted on each extension's newest stable release rather than across every
     * release, because what matters is what a merchant installing today would read.
     *
     * This is the measurement behind a complaint made publicly about this ecosystem:
     * that compatibility declarations on Packagist and GitHub cannot be trusted. An
     * absent constraint is not a wrong answer, it is no answer, and a wildcard claims
     * every version that will ever exist.
     *
     * @return list<array{label: string, value: int, partial: bool}>
     */
    public function constraintQuality(): array
    {
        $visible = $this->visibleCondition();

        $rows = $this->connection->fetchAllAssociative(
            "SELECT newest.constraint_tier AS tier, COUNT(*) AS n
             FROM (
                SELECT r.extension_id, r.constraint_tier,
                       ROW_NUMBER() OVER (PARTITION BY r.extension_id ORDER BY r.released_at DESC, r.id DESC) AS rn
                FROM extension_release r
                JOIN extension e ON e.id = r.extension_id
                WHERE r.stable = 1 AND {$visible}
             ) newest
             WHERE newest.rn = 1
             GROUP BY tier",
            ['visible' => self::VISIBLE],
            ['visible' => ArrayParameterType::STRING],
        );

        $labels = [
            'explicit' => 'Explicit version',
            'caret' => 'Caret range',
            'wildcard' => 'Wildcard',
            'absent' => 'Nothing declared',
        ];

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['tier']] = (int) $row['n'];
        }

        $out = [];
        foreach ($labels as $tier => $label) {
            $out[] = ['label' => $label, 'value' => $counts[$tier] ?? 0, 'partial' => false];
        }

        return $out;
    }

    /**
     * How long ago each extension last shipped, in buckets.
     *
     * The dormancy tail. The maintenance mix says how many are current; this says how
     * far gone the rest are, which is the difference between a quiet quarter and an
     * extension nobody has touched since Shopware 6.4.
     *
     * @return list<array{label: string, value: int, partial: bool}>
     */
    public function timeSinceLastRelease(): array
    {
        $visible = $this->visibleCondition();

        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                CASE
                    WHEN e.last_release_at IS NULL THEN 'never'
                    WHEN e.last_release_at >= NOW() - INTERVAL 3 MONTH THEN 'q'
                    WHEN e.last_release_at >= NOW() - INTERVAL 12 MONTH THEN 'y'
                    WHEN e.last_release_at >= NOW() - INTERVAL 24 MONTH THEN 'y2'
                    ELSE 'old'
                END AS bucket,
                COUNT(*) AS n
             FROM extension e WHERE {$visible}
             GROUP BY bucket",
            ['visible' => self::VISIBLE],
            ['visible' => ArrayParameterType::STRING],
        );

        $labels = [
            'q' => 'Within 3 months',
            'y' => '3 to 12 months',
            'y2' => '1 to 2 years',
            'old' => 'Over 2 years',
            'never' => 'No tagged release',
        ];

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['bucket']] = (int) $row['n'];
        }

        $out = [];
        foreach ($labels as $bucket => $label) {
            $out[] = ['label' => $label, 'value' => $counts[$bucket] ?? 0, 'partial' => false];
        }

        return $out;
    }

    /**
     * Share of each category declaring support for each Shopware version.
     *
     * A share rather than a count, because the categories differ in size by a factor
     * of sixty and a count would draw nothing but the size of the category. What the
     * reader wants is which areas have kept up, and that is a proportion.
     *
     * @return array{versions: list<string>, rows: list<array{label: string, total: int, cells: list<array{version: string, value: int, share: float}>}>}
     */
    public function categoryByVersion(): array
    {
        $visible = $this->visibleCondition();

        $versions = array_map(
            static fn (array $row): string => (string) $row['major_minor'],
            $this->connection->fetchAllAssociative(
                'SELECT major_minor FROM shopware_version WHERE shown_in_matrix = 1 ORDER BY sort_order',
            ),
        );

        $totals = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT c.category_key AS k, c.label AS lbl, COUNT(DISTINCT e.id) AS n
             FROM category c
             JOIN extension_category ec ON ec.category_id = c.id
             JOIN extension e ON e.id = ec.extension_id
             WHERE {$visible}
             GROUP BY c.id ORDER BY n DESC",
            ['visible' => self::VISIBLE],
            ['visible' => ArrayParameterType::STRING],
        ) as $row) {
            $totals[(string) $row['k']] = ['label' => (string) $row['lbl'], 'total' => (int) $row['n']];
        }

        $supported = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT c.category_key AS k, sv.major_minor AS ver, COUNT(DISTINCT e.id) AS n
             FROM compatibility_claim cc
             JOIN extension e ON e.id = cc.extension_id
             JOIN extension_release r ON r.id = cc.release_id AND r.stable = 1
             JOIN shopware_version sv ON sv.id = cc.shopware_version_id AND sv.shown_in_matrix = 1
             JOIN extension_category ec ON ec.extension_id = e.id
             JOIN category c ON c.id = ec.category_id
             WHERE cc.satisfied = 1 AND {$visible}
             GROUP BY c.id, sv.id",
            ['visible' => self::VISIBLE],
            ['visible' => ArrayParameterType::STRING],
        ) as $row) {
            $supported[(string) $row['k']][(string) $row['ver']] = (int) $row['n'];
        }

        $rows = [];
        foreach ($totals as $key => $category) {
            $cells = [];
            foreach ($versions as $version) {
                $value = $supported[$key][$version] ?? 0;
                $cells[] = [
                    'version' => $version,
                    'value' => $value,
                    'share' => $category['total'] > 0 ? $value / $category['total'] : 0.0,
                ];
            }

            $rows[] = ['label' => $category['label'], 'total' => $category['total'], 'cells' => $cells];
        }

        return ['versions' => $versions, 'rows' => $rows];
    }

    /**
     * Turns a year-keyed query into chart rows, filling years that produced nothing.
     *
     * A gap year must be drawn as a zero rather than skipped. Omitting it would close
     * the gap and shorten the axis, which quietly redraws a quiet year as though it
     * never happened.
     *
     * @return list<array{label: string, value: int, partial: bool}>
     */
    private function years(string $sql): array
    {
        $rows = $this->connection->fetchAllAssociative(
            $sql,
            ['visible' => self::VISIBLE, 'firstYear' => self::FIRST_YEAR],
            ['visible' => ArrayParameterType::STRING],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['yr']] = (int) $row['n'];
        }

        $thisYear = (int) date('Y');
        $out = [];

        foreach (range(self::FIRST_YEAR, $thisYear) as $year) {
            $out[] = [
                'label' => (string) $year,
                'value' => $counts[$year] ?? 0,
                // The current year is still running, so its column is short for a
                // reason that has nothing to do with the ecosystem. Marked rather
                // than hidden: dropping it would be worse, and drawing it plain
                // would invite reading a decline that is really a calendar.
                'partial' => $year === $thisYear,
            ];
        }

        return $out;
    }

    private function scalar(string $sql): int
    {
        return (int) $this->connection->fetchOne(
            $sql,
            ['visible' => self::VISIBLE],
            ['visible' => ArrayParameterType::STRING],
        );
    }

    private function visibleCondition(): string
    {
        return 'e.index_status IN (:visible)';
    }
}
