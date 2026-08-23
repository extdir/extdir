<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Catalog\Repository\ExtensionRepository;
use App\Compatibility\Repository\ShopwareVersionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The files written for machines rather than people.
 *
 * Three of them, and they are not equally well founded. security.txt is RFC 9116, an
 * actual IETF standard. Atom is twenty years old and outlived several replacements.
 * llms.txt is a community convention with no standards body behind it, and measured
 * traffic says AI search crawlers almost never fetch it.
 *
 * It earns its place here anyway, for a reason specific to this site: IDE agents do
 * fetch it, routinely, and this directory's readers are Shopware developers working in
 * exactly those tools. A file nobody reads is clutter; this one has a known reader.
 */
final class MachineReadableController extends AbstractController
{
    /**
     * How long a security.txt stays valid.
     *
     * RFC 9116 requires Expires and treats a past date as invalid, which is why this is
     * computed per request rather than typed into a static file. A hand-written date
     * rots quietly: the file keeps serving, and only a validator notices it stopped
     * counting.
     */
    private const string VALID_FOR = '+1 year';

    /** Entries in the feed. */
    private const int FEED_SIZE = 30;

    public function __construct(
        private readonly ExtensionRepository $extensions,
        private readonly ShopwareVersionRepository $shopwareVersions,
    ) {
    }

    /**
     * What this directory is, for something about to answer a question with it.
     */
    #[Route('/llms.txt', name: 'llms_txt', methods: ['GET'])]
    public function llms(): Response
    {
        $response = $this->render('machine/llms.txt.twig', [
            'total' => \count($this->extensions->findPubliclyVisible()),
            'currentVersion' => $this->shopwareVersions->findCurrent(),
        ]);

        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    /**
     * RFC 9116.
     *
     * Expected of anything that indexes software, and this one already runs a rights
     * and takedown process at the same address, so the contact exists whether or not
     * the file does.
     */
    #[Route('/.well-known/security.txt', name: 'security_txt', methods: ['GET'])]
    public function securityTxt(): Response
    {
        $expires = (new \DateTimeImmutable())->modify(self::VALID_FOR);

        $body = <<<SECURITY
            Contact: mailto:legal@extdir.com
            Expires: {$expires->format(\DateTimeInterface::ATOM)}
            Preferred-Languages: en, de
            Canonical: {$this->absoluteUrl('security_txt')}
            Policy: {$this->absoluteUrl('takedown')}

            # extdir indexes public metadata about other people's software and hosts
            # none of it. A vulnerability in an indexed extension belongs to its
            # maintainer, whose repository is linked on every entry. This address is
            # for the directory itself.
            SECURITY;

        $response = new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
        $response->setPublic();
        $response->setMaxAge(86400);

        return $response;
    }

    private function absoluteUrl(string $route): string
    {
        return $this->generateUrl($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Newly indexed extensions.
     *
     * Ordered by when the directory first saw a package, not by when it was written, so
     * it answers "what is new here" rather than "what is new in Shopware". Those differ:
     * a plugin published in 2021 and discovered last week is new to a reader of this
     * feed and old to everyone else.
     */
    #[Route('/feed.atom', name: 'feed_atom', methods: ['GET'])]
    public function feed(): Response
    {
        $response = $this->render('machine/feed.atom.twig', [
            'extensions' => $this->extensions->findRecentlyIndexed(self::FEED_SIZE),
        ]);

        $response->headers->set('Content-Type', 'application/atom+xml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
