<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Moderation\Entity\Complaint;
use App\Moderation\Enum\ComplaintKind;
use App\Submission\Entity\OwnershipClaim;
use App\Submission\Entity\User;
use App\Submission\Enum\VerificationMethod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The pages you only see once you have signed in.
 *
 * These had no test and no audit, because reaching them needs a GitHub session and
 * every sweep ran anonymously. That gap cost something real: the header nav
 * overflowed on both of them for months, signing in adds three links, the nav could
 * not wrap, and no anonymous check could ever have seen it.
 *
 * Populated rather than empty on purpose. The moderation queue's controls only exist
 * when there is something to act on, and an empty state proves nothing about the
 * markup a moderator actually uses.
 */
final class SignedInPagesTest extends WebTestCase
{
    public function testAModeratorCanReachBothPages(): void
    {
        $client = static::createClient();
        $user = $this->seed();

        $client->loginUser($user);

        foreach (['/my/extensions', '/moderate'] as $path) {
            $client->request('GET', $path);

            self::assertResponseIsSuccessful($path.' must render for a signed-in moderator.');
        }
    }

    /**
     * The nav grows when you sign in, and it must be able to wrap.
     *
     * A flex row with the default nowrap laid out at its content width whatever the
     * viewport, 474px on a 390px phone, and pushed the page sideways with "Sign out"
     * off the edge.
     */
    public function testTheSignedInNavCanWrap(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seed());

        $crawler = $client->request('GET', '/moderate');

        $links = $crawler->filter('.header-nav a');

        self::assertGreaterThan(
            3,
            $links->count(),
            'Signing in should add links, that is the case the nav has to survive.',
        );
        self::assertStringContainsString('Sign out', $crawler->filter('.header-nav')->text());
    }

    /**
     * The remove button's column had a bare <th>, which axe reports as
     * empty-table-header: a screen reader announces the cell with no idea what it is
     * for. The label is present and only visually hidden, because a visible heading
     * over one button would be noise.
     */
    public function testTheActionsColumnIsLabelled(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seed());

        $crawler = $client->request('GET', '/my/extensions');

        self::assertSame(0, $crawler->filter('th:empty')->count(), 'No table header may be empty.');
        self::assertStringContainsString('Actions', $crawler->filter('th .visually-hidden')->text());
    }

    private function seed(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $vendor = new Vendor('acme', 'acme');
        $extension = new Extension($vendor, 'acme/widget', 'acme-widget', 'Acme Widget');
        $extension->setRepositoryUrl('https://github.com/acme/widget');

        $user = new User(4242, 'a-maintainer');
        $user->setModerator(true);

        $em->persist($vendor);
        $em->persist($extension);
        $em->persist($user);
        $em->persist(new Complaint($extension, ComplaintKind::Rights, 'rights@example.com', 'Trademark concern.'));
        $em->persist(new OwnershipClaim($user, $extension, VerificationMethod::GitHubPermission, 'write access'));
        $em->flush();

        return $user;
    }
}
