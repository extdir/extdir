<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Ui\Twig\RelativeTimeExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Relative dates, in two registers.
 *
 * `ago` is terse because it lives in a table column that is read by scanning, "2 d"
 * against forty rows, where "2 days ago" would be noise. `ago_phrase` is the same
 * span in a sentence, and exists because appending " ago" to the terse form produced
 * "crawled today ago" on every extension page crawled that day.
 */
final class RelativeTimeTest extends TestCase
{
    private const string NOW = '2026-08-20 12:00:00';

    #[DataProvider('spans')]
    public function testTerseFormIsForScanning(string $moment, string $expected): void
    {
        self::assertSame($expected, $this->extension()->ago(new \DateTimeImmutable($moment)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function spans(): iterable
    {
        yield 'same day' => ['2026-08-20 09:00:00', 'today'];
        yield 'yesterday' => ['2026-08-19 09:00:00', '1 d'];
        yield 'a week' => ['2026-08-13 12:00:00', '7 d'];
        yield 'a month' => ['2026-07-01 12:00:00', '1 mo'];
        yield 'a year' => ['2025-08-01 12:00:00', '1 y'];
        yield 'several years' => ['2022-03-03 12:00:00', '4 y'];
    }

    #[DataProvider('phrases')]
    public function testPhraseFormReadsAsASentence(?string $moment, string $expected): void
    {
        $value = null === $moment ? null : new \DateTimeImmutable($moment);

        self::assertSame($expected, $this->extension()->agoPhrase($value));
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function phrases(): iterable
    {
        // The regression: "today" must not become "today ago".
        yield 'same day' => ['2026-08-20 09:00:00', 'today'];
        yield 'yesterday' => ['2026-08-19 09:00:00', '1 d ago'];
        yield 'a month' => ['2026-07-01 12:00:00', '1 mo ago'];
        yield 'never crawled' => [null, 'never'];
    }

    /**
     * The em dash is a column placeholder, not something to put in a sentence.
     */
    public function testTheTerseFormStillUsesADashForNothing(): void
    {
        self::assertSame('-', $this->extension()->ago(null));
    }

    private function extension(): RelativeTimeExtension
    {
        return new RelativeTimeExtension(new MockClock(new \DateTimeImmutable(self::NOW)));
    }
}
