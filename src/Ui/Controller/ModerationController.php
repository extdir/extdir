<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Catalog\Repository\ExtensionRepository;
use App\Moderation\Entity\Complaint;
use App\Moderation\Enum\ComplaintKind;
use App\Moderation\Enum\ComplaintStatus;
use App\Moderation\Repository\ComplaintRepository;
use App\Submission\Entity\ModerationAction;
use App\Submission\Entity\User;
use App\Submission\Enum\ModerationActionType;
use App\Submission\Repository\ModerationActionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reporting an extension, and acting on those reports.
 *
 * The takedown policy has promised a notice-and-takedown procedure since launch, and
 * until now the only implementation was an inbox and hand-written SQL. Nobody could
 * say how many complaints were open, how old the oldest one was, or whether the
 * seven days had already run out.
 *
 * The reporting half is public and needs no account. A rights holder is a lawyer or
 * a brand owner, not a GitHub user, and asking them to sign in before they can
 * object would be a barrier exactly where a barrier looks like evasion.
 *
 * The acting half needs ROLE_MODERATOR, which is set by hand in the database. No
 * amount of GitHub standing grants it, and it is deliberately not something ownership
 * verification can escalate into.
 */
final class ModerationController extends AbstractController
{
    public function __construct(
        private readonly ExtensionRepository $extensions,
        private readonly ComplaintRepository $complaints,
        private readonly ModerationActionRepository $actions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/report/{slug}', name: 'complaint_new', methods: ['GET'])]
    public function report(string $slug): Response
    {
        $extension = $this->extensions->findOneBySlug($slug);

        if (null === $extension) {
            throw $this->createNotFoundException();
        }

        return $this->render('moderation/report.html.twig', [
            'extension' => $extension,
            'kinds' => ComplaintKind::cases(),
        ]);
    }

    #[Route('/report/{slug}', name: 'complaint_submit', methods: ['POST'])]
    public function submit(
        string $slug,
        Request $request,
        #[Autowire(service: 'limiter.complaint')]
        RateLimiterFactoryInterface $limiter,
    ): Response {
        $extension = $this->extensions->findOneBySlug($slug);

        if (null === $extension) {
            throw $this->createNotFoundException();
        }

        // Not an AccessDeniedException: Symfony answers that for an anonymous visitor
        // by redirecting into the GitHub sign-in, and this form's whole premise is
        // that a rights holder never needs an account. An expired token means try
        // again, not authenticate.
        if (!$this->isCsrfTokenValid('report'.$slug, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That form had expired. Please try again.');

            return $this->redirectToRoute('complaint_new', ['slug' => $slug]);
        }

        // An unauthenticated form that writes to the database needs a ceiling, or the
        // queue becomes the attack surface. Generous enough that a genuine rights
        // holder reporting several extensions in one sitting is never blocked.
        if (!$limiter->create($request->getClientIp() ?? 'anonymous')->consume(1)->isAccepted()) {
            $this->addFlash('error', 'Too many reports from this network. Email legal@extdir.com instead.');

            return $this->redirectToRoute('complaint_new', ['slug' => $slug]);
        }

        $reporter = trim((string) $request->request->get('reporter', ''));
        $body = trim((string) $request->request->get('body', ''));
        $kind = ComplaintKind::tryFrom((string) $request->request->get('kind', '')) ?? ComplaintKind::Other;

        if ('' === $reporter || '' === $body) {
            $this->addFlash('error', 'Please say how to reach you and what the problem is.');

            return $this->redirectToRoute('complaint_new', ['slug' => $slug]);
        }

        $this->em->persist(new Complaint($extension, $kind, mb_substr($reporter, 0, 255), $body));
        $this->em->flush();

        $this->addFlash(
            'success',
            $kind->isUrgent()
                ? 'Received. Rights and security reports are answered within seven days.'
                : 'Received. Thank you, corrections are usually applied at the next crawl.',
        );

        return $this->redirectToRoute('extension_detail', ['slug' => $slug]);
    }

    #[Route('/moderate', name: 'moderate', methods: ['GET'])]
    #[IsGranted('ROLE_MODERATOR')]
    public function queue(): Response
    {
        return $this->render('moderation/queue.html.twig', [
            'open' => $this->complaints->findOpen(),
            'resolved' => $this->complaints->findRecentlyResolved(),
            'recentActions' => $this->actions->findBy([], ['createdAt' => 'DESC'], 25),
        ]);
    }

    /**
     * Delist or relist, with the reason recorded either way.
     *
     * Both directions go through the same action so the audit trail is symmetrical:
     * a removal and its reversal are equally visible, and neither can happen without
     * somebody writing down why.
     */
    #[Route('/moderate/{slug}/{action}', name: 'moderate_act', requirements: ['action' => 'delist|relist'], methods: ['POST'])]
    #[IsGranted('ROLE_MODERATOR')]
    public function act(string $slug, string $action, Request $request): RedirectResponse
    {
        $extension = $this->extensions->findOneBySlug($slug);

        if (null === $extension) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('moderate'.$slug, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }

        $reason = trim((string) $request->request->get('reason', ''));

        // Required in both directions. A moderator action with no stated reason is
        // indistinguishable from an accident when someone reads the log a year later.
        if ('' === $reason) {
            $this->addFlash('error', 'A reason is required, in both directions.');

            return $this->redirectToRoute('moderate');
        }

        /** @var User $user */
        $user = $this->getUser();

        if ('delist' === $action) {
            $extension->delist($reason);
            $type = ModerationActionType::Delisted;
        } else {
            $extension->relist();
            $type = ModerationActionType::Relisted;
        }

        $this->em->persist(new ModerationAction($extension, $type, $reason, $user));

        // Resolving the complaints that prompted this closes the loop; otherwise the
        // queue keeps showing work that has already been done.
        if ($complaintId = $request->request->get('complaint')) {
            $complaint = $this->complaints->find($complaintId);

            if (null !== $complaint && !$complaint->getStatus()->isClosed()) {
                $complaint->resolve(ComplaintStatus::Upheld, $reason, $user);
            }
        }

        $this->em->flush();
        $this->addFlash('success', \sprintf('%s %s.', $extension->getPackageName(), 'delist' === $action ? 'delisted' : 'relisted'));

        return $this->redirectToRoute('moderate');
    }

    #[Route('/moderate/complaint/{id}/reject', name: 'moderate_reject', methods: ['POST'])]
    #[IsGranted('ROLE_MODERATOR')]
    public function reject(string $id, Request $request): RedirectResponse
    {
        $complaint = $this->complaints->find($id);

        if (null === $complaint) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('reject'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }

        $reason = trim((string) $request->request->get('reason', ''));

        if ('' === $reason) {
            $this->addFlash('error', 'Say why it was rejected. A complainant who disagrees is entitled to the reasoning.');

            return $this->redirectToRoute('moderate');
        }

        /** @var User $user */
        $user = $this->getUser();
        $complaint->resolve(ComplaintStatus::Rejected, $reason, $user);
        $this->em->flush();

        $this->addFlash('success', 'Complaint rejected and the reasoning recorded.');

        return $this->redirectToRoute('moderate');
    }
}
