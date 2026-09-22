<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Catalog\Repository\CategoryRepository;
use App\Catalog\Search\ExtensionSearch;
use App\Catalog\Search\SearchCriteria;
use App\Stats\EcosystemStats;
use App\Ui\CatalogueStatus;
use App\Ui\Chart\Plot;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What the catalogue knows about the ecosystem, rather than about one extension.
 *
 * The directory could already answer "does this work with my Shopware version" one
 * package at a time. The same tables answer questions nobody in this ecosystem can
 * currently answer at all: how long extensions take to catch up after a Shopware
 * release, which categories have kept pace, and how precisely compatibility is
 * actually declared. None of that is visible from a listing page.
 *
 * Every chart here carries the expression that produced it, for the same reason every
 * board does. Section 4.6 forbids ordering a reader cannot check, and a chart is an
 * ordering with the numbers taken off the axis. A reader who doubts a bar has to be
 * able to find out what it counted without reading the source.
 *
 * Distinct from /boards on purpose. Boards rank who builds this ecosystem and what
 * people install, which is a question about parties. This is a question about time.
 */
final class StatsController extends AbstractController
{
    public function __construct(
        private readonly EcosystemStats $stats,
        private readonly ExtensionSearch $search,
        private readonly CategoryRepository $categories,
        private readonly CatalogueStatus $status,
    ) {
    }

    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function index(): Response
    {
        // The same facet counts the listing page shows, from the same code. A second
        // implementation of "how many are permissive" would agree with the first until
        // the day it did not, and nothing on either page would say which was right.
        $facets = $this->search->facets(new SearchCriteria());

        $response = $this->render('pages/stats.html.twig', [
            'headline' => $this->stats->headline(),
            'charts' => [
                $this->newExtensions(),
                $this->releaseVolume(),
                $this->seasonality(),
                $this->adoption(),
                $this->licenceMix($facets['licence'] ?? []),
                $this->maintenanceMix($facets['maintenance'] ?? []),
                $this->constraintQuality(),
                $this->dormancy(),
                $this->categorySize($facets['category'] ?? []),
            ],
            'heatmap' => $this->heatmap(),
            'catalogueStatus' => $this->status->toArray(),
        ]);

        // Nine aggregates, none of them per-visitor, over a catalogue that changes
        // once a night. The same hour Boards uses, for the same reason.
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function newExtensions(): array
    {
        return $this->columns(
            'new-per-year',
            'New extensions per year',
            'When was this ecosystem built?',
            'MIN(released_at) per extension, counting the first tagged release. Repositories not on Packagist are read from their newest 30 tags, so an older one can report a later first release than the truth.',
            'extensions',
            $this->stats->newExtensionsPerYear(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseVolume(): array
    {
        return $this->columns(
            'releases-per-year',
            'Releases per year',
            'How much work goes into it?',
            'COUNT(stable tagged releases) across every listed extension, by release year.',
            'releases',
            $this->stats->releasesPerYear(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function seasonality(): array
    {
        return $this->columns(
            'seasonality',
            'Releases by calendar month',
            'Which months are the productive ones?',
            'COUNT(stable tagged releases) grouped by calendar month, every year counted together.',
            'releases',
            $this->stats->releasesByCalendarMonth(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function constraintQuality(): array
    {
        return $this->bars(
            'constraint-quality',
            'How precisely compatibility is declared',
            'Is the compatibility claim worth anything?',
            'The constraint tier on each extension\'s newest stable release. Explicit names a version, a caret allows anything within a major, a wildcard claims every version that will ever exist, and nothing declared is no claim at all.',
            'extensions',
            $this->stats->constraintQuality(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dormancy(): array
    {
        return $this->bars(
            'dormancy',
            'Time since the last release',
            'How much of the catalogue is still moving?',
            'extension.last_release_at against today, in fixed buckets.',
            'extensions',
            $this->stats->timeSinceLastRelease(),
        );
    }

    /**
     * @param array<string, int> $facetCounts
     *
     * @return array<string, mixed>
     */
    private function categorySize(array $facetCounts): array
    {
        $labels = $this->categories->findAllKeyed();
        $rows = [];

        foreach ($facetCounts as $key => $count) {
            $rows[] = [
                'label' => isset($labels[$key]) ? $labels[$key]->getLabel() : $key,
                'value' => $count,
                'partial' => false,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return $this->bars(
            'categories',
            'Extensions per category',
            'Where is the ecosystem thick, and where is it thin?',
            'COUNT(DISTINCT extensions) per category. An extension can hold several categories, so these add up to more than the catalogue total, and anything the classifier could not place is in none of them.',
            'extensions',
            $rows,
        );
    }

    /**
     * The licence mix.
     *
     * Four segments, each carrying its own label. The site's four signal colours are
     * not separable enough on their own to encode four classes, measured rather than
     * guessed, so the label is what tells them apart and the colour only agrees with
     * the badge the reader already met on every extension card.
     *
     * @param array<string, int> $facetCounts
     *
     * @return array<string, mixed>
     */
    private function licenceMix(array $facetCounts): array
    {
        return $this->stack(
            'licence-mix',
            'What the licences permit',
            'How much of this may actually be redistributed?',
            'extension.license_status, detected from composer.json and confirmed against the repository LICENSE file.',
            [
                ['key' => 'permissive', 'label' => 'Open source', 'tone' => 'ok'],
                ['key' => 'copyleft', 'label' => 'Copyleft', 'tone' => 'info'],
                ['key' => 'rejected', 'label' => 'Not open source', 'tone' => 'risk'],
                ['key' => 'unknown', 'label' => 'No licence found', 'tone' => 'caution'],
            ],
            $facetCounts,
        );
    }

    /**
     * @param array<string, int> $facetCounts
     *
     * @return array<string, mixed>
     */
    private function maintenanceMix(array $facetCounts): array
    {
        return $this->stack(
            'maintenance-mix',
            'Whether anyone is still maintaining it',
            'How much of the catalogue is alive?',
            'extension.maintenance_status, measured against the date the current Shopware version shipped.',
            [
                ['key' => 'current', 'label' => 'Current', 'tone' => 'ok'],
                ['key' => 'lagging', 'label' => 'Lagging', 'tone' => 'caution'],
                ['key' => 'dormant', 'label' => 'Dormant', 'tone' => 'risk'],
                ['key' => 'abandoned', 'label' => 'Abandoned', 'tone' => 'risk'],
                ['key' => 'unknown', 'label' => 'Unknown', 'tone' => 'muted'],
            ],
            $facetCounts,
        );
    }

    /**
     * The adoption curves.
     *
     * Aligned to months since each Shopware version shipped rather than to calendar
     * time. That is the whole point of the chart: on a calendar axis the lines merely
     * start at different places, and the reader learns that 6.7 is newer, which they
     * knew. Aligned to release, the lines become comparable and the chart answers
     * whether the ecosystem is getting faster.
     *
     * @return array<string, mixed>
     */
    private function adoption(): array
    {
        $series = $this->stats->shopwareAdoption();
        $ceiling = Plot::niceMax(Plot::peak($series));
        $span = Plot::span($series);

        $lines = [];
        foreach ($series as $index => $line) {
            $lines[] = [
                'version' => $line['version'],
                'releasedAt' => $line['releasedAt'],
                // Colour follows the Shopware version, not the line's current rank,
                // so the newest release keeps its hue when another is added.
                'step' => min(5, $index + 1),
                'path' => Plot::linePath($line['points'], $span, $ceiling),
                'points' => Plot::project($line['points'], $span, $ceiling),
                'total' => $line['points'] ? end($line['points'])['value'] : 0,
            ];
        }

        return [
            'id' => 'adoption',
            'type' => 'lines',
            'title' => 'How fast each Shopware version was adopted',
            'question' => 'How long does the ecosystem take to catch up?',
            'rule' => 'For each Shopware minor, the running count of listed extensions whose stable releases satisfy it, against months since that Shopware version shipped. Month zero is not an adoption event: it holds everything whose existing constraint already covered the new version without anyone testing it.',
            'unit' => 'extensions',
            'lines' => $lines,
            'ceiling' => $ceiling,
            'span' => $span,
            'ticks' => Plot::ticks($ceiling),
            'width' => Plot::WIDTH,
            'height' => Plot::HEIGHT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function heatmap(): array
    {
        $data = $this->stats->categoryByVersion();
        $rows = [];

        foreach ($data['rows'] as $row) {
            $cells = [];
            foreach ($row['cells'] as $cell) {
                $cells[] = [
                    'version' => $cell['version'],
                    'value' => $cell['value'],
                    'percent' => (int) round($cell['share'] * 100),
                    'step' => Plot::rampStep($cell['share']),
                ];
            }

            $rows[] = ['label' => $row['label'], 'total' => $row['total'], 'cells' => $cells];
        }

        return [
            'id' => 'category-matrix',
            'title' => 'Which categories kept up',
            'question' => 'Where is the ecosystem still on an old Shopware?',
            'rule' => 'The share of each category declaring support for each Shopware version. A share rather than a count, because the categories differ in size by a factor of sixty and counts would draw nothing but category size.',
            'versions' => $data['versions'],
            'rows' => $rows,
        ];
    }

    /**
     * @param list<array{label: string, value: int, partial: bool}> $rows
     *
     * @return array<string, mixed>
     */
    private function columns(string $id, string $title, string $question, string $rule, string $unit, array $rows): array
    {
        $peak = $this->largest($rows);
        $ceiling = Plot::niceMax($peak);

        return [
            'id' => $id,
            'type' => 'columns',
            // The tallest bar, not the axis ceiling. The ceiling is rounded up to a
            // readable number, so no bar is ever equal to it and the label that marks
            // the peak would never appear.
            'peak' => $peak,
            'title' => $title,
            'question' => $question,
            'rule' => $rule,
            'unit' => $unit,
            'ceiling' => $ceiling,
            'rows' => array_map(
                static fn (array $row): array => $row + ['share' => Plot::share($row['value'], $ceiling)],
                $rows,
            ),
        ];
    }

    /**
     * @param list<array{label: string, value: int, partial: bool}> $rows
     *
     * @return array<string, mixed>
     */
    private function bars(string $id, string $title, string $question, string $rule, string $unit, array $rows): array
    {
        $ceiling = $this->largest($rows);

        return [
            'id' => $id,
            'type' => 'bars',
            'title' => $title,
            'question' => $question,
            'rule' => $rule,
            'unit' => $unit,
            'ceiling' => $ceiling,
            'rows' => array_map(
                static fn (array $row): array => $row + ['share' => Plot::share($row['value'], $ceiling)],
                $rows,
            ),
        ];
    }

    /**
     * @param list<array{key: string, label: string, tone: string}> $segments
     * @param array<string, int>                                    $counts
     *
     * @return array<string, mixed>
     */
    private function stack(string $id, string $title, string $question, string $rule, array $segments, array $counts): array
    {
        $total = array_sum($counts);
        $rows = [];

        foreach ($segments as $segment) {
            $value = $counts[$segment['key']] ?? 0;

            // A segment nobody is in is dropped rather than drawn as a sliver. An
            // empty class in the legend invites reading it as "almost none" when the
            // honest reading is "none".
            if (0 === $value) {
                continue;
            }

            $rows[] = [
                'label' => $segment['label'],
                'tone' => $segment['tone'],
                'value' => $value,
                'partial' => false,
                'share' => $total > 0 ? round($value / $total * 100, 1) : 0.0,
            ];
        }

        return [
            'id' => $id,
            'type' => 'stack',
            'title' => $title,
            'question' => $question,
            'rule' => $rule,
            'unit' => 'extensions',
            'total' => $total,
            'rows' => $rows,
        ];
    }

    /**
     * @param list<array{label: string, value: int, partial: bool}> $rows
     */
    private function largest(array $rows): int
    {
        $values = array_map(static fn (array $row): int => $row['value'], $rows);

        return $values ? max($values) : 0;
    }
}
