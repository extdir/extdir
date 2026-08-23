<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Catalog\Repository\ExtensionRepository;
use App\Catalog\Search\ExtensionSearch;
use App\Catalog\Search\SearchCriteria;
use App\Compatibility\Repository\CompatibilityClaimRepository;
use App\Ui\Api\ExtensionSerialiser;
use App\Ui\Api\Problem;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The catalogue as JSON.
 *
 * The directory answers one question: does this extension work with the Shopware I run,
 * is anyone maintaining it, and may I legally use it. Until now the only way to get that
 * answer was to parse the HTML, or to read /repo/packages.json, which is Composer
 * metadata rather than an answer.
 *
 * Built on the same ExtensionSearch the pages use, deliberately. A second search would
 * be a second definition of what "licence=permissive" means, and the two would agree
 * until the day they did not.
 *
 * Read-only, unauthenticated and cacheable. There is nothing here that is not already
 * on a public page; the difference is that this shape does not have to be scraped.
 */
final class ApiController extends AbstractController
{
    public function __construct(
        private readonly ExtensionSearch $search,
        private readonly ExtensionRepository $extensions,
        private readonly CompatibilityClaimRepository $claims,
        private readonly ExtensionSerialiser $serialiser,
    ) {
    }

    /**
     * What this API answers.
     *
     * Exists because the first thing anyone does with an undocumented API is request
     * its root, and until now that returned the HTML 404 page. The two caveats are
     * repeated here rather than left to llms.txt: something that starts at /api may
     * never read anything else, and both are the kind of mistake that ends with
     * somebody installing a package they may not redistribute.
     */
    #[Route('/api', name: 'api_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->cacheable([
            'name' => 'extdir',
            'description' => 'A community-run directory of open-source Shopware 6 extensions.',
            'documentation' => $this->generateUrl('llms_txt', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'endpoints' => [
                'extensions' => [
                    'url' => $this->generateUrl('api_extensions', [], UrlGeneratorInterface::ABSOLUTE_URL),
                    'filters' => ['q', 'shopware', 'category', 'licence', 'maintenance', 'page'],
                    'description' => 'Filtered listing. Same filters and same counts as the website.',
                ],
                'extension' => [
                    // Built by hand rather than generated: the router percent-encodes
                    // the braces, and %7Bslug%7D reads like a real URL somebody could
                    // request rather than a placeholder to substitute.
                    'url' => $this->generateUrl('api_extensions', [], UrlGeneratorInterface::ABSOLUTE_URL).'/{slug}',
                    'description' => 'One extension, with the Shopware versions it declares support for.',
                ],
                'composer' => [
                    'url' => $this->generateUrl('repo_root', [], UrlGeneratorInterface::ABSOLUTE_URL),
                    'description' => 'Composer v2 repository for the extensions Packagist does not carry.',
                ],
                'feed' => [
                    'url' => $this->generateUrl('feed_atom', [], UrlGeneratorInterface::ABSOLUTE_URL),
                    'description' => 'Newly indexed extensions.',
                ],
            ],
            'readThisFirst' => [
                'compatibility' => 'Read from the shopware/core constraint the maintainer declared. '
                    .'Nothing here has been installed against a running Shopware. The field is called '
                    .'declaresSupportFor for that reason.',
                'licence' => 'A public repository is not permission to redistribute. Check '
                    .'licence.redistributable before recommending an install.',
            ],
            'cache' => 'Responses are public for one hour. The catalogue changes once a night.',
        ]);
    }

    #[Route('/api/extensions', name: 'api_extensions', methods: ['GET'])]
    public function list(
        Request $request,
        #[Autowire(service: 'limiter.api')]
        RateLimiterFactoryInterface $limiter,
    ): JsonResponse {
        if (!$limiter->create($request->getClientIp() ?? 'anonymous')->consume(1)->isAccepted()) {
            return Problem::response(429, 'Too many requests. The catalogue changes once a night; please cache.', $request->getPathInfo());
        }

        // Parsed by the same object the HTML listing uses, so a filter cannot mean one
        // thing here and another there.
        $criteria = SearchCriteria::fromRequest($request);
        $result = $this->search->search($criteria);

        return $this->cacheable([
            'total' => $result->total,
            'page' => $criteria->page,
            'pageCount' => $result->pageCount(),
            'perPage' => SearchCriteria::PER_PAGE,
            'filters' => $criteria->toCanonicalParameters(),
            'extensions' => array_map(
                fn ($extension): array => $this->serialiser->toArray($extension),
                $result->extensions,
            ),
        ]);
    }

    #[Route('/api/extensions/{slug}', name: 'api_extension', requirements: ['slug' => Requirement::CATCH_ALL], methods: ['GET'])]
    public function detail(
        string $slug,
        Request $request,
        #[Autowire(service: 'limiter.api')]
        RateLimiterFactoryInterface $limiter,
    ): JsonResponse {
        if (!$limiter->create($request->getClientIp() ?? 'anonymous')->consume(1)->isAccepted()) {
            return Problem::response(429, 'Too many requests. The catalogue changes once a night; please cache.', $request->getPathInfo());
        }

        $extension = $this->extensions->findOneBySlug($slug);

        // Delisted work is gone from here for the same reason it is gone from the
        // pages: a takedown that leaves the data reachable through a second door is
        // not a takedown.
        if (null === $extension || !$extension->getIndexStatus()->isPubliclyVisible()) {
            return Problem::response(404, 'No extension with that slug is listed.', $request->getPathInfo());
        }

        return $this->cacheable($this->serialiser->toArrayWithCompatibility(
            $extension,
            $this->claims->findMatrixForExtension($extension),
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function cacheable(array $payload): JsonResponse
    {
        $response = new JsonResponse($payload);

        // Nothing here is per-visitor and the underlying data moves once a night, so
        // an intermediary is welcome to hold it. This is also the polite half of the
        // rate limit: a caller who respects it will rarely reach the ceiling.
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
