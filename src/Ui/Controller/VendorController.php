<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Catalog\Repository\ExtensionRepository;
use App\Catalog\Repository\VendorRepository;
use App\Compatibility\Repository\CompatibilityClaimRepository;
use App\Compatibility\Repository\ShopwareVersionRepository;
use App\Ui\CatalogueStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Everything one vendor publishes, on one page.
 *
 * A useful question the catalogue could not answer: an agency evaluating a plugin
 * usually wants to know what else its author maintains and whether they keep any of
 * it current. One extension being three years stale means something different when
 * the same vendor has twenty others updated last week.
 *
 * 192 vendors, so 192 pages, and they are indexable. The largest publishes 24
 * extensions; most publish one.
 */
final class VendorController extends AbstractController
{
    public function __construct(
        private readonly VendorRepository $vendors,
        private readonly ExtensionRepository $extensions,
        private readonly ShopwareVersionRepository $shopwareVersions,
        private readonly CompatibilityClaimRepository $claims,
        private readonly CatalogueStatus $status,
    ) {
    }

    #[Route('/vendors', name: 'vendors', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('catalog/vendors.html.twig', [
            'vendors' => $this->vendors->findWithVisibleExtensions(),
            'catalogueStatus' => $this->status->toArray(),
        ]);
    }

    #[Route('/vendor/{slug}', name: 'vendor', requirements: ['slug' => Requirement::CATCH_ALL], methods: ['GET'])]
    public function detail(string $slug): Response
    {
        $vendor = $this->vendors->findOneBySlug($slug);

        if (null === $vendor) {
            throw $this->createNotFoundException();
        }

        $extensions = $this->extensions->findVisibleForVendor($vendor);

        if ([] === $extensions) {
            // Every extension delisted. The vendor row survives, but a page listing
            // nothing is a dead end for a reader and for a crawler.
            throw $this->createNotFoundException();
        }

        return $this->render('catalog/vendor.html.twig', [
            'vendor' => $vendor,
            'extensions' => $extensions,
            'shopwareVersions' => $this->shopwareVersions->findShownInMatrix(),
            'matrices' => $this->claims->findMatrixForExtensions($extensions),
            'catalogueStatus' => $this->status->toArray(),
        ]);
    }
}
