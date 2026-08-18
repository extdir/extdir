<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
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
