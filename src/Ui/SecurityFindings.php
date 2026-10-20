<?php

declare(strict_types=1);

namespace App\Ui;

use App\Catalog\Entity\Extension;
use App\Signals\Entity\PlumbScan;
use App\Signals\Enum\PlumbStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Plumb's security findings, shaped for the extension page.
 *
 * A read-side view model rather than logic in Twig, for the same reason Plot exists:
 * the ordering and the three absent states are the part that can quietly be wrong,
 * and they are worth testing without a browser or a database.
 *
 * The ordering is the one piece of judgement here. Findings come back in Plumb's own
 * order and are re-sorted so that anything actionable is first, because this panel is
 * read by two people with opposite needs. A maintainer wants the list of things to
 * fix. A merchant wants to know whether there is anything to worry about. Putting the
 * failures at the top answers both in the first line, and a panel that opened with
 * four passes would answer neither.
 *
 * Three absences, deliberately distinguished, because collapsing them is the easiest
 * way for this panel to say something untrue:
 *
 * - not on Packagist, so Plumb reads nothing and never will. Roughly three in ten of
 *   the catalogue, permanently.
 * - on Packagist, but Plumb has not reached it yet. About half of the rest today,
 *   and shrinking as they work through the registry.
 * - swept and scanned, with findings.
 *
 * Only the third is a verdict. The first two must never render as a low score, which
 * is exactly how a blank panel would be read.
 */
final readonly class SecurityFindings
{
    /**
     * Actionable first, then by weight, so the heaviest failure leads.
     *
     * The tail runs from most to least informative rather than alphabetically: a pass
     * is a result, "not assessed" is the absence of one, and "not applicable" means
     * the question was never this extension's to answer.
     */
    private const array ORDER = [
        'fail' => 0,
        'warn' => 1,
        'pass' => 2,
        'unassessable' => 3,
        'not_applicable' => 4,
    ];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return array{state: string, score: ?float, scannedAt: ?\DateTimeImmutable, version: ?string, url: string, checks: list<array{title: string, status: PlumbStatus, weight: int, guide: ?string, evidence: ?string}>, actionable: int, unassessed: int}
     */
    public function forExtension(Extension $extension): array
    {
        $url = 'https://plumbphp.dev/'.$extension->getPackageName();

        if (!$extension->isOnPackagist()) {
            return $this->absent('off_packagist', $url);
        }

        $scan = $this->em->getRepository(PlumbScan::class)->findOneBy(['extension' => $extension]);

        if (!$scan instanceof PlumbScan || !$scan->isScanned()) {
            return $this->absent('unscanned', $url);
        }

        $checks = [];
        foreach ($scan->getChecks() as $check) {
            $checks[] = [
                'title' => $check['title'],
                'status' => PlumbStatus::from_($check['status']),
                'weight' => $check['weight'],
                'guide' => $check['guide'],
                'evidence' => $check['evidence'],
            ];
        }

        usort($checks, static function (array $a, array $b): int {
            $rank = self::ORDER[$a['status']->value] <=> self::ORDER[$b['status']->value];

            return 0 !== $rank ? $rank : $b['weight'] <=> $a['weight'];
        });

        return [
            'state' => 'scanned',
            'score' => $scan->getSecurityScore(),
            'scannedAt' => $scan->getScannedAt(),
            'version' => $scan->getReferenceVersion(),
            'url' => $url,
            'checks' => $checks,
            'actionable' => \count(array_filter(
                $checks,
                static fn (array $c): bool => $c['status']->isActionable(),
            )),
            // Counted separately because the panel's one-line summary has to be able
            // to say so. "All eight passed or did not apply" is not true of a scan
            // where three of them could not be run at all, and that sentence is the
            // only part of the panel most readers will read.
            'unassessed' => \count(array_filter(
                $checks,
                static fn (array $c): bool => PlumbStatus::Unassessable === $c['status'],
            )),
        ];
    }

    /**
     * @return array{state: string, score: ?float, scannedAt: ?\DateTimeImmutable, version: ?string, url: string, checks: list<array{title: string, status: PlumbStatus, weight: int, guide: ?string, evidence: ?string}>, actionable: int, unassessed: int}
     */
    private function absent(string $state, string $url): array
    {
        return [
            'state' => $state,
            'score' => null,
            'scannedAt' => null,
            'version' => null,
            'url' => $url,
            'checks' => [],
            'actionable' => 0,
            'unassessed' => 0,
        ];
    }
}
