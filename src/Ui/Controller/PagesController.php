<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Catalog\Repository\ExtensionRepository;
use App\Compatibility\Enum\ConstraintTier;
use App\Compatibility\Repository\ShopwareVersionRepository;
use App\Signals\Enum\MaintenanceStatus;
use App\Signals\RankingScore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PagesController extends AbstractController
{
    public function __construct(
        private readonly ExtensionRepository $extensions,
        private readonly ShopwareVersionRepository $shopwareVersions,
    ) {
    }

    /**
     * The public ranking explanation.
     *
     * Rendered directly from the constants that do the ranking, so the published
     * description cannot drift from the behaviour. The conflict-of-interest rule requires the
     * algorithm to be public; a hand-written page describing an algorithm that has
     * since changed would satisfy the letter of that and betray the point.
     */
    #[Route('/ranking', name: 'ranking', methods: ['GET'])]
    public function ranking(): Response
    {
        return $this->render('pages/ranking.html.twig', [
            'weights' => RankingScore::published(),
            'tiers' => ConstraintTier::cases(),
            'maintenanceStates' => MaintenanceStatus::cases(),
            'currentVersion' => $this->shopwareVersions->findCurrent(),
            'recencyHalfLife' => RankingScore::RECENCY_HALF_LIFE_DAYS,
        ]);
    }

    #[Route('/about', name: 'about', methods: ['GET'])]
    public function about(): Response
    {
        return $this->render('pages/about.html.twig', [
            'total' => $this->extensions->count([]),
            'shopwareVersions' => $this->shopwareVersions->findShownInMatrix(),
        ]);
    }
}
