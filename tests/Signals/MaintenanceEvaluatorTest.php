<?php

declare(strict_types=1);

namespace App\Tests\Signals;

use App\Compatibility\Entity\ShopwareVersion;
use App\Compatibility\Repository\ShopwareVersionRepository;
use App\Signals\Enum\MaintenanceStatus;
use App\Signals\MaintenanceEvaluator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaintenanceEvaluator::class)]
final class MaintenanceEvaluatorTest extends TestCase
{
    private MaintenanceEvaluator $evaluator;

    protected function setUp(): void
    {
        // evaluateAgainst() is pure and never touches the repository, every test
        // here passes the version timeline in directly. A stub rather than a mock,
        // because there is no interaction to verify.
        $this->evaluator = new MaintenanceEvaluator(
            $this->createStub(ShopwareVersionRepository::class),
        );
    }

    #[DataProvider('timelineProvider')]
    public function testStatusIsMeasuredAgainstShopwareReleases(
        string $lastCommit,
        MaintenanceStatus $expected,
        string $because,
    ): void {
        $status = $this->evaluator->evaluateAgainst(
            new \DateTimeImmutable($lastCommit),
            false,
            self::timeline(),
            new \DateTimeImmutable('2026-08-17'),
        );

        self::assertSame($expected, $status, $because);
    }

    /**
     * Real Shopware release dates: 6.7 shipped 2025-06-10, 6.6 on 2024-03-18,
     * 6.5 on 2023-05-03. "Now" is 2026-08-17.
     *
     * @return iterable<string, array{string, MaintenanceStatus, string}>
     */
    public static function timelineProvider(): iterable
    {
        yield 'committed after 6.7 shipped' => [
            '2026-08-01', MaintenanceStatus::Current,
            'activity after the newest minor is the definition of current',
        ];
        yield 'committed the day 6.7 shipped' => [
            '2025-06-11', MaintenanceStatus::Current,
            'anything after the release date counts',
        ];
        yield 'quiet since 6.7 but active after 6.6' => [
            '2025-01-01', MaintenanceStatus::Lagging,
            'missed the newest minor but not the one before',
        ];
        yield 'quiet across 6.6 and 6.7, idle over two years' => [
            '2024-01-01', MaintenanceStatus::Dormant,
            'silent across two minors and past the absolute floor',
        ];
        yield 'ancient' => [
            '2021-06-01', MaintenanceStatus::Dormant,
            'silent for years',
        ];
    }

    /**
     * The absolute floor exists because Shopware minors have shipped as little as
     * five months apart. Without it, a burst of quick releases would sweep healthy
     * extensions into Dormant purely because two version numbers went by, the
     * ecosystem moving fast is not evidence that a plugin was abandoned.
     */
    public function testRapidReleasesDoNotStrandAnActiveExtension(): void
    {
        $rapid = [
            new ShopwareVersion('6.9', '6.9.0.0', '6.10.0.0', new \DateTimeImmutable('2026-07-01'), 2),
            new ShopwareVersion('6.8', '6.8.0.0', '6.9.0.0', new \DateTimeImmutable('2026-04-01'), 1),
            new ShopwareVersion('6.7', '6.7.0.0', '6.8.0.0', new \DateTimeImmutable('2026-01-01'), 0),
        ];

        // Silent across 6.8 and 6.9, but only ~7 months, well inside the floor.
        $status = $this->evaluator->evaluateAgainst(
            new \DateTimeImmutable('2026-02-01'),
            false,
            $rapid,
            new \DateTimeImmutable('2026-08-17'),
        );

        self::assertSame(MaintenanceStatus::Lagging, $status);
        self::assertNotSame(MaintenanceStatus::Dormant, $status);
    }

    /**
     * The failure mode the calendar rule in the ranking guidance would produce: a small, finished
     * plugin that still works and whose ecosystem simply has not moved.
     */
    public function testATwoYearOldCommitIsNotDormantIfNoNewShopwareShipped(): void
    {
        $slow = [
            new ShopwareVersion('6.7', '6.7.0.0', '6.8.0.0', new \DateTimeImmutable('2024-01-01'), 0),
        ];

        $status = $this->evaluator->evaluateAgainst(
            new \DateTimeImmutable('2024-06-01'),
            false,
            $slow,
            new \DateTimeImmutable('2026-08-17'),
        );

        self::assertSame(MaintenanceStatus::Current, $status, 'still active relative to the only minor');
    }

    /**
     * An explicit statement by the maintainer beats anything we could infer.
     */
    public function testPackagistAbandonmentOverridesActivity(): void
    {
        $status = $this->evaluator->evaluateAgainst(
            new \DateTimeImmutable('2026-08-16'),
            true,
            self::timeline(),
            new \DateTimeImmutable('2026-08-17'),
        );

        self::assertSame(MaintenanceStatus::Abandoned, $status);
        self::assertTrue($status->warrantsWarning());
    }

    public function testNoCommitDataIsUnknownRatherThanADamningGuess(): void
    {
        self::assertSame(
            MaintenanceStatus::Unknown,
            $this->evaluator->evaluateAgainst(null, false, self::timeline(), new \DateTimeImmutable()),
        );
    }

    public function testWithoutAVersionTimelineNothingCanBeConcluded(): void
    {
        self::assertSame(
            MaintenanceStatus::Unknown,
            $this->evaluator->evaluateAgainst(
                new \DateTimeImmutable('2026-08-01'),
                false,
                [],
                new \DateTimeImmutable('2026-08-17'),
            ),
        );
    }

    /**
     * @return list<ShopwareVersion>
     */
    private static function timeline(): array
    {
        return [
            new ShopwareVersion('6.7', '6.7.0.0', '6.8.0.0', new \DateTimeImmutable('2025-06-10'), 3),
            new ShopwareVersion('6.6', '6.6.0.0', '6.7.0.0', new \DateTimeImmutable('2024-03-18'), 2),
            new ShopwareVersion('6.5', '6.5.0.0', '6.6.0.0', new \DateTimeImmutable('2023-05-03'), 1),
        ];
    }
}
