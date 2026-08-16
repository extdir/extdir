<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Catalog\Entity\Extension;
use App\Catalog\Repository\CategoryRepository;
use App\Catalog\Repository\ExtensionRepository;
use App\Catalog\Search\ExtensionSearch;
use App\Catalog\Search\SearchCriteria;
use App\Compatibility\Repository\CompatibilityClaimRepository;
use App\Compatibility\Repository\ShopwareVersionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class CatalogController extends AbstractController
{
    public function __construct(
        private readonly ExtensionSearch $search,
        private readonly ExtensionRepository $extensions,
        private readonly ShopwareVersionRepository $shopwareVersions,
        private readonly CategoryRepository $categories,
        private readonly CompatibilityClaimRepository $claims,
    ) {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $criteria = SearchCriteria::fromRequest($request);
        $result = $this->search->search($criteria);

        return $this->render('catalog/index.html.twig', [
            'result' => $result,
            'criteria' => $criteria,
            'shopwareVersions' => $this->shopwareVersions->findShownInMatrix(),
            'categories' => $this->categories->findAllKeyed(),
            'matrices' => $this->claims->findMatrixForExtensions($result->extensions),
            'sortLabels' => SearchCriteria::sortLabels(),
        ]);
    }

    #[Route('/extension/{slug}', name: 'extension_detail', requirements: ['slug' => Requirement::CATCH_ALL], methods: ['GET'])]
    public function detail(string $slug): Response
    {
        $extension = $this->extensions->findOneBySlug($slug);

        if (null === $extension || !$extension->getIndexStatus()->isPubliclyVisible()) {
            // A delisted extension returns 404 rather than a tombstone page. If a
            // maintainer asked to be removed, leaving a page that says so would
            // defeat the point of the request.
            throw $this->createNotFoundException();
        }

        return $this->render('catalog/detail.html.twig', [
            'extension' => $extension,
            'shopwareVersions' => $this->shopwareVersions->findShownInMatrix(),
            'matrix' => $this->claims->findMatrixForExtension($extension),
            'releases' => $this->recentStableReleases($extension),
        ]);
    }

    /**
     * The newest stable releases, for the version table on the detail page.
     *
     * @return list<\App\Catalog\Entity\ExtensionRelease>
     */
    private function recentStableReleases(Extension $extension, int $limit = 10): array
    {
        $releases = array_filter(
            $extension->getReleases()->toArray(),
            static fn ($release): bool => $release->isStable(),
        );

        usort(
            $releases,
            static fn ($a, $b): int => version_compare($b->getVersion(), $a->getVersion()),
        );

        return \array_slice($releases, 0, $limit);
    }
}
