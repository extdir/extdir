<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Legally required pages.
 *
 * The legal obligations treats these as launch blockers rather than follow-up work, and
 * names a defective Impressum as a live Abmahnung risk in Germany — not a
 * theoretical one.
 *
 * The operator's name and postal address are read from the environment rather than
 * written here. They are one private individual's home details: § 5 DDG requires
 * them to be published on the site, but nothing requires them to sit in a public
 * git repository, which is scraped for personal data far more systematically than
 * a website is. The values live in .env.local on the server and nowhere in version
 * control.
 *
 * An unset value is treated as a fatal misconfiguration rather than an empty
 * string. An Impressum that renders a blank address is a worse defect than one that
 * fails to render at all, because it looks compliant and is not — and § 5 is
 * enforced by competitors sending invoices, not by a validator.
 *
 * The address is served from a separate endpoint rather than in the page, behind a
 * timed reveal. This is a considered trade and it is worth being honest about which
 * way it cuts. § 5 wants the details "leicht erkennbar, unmittelbar erreichbar und
 * ständig verfügbar", and a reveal that needs JavaScript, a minute of waiting and an
 * IP budget is a weaker claim on all three than plain markup would be. Against that:
 * this is a private individual's home address, GitHub and the open web are harvested
 * for exactly this, and the operator runs the same mechanism on another site. The
 * mitigations that follow from that reading are deliberate — the endpoint needs no
 * JavaScript to answer, the limit is per hour rather than per day, and the operator's
 * name and a monitored email stay in the markup unconditionally, so the page always
 * identifies who is behind it and offers a way to reach them.
 */
final class LegalController extends AbstractController
{
    /**
     * Operator details as published.
     *
     * Amer Malik Mohammed operates extdir as a private individual. There is no
     * Handelsregister entry and no USt-IdNr, and neither is a gap: § 5 Abs. 1 Nr. 4
     * and Nr. 6 require them only "soweit vorhanden". A regulated-profession block
     * (§ 5 Abs. 1 Nr. 5) does not apply either — software development is not a
     * chambered profession.
     *
     * The email address is not among these because it is a role address on this
     * domain rather than personal data, and § 5 wants a contact route that is
     * immediately visible; it is written directly in the templates.
     */
    public function __construct(
        #[Autowire('%env(OPERATOR_NAME)%')]
        private readonly string $operatorName,
        #[Autowire('%env(OPERATOR_STREET)%')]
        private readonly string $operatorStreet,
        #[Autowire('%env(OPERATOR_POSTAL_CITY)%')]
        private readonly string $operatorPostalCity,
        #[Autowire('%env(OPERATOR_COUNTRY)%')]
        private readonly string $operatorCountry,
    ) {
    }

    /**
     * @return array{name: string, street: string, postalCity: string, country: string, email: string}
     */
    private function operator(): array
    {
        $operator = [
            'name' => $this->operatorName,
            'street' => $this->operatorStreet,
            'postalCity' => $this->operatorPostalCity,
            'country' => $this->operatorCountry,
            'email' => self::EMAIL,
        ];

        foreach ($operator as $field => $value) {
            if ('' === trim($value)) {
                throw new \LogicException(\sprintf('The operator %s is empty. Set OPERATOR_%s in .env.local and re-run "composer dump-env prod"; publishing this page without it would be a defective Impressum under § 5 DDG.', $field, strtoupper(preg_replace('/(?<!^)[A-Z]/', '_$0', $field) ?? $field)));
            }
        }

        return $operator;
    }

    /**
     * Whether a data processing agreement under Art. 28 GDPR has actually been
     * concluded with the hosting provider.
     *
     * Deliberately a switch rather than a sentence in the template. The privacy
     * policy previously stated the agreement existed, which was written from the
     * standard generator wording and never verified — and a privacy policy that
     * claims a contract you do not hold is worse than one that stays quiet, because
     * it is the document a supervisory authority reads first.
     *
     * Hosting is processing: server logs contain IP addresses, which are personal
     * data. Art. 28(3) requires the agreement in writing, so this is not optional
     * paperwork to be deferred — it is a precondition for the site being lawful to
     * operate. Flip this to true once it is signed and the paragraph appears.
     */
    public const HOSTING_DPA_CONCLUDED = false;

    /**
     * A role address on a domain we control, so it is not personal data and stays
     * in the repository.
     */
    private const EMAIL = 'legal@extdir.com';

    #[Route('/imprint', name: 'imprint', methods: ['GET'])]
    public function imprint(): Response
    {
        return $this->render('legal/imprint.html.twig', ['operator' => $this->operator()]);
    }

    /**
     * The postal address on its own, as JSON.
     *
     * Not a security boundary and not treated as one: it answers any client that
     * asks, without a token or a referer check. Requiring either would only break
     * the visitor who has JavaScript disabled while a scraper reproduced it in an
     * afternoon. The rate limit is the actual control, and it is set per IP per
     * hour because harvesting is a volume activity and reading an imprint is not.
     */
    #[Route('/imprint/contact-details.json', name: 'imprint_contact_details', methods: ['GET'])]
    public function imprintContactDetails(
        Request $request,
        #[Autowire(service: 'limiter.imprint_reveal')]
        RateLimiterFactoryInterface $limiter,
    ): JsonResponse {
        $limit = $limiter->create($request->getClientIp() ?? 'anonymous')->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            $response = new JsonResponse([
                'error' => 'Too many requests. The imprint email address reaches a person immediately.',
                'retryAfterSeconds' => $retryAfter,
            ], Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) $retryAfter);

            return $response;
        }

        $operator = $this->operator();
        unset($operator['email']);

        $response = new JsonResponse($operator);
        // Never cached and never indexed: a cached copy in a shared proxy, or an
        // indexed one in a search engine, would put back exactly what keeping the
        // address out of the HTML was meant to prevent.
        $response->headers->set('Cache-Control', 'no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    #[Route('/privacy', name: 'privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('legal/privacy.html.twig', [
            'operator' => $this->operator(),
            'hostingDpaConcluded' => self::HOSTING_DPA_CONCLUDED,
        ]);
    }

    #[Route('/terms', name: 'terms', methods: ['GET'])]
    public function terms(): Response
    {
        return $this->render('legal/terms.html.twig');
    }

    #[Route('/takedown', name: 'takedown', methods: ['GET'])]
    public function takedown(): Response
    {
        return $this->render('legal/takedown.html.twig', ['operator' => $this->operator()]);
    }
}
