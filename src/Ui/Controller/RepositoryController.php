<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Satis\ComposerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Composer v2 repository endpoint.
 *
 * Serves only extensions that are not on Packagist. For anything Packagist already
 * carries, telling a merchant to add this repository would insert us into their
 * dependency resolution for no benefit and one more thing to go down.
 */
final class RepositoryController extends AbstractController
{
    public function __construct(
        private readonly ComposerRepository $repository,
    ) {
    }

    /**
     * What a person sees when they paste the repository URL into a browser.
     *
     * Composer never requests this path — given a `composer` repository it appends
     * `/packages.json` itself — so nothing was ever served here and the address
     * answered with the generic 404. That is precisely the wrong answer: checking the
     * URL before adding a stranger's repository to a project is the careful thing to
     * do, and the reward was a page saying the site was broken.
     */
    #[Route('/repo', name: 'repo_landing', methods: ['GET'])]
    public function landing(): Response
    {
        return $this->render('repo/landing.html.twig', [
            'packages' => $this->repository->publishablePackageNames(),
        ]);
    }

    #[Route('/repo/packages.json', name: 'repo_root', methods: ['GET'])]
    public function root(): JsonResponse
    {
        // Composer substitutes %package% itself, so the template is a literal
        // rather than something the router can generate.
        $response = new JsonResponse($this->repository->root('/repo/p2/%package%.json'));

        // Composer honours HTTP caching, and this document changes only when the
        // published set does.
        $response->setPublic();
        $response->setMaxAge(1800);

        return $response;
    }

    /**
     * Per-package metadata. The `{package}` placeholder carries a slash, so the
     * route has to accept one.
     */
    #[Route(
        '/repo/p2/{package}.json',
        name: 'repo_package',
        requirements: ['package' => '[a-z0-9]([_.-]?[a-z0-9])*/[a-z0-9](([_.]?|-{0,2})[a-z0-9])*'],
        methods: ['GET'],
    )]
    public function package(string $package): Response
    {
        $metadata = $this->repository->package($package);

        if (null === $metadata) {
            // A 404 here is a normal answer, not an error: it is how Composer
            // learns the package is not ours to serve.
            return new JsonResponse(['status' => 'not found'], Response::HTTP_NOT_FOUND);
        }

        $response = new JsonResponse($metadata);
        $response->setPublic();
        $response->setMaxAge(1800);

        return $response;
    }
}
