<?php

declare(strict_types=1);

namespace App\Ui\Twig;

use Symfony\Component\Clock\ClockInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Renders a date as an age: "6 d", "3 mo", "2 y".
 *
 * The catalogue's most-scanned column is "when was this last touched", and an
 * absolute date makes the reader do arithmetic on every row. Whether a shop can rely
 * on an extension is a question about elapsed time, not about the calendar.
 *
 * Deliberately coarse, and deliberately not "about 3 months ago". The unit alone is
 * the signal — days good, months worth a look, years a warning — and a column of
 * short tokens can be scanned at a glance where a column of sentences cannot. The
 * exact date stays in the title attribute and the datetime attribute for anyone who
 * needs it.
 */
final class RelativeTimeExtension extends AbstractExtension
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('ago', $this->ago(...)),
            new TwigFilter('ago_phrase', $this->agoPhrase(...)),
        ];
    }

    /**
     * The same span as a sentence rather than a column value.
     *
     * `ago` is deliberately terse — "2 d" scans in a table where "2 days ago" would
     * not — but appending " ago" to it in prose produces "crawled today ago". The
     * masthead already carried a conditional to work around that; the detail page
     * carried the naive version and shipped the broken sentence. One filter, so the
     * two cannot disagree again.
     */
    public function agoPhrase(?\DateTimeInterface $moment): string
    {
        $relative = $this->ago($moment);

        return match ($relative) {
            '—' => 'never',
            'today' => 'today',
            default => $relative.' ago',
        };
    }

    public function ago(?\DateTimeInterface $moment): string
    {
        if (null === $moment) {
            return '—';
        }

        $days = (int) $this->clock->now()->diff($moment)->days;

        return match (true) {
            $days <= 0 => 'today',
            1 === $days => '1 d',
            $days < 31 => $days.' d',
            // 30-day months and 365-day years. This is a scanning aid, not an
            // anniversary calculator, and rounding either way changes nothing a
            // reader would act on.
            $days < 365 => intdiv($days, 30).' mo',
            $days < 730 => '1 y',
            default => intdiv($days, 365).' y',
        };
    }
}
