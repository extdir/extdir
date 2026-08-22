<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Catalog\Entity\Extension;
use App\Catalog\Repository\ExtensionRepository;
use App\Catalog\Repository\VendorRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Six leaderboards: who builds this ecosystem, and what people actually install.
 *
 * The directory could already answer "does this work with my Shopware and is it
 * maintained". It could not answer "who is carrying this" or "is anyone using it",
 * and both are asked before anyone adopts an extension.
 *
 * Everything here is bound by the conflict-of-interest rule, which forbids editorial
 * placement outright — so a leaderboard has to be built as a published rule rather
 * than as a choice. Three things follow, and none of them is optional:
 *
 * Every board prints the expression that produced it. There is no curated list, no
 * override table and no configuration file naming a vendor anywhere in this feature;
 * the only way onto a board is to satisfy a rule a reader can check.
 *
 * The vendors are ranked three ways rather than one. "Most contributing" has at least
 * three defensible meanings and they produce genuinely different winners — the vendor
 * leading on releases maintains a third as many extensions as the vendor leading on
 * breadth. Picking one and calling it the answer would be the editorial judgement the
 * rule forbids, so all three are shown side by side and none is called the winner.
 *
 * And the maintainer's own vendor gets no exception. Nothing here special-cases it in
 * either direction: it can appear on any board it qualifies for, wearing the same
 * disclosure chip it wears on the vendor listing.
 *
 * Popularity is displayed and never scored. The download counts below feed no ranking
 * — RankingScore does not take them as an argument and cannot be given them without
 * changing its signature — because the ranking guidance is blunt that stars and installs
 * mislead in this ecosystem. Showing them on a page whose whole subject is popularity
 * is honest; letting them decide what a merchant sees first is not.
 */
final class BoardsController extends AbstractController
{
    /**
     * Rows per board.
     *
     * Ten rather than three. A top three is a podium and invites reading the winner as
     * an endorsement; ten reads as a distribution, and shows how quickly the numbers
     * fall away — which for this catalogue is the more honest picture.
     */
    private const int ROWS = 10;

    public function __construct(
        private readonly VendorRepository $vendors,
        private readonly ExtensionRepository $extensions,
    ) {
    }

    #[Route('/boards', name: 'boards', methods: ['GET'])]
    public function index(): Response
    {
        $response = $this->render('pages/boards.html.twig', [
            'vendorBoards' => $this->vendorBoards(),
            'extensionBoards' => $this->extensionBoards(),
            'coverage' => $this->extensions->packagistCoverage(),
        ]);

        // The underlying numbers change once a night at most, and the download counts
        // once a week. Six aggregate queries per visit is not a lot, but it is not
        // nothing either, and none of it is per-visitor.
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    /**
     * @return list<array{title: string, question: string, rule: string, unit: string, decimals: int, rows: list<array<string, mixed>>}>
     */
    private function vendorBoards(): array
    {
        return [
            $this->vendorBoard(
                'Breadth',
                'Who maintains the most extensions?',
                'COUNT(extensions), counting only what the catalogue lists',
                'extensions',
                $this->vendors->topByExtensionCount(self::ROWS),
                0,
            ),
            $this->vendorBoard(
                'Depth',
                'Who maintains the most extensions well?',
                'SUM(ranking score) ÷ 100 — the same score shown on every extension page',
                'score',
                $this->vendors->topByAggregateScore(self::ROWS),
                1,
            ),
            $this->vendorBoard(
                'Activity',
                'Who ships the most?',
                'COUNT(tagged releases) across the vendor’s listed extensions',
                'releases',
                $this->vendors->topByReleaseCount(self::ROWS),
                0,
            ),
        ];
    }

    /**
     * @return list<array{title: string, question: string, rule: string, unit: string, decimals: int, rows: list<array<string, mixed>>}>
     */
    private function extensionBoards(): array
    {
        return [
            $this->extensionBoard(
                'Installed, all time',
                'What has been installed most?',
                'Packagist lifetime downloads',
                'installs',
                $this->extensions->topByDownloads(self::ROWS),
                static fn (Extension $e): float => (float) $e->getDownloadsTotal(),
            ),
            $this->extensionBoard(
                'Installed, last 30 days',
                'What is being installed now?',
                'Packagist downloads over the trailing thirty days',
                'installs',
                $this->extensions->topByMonthlyDownloads(self::ROWS),
                static fn (Extension $e): float => (float) $e->getDownloadsMonthly(),
            ),
            $this->extensionBoard(
                'Starred',
                'What has been starred most?',
                'Stars on the extension’s own forge',
                'stars',
                $this->extensions->topByStars(self::ROWS),
                static fn (Extension $e): float => (float) $e->getStars(),
            ),
        ];
    }

    /**
     * @param list<array{vendor: \App\Catalog\Entity\Vendor, value: float}> $rows
     *
     * @return array{title: string, question: string, rule: string, unit: string, decimals: int, rows: list<array<string, mixed>>}
     */
    private function vendorBoard(
        string $title,
        string $question,
        string $rule,
        string $unit,
        array $rows,
        int $decimals,
    ): array {
        $values = array_map(static fn (array $row): float => $row['value'], $rows);

        return [
            'title' => $title,
            'question' => $question,
            'rule' => $rule,
            'unit' => $unit,
            'decimals' => $decimals,
            'rows' => array_map(
                fn (array $row): array => [
                    'label' => $row['vendor']->getName(),
                    'route' => 'vendor',
                    'params' => ['slug' => $row['vendor']->getSlug()],
                    'value' => $row['value'],
                    'share' => self::share($row['value'], $values),
                    // The same chip the vendor listing renders, from the same flag.
                    // No board may show the maintainer's own vendor without it.
                    'disclosure' => $row['vendor']->isMaintainerOperated(),
                ],
                $rows,
            ),
        ];
    }

    /**
     * @param list<Extension>          $extensions
     * @param callable(Extension): float $value
     *
     * @return array{title: string, question: string, rule: string, unit: string, decimals: int, rows: list<array<string, mixed>>}
     */
    private function extensionBoard(
        string $title,
        string $question,
        string $rule,
        string $unit,
        array $extensions,
        callable $value,
    ): array {
        $values = array_map($value, $extensions);

        return [
            'title' => $title,
            'question' => $question,
            'rule' => $rule,
            'unit' => $unit,
            // Installs and stars are counts. There is no fractional star.
            'decimals' => 0,
            'rows' => array_map(
                fn (Extension $e): array => [
                    'label' => $e->getLabel(),
                    'sublabel' => $e->getPackageName(),
                    'route' => 'extension_detail',
                    'params' => ['slug' => $e->getSlug()],
                    'value' => $value($e),
                    'share' => self::share($value($e), $values),
                    'disclosure' => $e->getVendor()->isMaintainerOperated(),
                ],
                $extensions,
            ),
        ];
    }

    /**
     * This row's value as a fraction of the board's largest, for the bar.
     *
     * Scaled against the top of its own board rather than a global maximum, because the
     * boards measure incomparable things — a release count and a ranking score share no
     * unit, and drawing them on one scale would say something untrue about both.
     *
     * @param list<float> $values
     */
    private static function share(float $value, array $values): float
    {
        $max = $values ? max($values) : 0.0;

        return $max > 0 ? round($value / $max * 100, 1) : 0.0;
    }
}
