<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Legally required pages.
 *
 * The legal obligations treats these as launch blockers rather than follow-up work, and
 * names a defective Impressum as a live Abmahnung risk in Germany — not a
 * theoretical one.
 *
 * A note on the contact details, because there is a tempting trick here and this
 * codebase deliberately does not use it. It is possible to keep the postal address
 * out of the HTML and serve it from a rate-limited, JavaScript-triggered endpoint,
 * which does defeat naive scrapers. The problem is DDG § 5's actual requirement:
 * the information must be "leicht erkennbar, unmittelbar erreichbar und ständig
 * verfügbar". An address that needs JavaScript, a deliberate delay and an IP
 * budget is arguably none of those — and a visitor behind carrier-grade NAT can be
 * refused it outright. That trades a small spam nuisance for the exact defect the
 * Abmahnung industry looks for.
 *
 * The privacy win is taken instead where it is free: § 5 does not require a
 * telephone number at all. The ECJ (C-298/07) held that an email address plus a
 * second route for fast, direct contact is sufficient. So the phone number is
 * simply not published, and the address — which is required, and is not what spam
 * harvesters are after — is served as plain text.
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
     */
    public const OPERATOR = [
        'name' => 'Amer Malik Mohammed',
        'street' => '[redacted]',
        'postalCity' => '[redacted]',
        'country' => 'Germany',
        'email' => 'legal@extdir.com',
    ];

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

    #[Route('/imprint', name: 'imprint', methods: ['GET'])]
    public function imprint(): Response
    {
        return $this->render('legal/imprint.html.twig', ['operator' => self::OPERATOR]);
    }

    #[Route('/privacy', name: 'privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('legal/privacy.html.twig', [
            'operator' => self::OPERATOR,
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
        return $this->render('legal/takedown.html.twig', ['operator' => self::OPERATOR]);
    }
}
