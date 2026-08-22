<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Catalog\Enum\DiscoverySource;
use App\Catalog\Repository\ExtensionRepository;
use App\Ingestion\GitHub\GitHubPackageAssembler;
use App\Ingestion\PackageIngestor;
use App\Signals\RepositoryEnricher;
use App\Submission\Entity\ModerationAction;
use App\Submission\Enum\ModerationActionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "You have missed one", a way in for extensions the crawlers cannot see.
 *
 * Three channels feed the catalogue and each has a blind spot. Packagist misses
 * anything never published there; topics miss anyone who did not label their
 * repository; repository search misses whatever falls outside its queries or past the
 * API's thousand-result ceiling. A maintainer reported a plugin that fell through all
 * three, which is what this exists for.
 *
 * Deliberately not an ownership claim. Pointing at a public repository is discovery,
 * not an assertion of rights, and conflating the two would mean asking a passer-by to
 * attest to something they cannot know. Verification stays where it is, behind a
 * login, at /my/verify/{slug}.
 *
 * No account, matching the report form: the point is to remove friction from someone
 * doing us a favour. What keeps it safe is not a login but the gate, a submission is
 * only accepted if the repository's own composer.json declares
 * `shopware-platform-plugin`, which is the same test every crawled candidate passes
 * and is not something a spammer can satisfy with an arbitrary URL.
 */
final class SubmissionController extends AbstractController
{
    public function __construct(
        private readonly ExtensionRepository $extensions,
        private readonly GitHubPackageAssembler $assembler,
        private readonly PackageIngestor $ingestor,
        private readonly RepositoryEnricher $enricher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/submit', name: 'submission_new', methods: ['GET'])]
    public function form(): Response
    {
        return $this->render('submission/new.html.twig');
    }

    #[Route('/submit', name: 'submission_submit', methods: ['POST'])]
    public function submit(
        Request $request,
        #[Autowire(service: 'limiter.submission')]
        RateLimiterFactoryInterface $limiter,
    ): Response {
        // Deliberately not an AccessDeniedException. For an anonymous visitor Symfony
        // answers that by starting authentication, so a stale token on a form that
        // advertises "no account needed" would bounce them to a GitHub sign-in they
        // never asked for. A tab left open overnight is the usual cause, and the fix
        // is to submit again, not to log in.
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That form had expired. Please try again.');

            return $this->redirectToRoute('submission_new');
        }

        // Each accepted submission costs several GitHub requests and can write a new
        // extension. Without a ceiling this endpoint is a way to spend our rate limit
        // and fill the catalogue from outside.
        if (!$limiter->create($request->getClientIp() ?? 'anonymous')->consume(1)->isAccepted()) {
            $this->addFlash('error', 'Too many submissions from this network. Try again in an hour.');

            return $this->redirectToRoute('submission_new');
        }

        $url = trim((string) $request->request->get('url', ''));
        $fullName = self::gitHubRepository($url);

        if (null === $fullName) {
            $this->addFlash(
                'error',
                'That does not look like a GitHub repository address. Paste the repository URL, for example https://github.com/vendor/plugin.',
            );

            return $this->redirectToRoute('submission_new');
        }

        try {
            $assembled = $this->assembler->assemble($fullName);
        } catch (\Throwable) {
            $this->addFlash('error', 'GitHub could not be reached just now. Please try again later.');

            return $this->redirectToRoute('submission_new');
        }

        if (null === $assembled) {
            // By far the most common rejection, and the only one the submitter can act
            // on, so it says exactly what is missing rather than "not accepted".
            $this->addFlash(
                'error',
                'That repository does not look like a Shopware 6 extension: its composer.json must declare "type": "shopware-platform-plugin", and it needs at least one tagged release.',
            );

            return $this->redirectToRoute('submission_new');
        }

        $existing = $this->extensions->findOneByPackageName($assembled['package']);

        if (null !== $existing) {
            // Already indexed, usually under a package name that does not resemble the
            // repository name. Sending them to it beats telling them they were wrong.
            $this->addFlash('success', 'Already in the directory, here it is.');

            return $this->redirectToRoute('extension_detail', ['slug' => $existing->getSlug()]);
        }

        $extension = $this->ingestor->ingest(
            $assembled['package'],
            $assembled['versions'],
            DiscoverySource::Submitted,
        );

        if (null === $extension) {
            $this->addFlash('error', 'That repository has no release we can read a Shopware version from.');

            return $this->redirectToRoute('submission_new');
        }

        // Without this the page would show no licence, no maintenance signal and no
        // compatibility matrix until the nightly crawl, an empty page as the reward
        // for helping. One extension is a single batched query.
        try {
            $this->enricher->enrich([$extension]);
        } catch (\Throwable) {
            // Enrichment is a nicety here; the nightly pass will pick it up. Never
            // lose an accepted submission because signals were briefly unavailable.
        }

        $this->em->persist(new ModerationAction(
            $extension,
            ModerationActionType::Submitted,
            \sprintf('Submitted via %s', $fullName),
        ));
        $this->em->flush();

        $this->addFlash('success', 'Added. Thank you, the compatibility data fills in as it is crawled.');

        return $this->redirectToRoute('extension_detail', ['slug' => $extension->getSlug()]);
    }

    /**
     * The "owner/repo" in a GitHub URL, or null for anything else.
     *
     * GitHub only, because the assembler is: it reads tags and composer.json through
     * GitHub's GraphQL API, and there is no equivalent path for the other forges yet.
     * Saying so plainly beats accepting a GitLab URL and silently doing nothing.
     */
    private static function gitHubRepository(string $url): ?string
    {
        if ('' === $url) {
            return null;
        }

        // Accept a bare "owner/repo" too, it is what people paste from a README.
        if (!str_contains($url, '://') && preg_match('~^[\w.-]+/[\w.-]+$~', $url)) {
            return rtrim($url, '/');
        }

        $parts = parse_url($url);

        if (!\is_array($parts) || !isset($parts['host'], $parts['path'])) {
            return null;
        }

        $host = strtolower($parts['host']);

        if ('github.com' !== $host && 'www.github.com' !== $host) {
            return null;
        }

        $path = preg_replace('/\.git$/i', '', trim($parts['path'], '/')) ?? '';
        $segments = explode('/', $path);

        // Tolerate a deep link, /tree/main, /blob/..., because that is what the
        // address bar holds when somebody is looking at the repository.
        if (\count($segments) < 2 || '' === $segments[0] || '' === $segments[1]) {
            return null;
        }

        return $segments[0].'/'.$segments[1];
    }
}
