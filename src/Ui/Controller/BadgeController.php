<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Catalog\Repository\ExtensionRepository;
use App\Compatibility\Repository\CompatibilityClaimRepository;
use App\Compatibility\Repository\ShopwareVersionRepository;
use App\Ui\Badge\CompatibilityBadge;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * A compatibility badge a maintainer can put in their README.
 *
 * The cheapest growth loop available: every badge is a backlink, and a maintainer
 * showing "shopware 6.5, 6.7" is advertising the directory to precisely the people
 * it is for. It also gives maintainers something back, which matters for a project
 * that indexes their work without asking.
 *
 * The badge states the same thing the site does and never more. An extension with no
 * parsable constraint says so rather than guessing, and one that has been delisted
 * says that too, a badge that 404s or 500s in someone's README would be a small
 * public embarrassment that they would rightly blame on us.
 */
final class BadgeController extends AbstractController
{
    public function __construct(
        private readonly ExtensionRepository $extensions,
        private readonly CompatibilityClaimRepository $claims,
        private readonly ShopwareVersionRepository $versions,
        private readonly CompatibilityBadge $badge,
    ) {
    }

    #[Route('/badge/{slug}.svg', name: 'badge', requirements: ['slug' => Requirement::CATCH_ALL], methods: ['GET'])]
    public function compatibility(string $slug): Response
    {
        $extension = $this->extensions->findOneBySlug($slug);

        [$message, $colour] = match (true) {
            null === $extension => ['not indexed', '#9f9f9f'],
            !$extension->getIndexStatus()->isPubliclyVisible() => ['removed', '#9f9f9f'],
            default => $this->describe($extension),
        };

        $response = new Response(
            $this->badge->render('shopware', $message, $colour),
            // Always 200, even for an unknown slug. A 404 in an <img> renders as a
            // broken image in a README, which looks like the maintainer's mistake
            // rather than a stale link.
            Response::HTTP_OK,
            ['Content-Type' => 'image/svg+xml; charset=utf-8'],
        );

        // An hour. Compatibility changes at most once per crawl, and a badge that is
        // an hour stale has never misled anyone, while a badge fetched fresh on
        // every README view would put this server in the path of someone else's
        // traffic.
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->setEtag(md5($message));

        return $response;
    }

    /**
     * @return array{string, string}
     */
    private function describe(\App\Catalog\Entity\Extension $extension): array
    {
        $matrix = $this->claims->findMatrixForExtension($extension);
        $declared = [];

        foreach ($this->versions->findShownInMatrix() as $version) {
            if (isset($matrix[$version->getMajorMinor()])) {
                $declared[] = $version->getMajorMinor();
            }
        }

        if ([] === $declared) {
            // Honest rather than blank. "No parsable shopware/core constraint" is a
            // real state for 40 of the indexed extensions.
            return ['not declared', '#9f9f9f'];
        }

        $first = $declared[0];
        $last = $declared[\count($declared) - 1];

        // A range reads better than a list once there are more than two, and the
        // detail page carries the exact set including which are open-ended.
        $message = \count($declared) > 2
            ? \sprintf('%s, %s', $first, $last)
            : implode(' | ', $declared);

        return [$message, '#2f7d33'];
    }
}
