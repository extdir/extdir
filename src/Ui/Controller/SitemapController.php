<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Catalog\Repository\CategoryRepository;
use App\Catalog\Repository\ExtensionRepository;
use App\Compatibility\Repository\ShopwareVersionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * robots.txt and the sitemap.
 *
 * For a directory this is not housekeeping, it is the distribution plan. The
 * content is scraped, so the cold-start problem is not filling the index — it is
 * being found at all. Nobody types "extdir"; they type "shopware 6 abandoned cart
 * extension", and every extension page and every facet landing page is a chance to
 * be the answer.
 */
final class SitemapController extends AbstractController
{
    public function __construct(
        private readonly ExtensionRepository $extensions,
        private readonly ShopwareVersionRepository $shopwareVersions,
        private readonly CategoryRepository $categories,
    ) {
    }

    #[Route('/robots.txt', name: 'robots', methods: ['GET'])]
    public function robots(): Response
    {
        $sitemap = $this->generateUrl('sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL);

        // Sort orderings return the same set in a different order, so letting a
        // crawler walk them multiplies identical content across the crawl budget.
        // Facet combinations are left crawlable: "6.7 + payment" is a page a
        // merchant would genuinely search for.
        $body = <<<ROBOTS
            User-agent: *
            Allow: /

            # Same results, different order — nothing new to index.
            Disallow: /*?*sort=
            Disallow: /*&sort=

            # Machine-readable endpoints; useful to Composer, not to a search index.
            Disallow: /repo/

            Sitemap: {$sitemap}
            ROBOTS;

        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function sitemap(): Response
    {
        $urls = [];

        foreach (['home', 'about', 'ranking', 'imprint', 'privacy', 'terms', 'takedown'] as $route) {
            $urls[] = ['loc' => $this->generateUrl($route, [], UrlGeneratorInterface::ABSOLUTE_URL)];
        }

        // Facet landing pages. Each one answers a question somebody actually types
        // — "shopware 6.7 extensions", "shopware payment plugins" — and each is a
        // real page rather than a redirect, so they are worth submitting.
        foreach ($this->shopwareVersions->findShownInMatrix() as $version) {
            $urls[] = ['loc' => $this->generateUrl(
                'home',
                ['shopware' => $version->getMajorMinor()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            )];
        }

        foreach ($this->categories->findAllKeyed() as $key => $category) {
            $urls[] = ['loc' => $this->generateUrl(
                'home',
                ['category' => $key],
                UrlGeneratorInterface::ABSOLUTE_URL,
            )];
        }

        foreach ($this->extensions->findPubliclyVisible() as $extension) {
            $urls[] = [
                'loc' => $this->generateUrl(
                    'extension_detail',
                    ['slug' => $extension->getSlug()],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
                // Reflects when the extension itself last changed, not when we last
                // looked at it — a crawl that found nothing new is not a reason to
                // ask a search engine to re-read the page.
                'lastmod' => ($extension->getLastCommitAt() ?? $extension->getLastReleaseAt())?->format('Y-m-d'),
            ];

            // The alternatives page answers "what else does this", which is a
            // question people arrive with from a search engine. Listing it costs one
            // line here and is the difference between the page existing and the page
            // being found.
            $urls[] = [
                'loc' => $this->generateUrl(
                    'alternatives',
                    ['slug' => $extension->getSlug()],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
                'lastmod' => ($extension->getLastCommitAt() ?? $extension->getLastReleaseAt())?->format('Y-m-d'),
            ];
        }

        $response = $this->render('sitemap/sitemap.xml.twig', ['urls' => $urls]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
