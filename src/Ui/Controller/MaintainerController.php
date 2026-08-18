<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Catalog\Repository\ExtensionRepository;
use App\Submission\Entity\ModerationAction;
use App\Submission\Entity\User;
use App\Submission\Enum\ModerationActionType;
use App\Submission\OwnershipVerifier;
use App\Submission\Repository\OwnershipClaimRepository;
use App\Submission\Security\GitHubAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sign-in and the maintainer's own controls.
 *
 * The takedown policy promises that a maintainer can have their extension removed
 * without giving a reason, within seven days. This is that promise made
 * self-service: a verified maintainer removes their own extension in one click and
 * nobody has to be awake for it. A policy that depends on one person reading email
 * is a policy with a seven-day floor and a holiday-shaped hole.
 */
final class MaintainerController extends AbstractController
{
    public function __construct(
        private readonly ExtensionRepository $extensions,
        private readonly OwnershipVerifier $verifier,
        private readonly OwnershipClaimRepository $claims,
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(GITHUB_APP_CLIENT_ID)%')]
        private readonly string $clientId,
    ) {
    }

    #[Route('/auth/github', name: 'auth_github', methods: ['GET'])]
    public function startSignIn(Request $request): RedirectResponse
    {
        $session = $request->getSession();

        // Remembered so the callback can return the user to the extension they
        // were looking at rather than to a generic dashboard.
        $slug = $request->query->get('extension');
        if (\is_string($slug) && '' !== $slug) {
            $session->set(GitHubAuthenticator::PENDING_EXTENSION_KEY, $slug);
        }

        $state = bin2hex(random_bytes(16));
        $session->set(GitHubAuthenticator::STATE_KEY, $state);

        return new RedirectResponse('https://github.com/login/oauth/authorize?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->generateUrl('auth_github_callback', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'state' => $state,
            // No scopes are requested. Reading whether the signed-in user has push
            // access to a public repository needs none, and asking for more than
            // the job requires is how an integration ends up holding rights it
            // cannot justify.
            'scope' => '',
        ]));
    }

    /**
     * Handled entirely by GitHubAuthenticator; this exists so the route does.
     */
    #[Route('/auth/github/callback', name: 'auth_github_callback', methods: ['GET'])]
    public function callback(): Response
    {
        throw new \LogicException('Handled by the authenticator.');
    }

    #[Route('/logout', name: 'logout', methods: ['GET'])]
    public function logout(): void
    {
        throw new \LogicException('Handled by the firewall.');
    }

    #[Route('/my/extensions', name: 'my_extensions', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function myExtensions(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('maintainer/extensions.html.twig', [
            'claims' => $this->claims->findActiveFor($user),
        ]);
    }

    #[Route('/my/verify/{slug}', name: 'ownership_verify', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function verify(string $slug, Request $request): Response
    {
        $extension = $this->extensions->findOneBySlug($slug);

        if (null === $extension) {
            throw $this->createNotFoundException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $session = $request->getSession();

        $accessToken = $session->get(GitHubAuthenticator::ACCESS_TOKEN_KEY);
        // Cleared immediately whatever happens next. It is only ever meant to
        // survive from the callback to this one request.
        $session->remove(GitHubAuthenticator::ACCESS_TOKEN_KEY);
        $session->remove(GitHubAuthenticator::PENDING_EXTENSION_KEY);

        if (!\is_string($accessToken)) {
            $this->addFlash('error', 'Your sign-in has expired. Please start the verification again.');

            return $this->redirectToRoute('extension_detail', ['slug' => $slug]);
        }

        $result = $this->verifier->verifyWithGitHub($user, $extension, $accessToken);

        $this->addFlash($result->isVerified ? 'success' : ($result->isAvailable ? 'error' : 'warning'), $result->message);

        return $this->redirectToRoute('extension_detail', ['slug' => $slug]);
    }

    /**
     * Self-service removal, honouring the takedown policy.
     *
     * Deliberately requires no reason. The policy says so in as many words, and
     * asking anyway would turn a right into a negotiation.
     */
    #[Route('/my/delist/{slug}', name: 'ownership_delist', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delist(string $slug, Request $request): Response
    {
        $extension = $this->extensions->findOneBySlug($slug);

        if (null === $extension) {
            throw $this->createNotFoundException();
        }

        /** @var User $user */
        $user = $this->getUser();

        if (!$this->verifier->mayActOn($user, $extension)) {
            throw $this->createAccessDeniedException('You have not verified ownership of this extension.');
        }

        if (!$this->isCsrfTokenValid('delist'.$slug, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }

        $reason = trim((string) $request->request->get('reason', ''));
        $extension->delist('' !== $reason ? $reason : 'Removal requested by the verified maintainer.');

        $this->em->persist(new ModerationAction(
            $extension,
            ModerationActionType::Delisted,
            '' !== $reason ? $reason : 'Requested by the verified maintainer; no reason required.',
            $user,
        ));
        $this->em->flush();

        $this->addFlash(
            'success',
            'Removed. It will not reappear in a later crawl. Email us if you change your mind.',
        );

        return $this->redirectToRoute('my_extensions');
    }
}
