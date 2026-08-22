<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Catalog\Repository\ExtensionRepository;
use App\Compatibility\Repository\CompatibilityClaimRepository;
use App\Compatibility\Repository\ShopwareVersionRepository;
use App\Ui\CatalogueStatus;
use App\Ui\Image\SocialCard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The images that appear when a link is shared.
 *
 * Two routes rather than one: the catalogue card says what the directory is and how
 * much it holds, and the extension card says what that specific extension declares.
 * Both are drawn from live data, so neither can quietly go stale the way a
 * hand-made image would.
 */
final class SocialCardController extends AbstractController
{
    public function __construct(
        private readonly ExtensionRepository $extensions,
        private readonly CompatibilityClaimRepository $claims,
        private readonly ShopwareVersionRepository $versions,
        private readonly CatalogueStatus $status,
        private readonly SocialCard $cards,
    ) {
    }

    #[Route('/og/default.png', name: 'og_default', methods: ['GET'])]
    public function default(): Response
    {
        $path = $this->cards->render(
            'Directory',
            'Open-source Shopware 6 extensions',
            'Compatibility, licence and maintenance, from the source',
            [
                'indexed' => (string) $this->status->total(),
                'updated' => $this->status->crawledAt()?->format('j M Y') ?? 'unknown',
            ],
        );

        return $this->serve($path);
    }

    #[Route('/og/{slug}.png', name: 'og_extension', requirements: ['slug' => Requirement::CATCH_ALL], methods: ['GET'])]
    public function extension(string $slug): Response
    {
        $extension = $this->extensions->findOneBySlug($slug);

        if (null === $extension || !$extension->getIndexStatus()->isPubliclyVisible()) {
            // A delisted extension has no page, so it gets no card either, the
            // removal should not leave a shareable artefact behind.
            return $this->redirectToRoute('og_default');
        }

        $matrix = $this->claims->findMatrixForExtension($extension);
        $declared = [];

        foreach ($this->versions->findShownInMatrix() as $version) {
            if (isset($matrix[$version->getMajorMinor()])) {
                $declared[] = $version->getMajorMinor();
            }
        }

        $path = $this->cards->render(
            'Shopware 6 extension',
            $extension->getLabel(),
            $extension->getPackageName(),
            [
                // The same range wording the badge uses. Listing five versions is
                // both wider than the column and less readable than its endpoints.
                'declares' => match (true) {
                    [] === $declared => 'not declared',
                    \count($declared) > 2 => $declared[0].', '.$declared[\count($declared) - 1],
                    default => implode('  ', $declared),
                },
                'licence' => $extension->getLicenseSpdx() ?? $extension->getLicenseStatus()->badgeLabel(),
                'last commit' => $extension->getLastCommitAt()?->format('M Y') ?? 'unknown',
            ],
        );

        return $this->serve($path);
    }

    private function serve(?string $path): Response
    {
        if (null === $path) {
            // No Imagick, or no usable font. A missing social preview is a cosmetic
            // loss; a 500 in a crawler's face is not.
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'image/png');
        $response->setPublic();
        $response->setMaxAge(86400);

        return $response;
    }
}
