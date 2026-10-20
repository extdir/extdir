<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Catalog\Enum\DiscoverySource;
use App\Catalog\Enum\IndexStatus;
use App\License\Enum\FindingSource;
use App\License\Enum\LicenseStatus;
use App\Signals\Entity\PlumbScan;
use App\Signals\Enum\PlumbStatus;
use App\Ui\SecurityFindings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The supply-chain panel, and the three different kinds of nothing.
 *
 * This panel shows a third party's findings, so almost everything worth testing is
 * about honesty rather than about rendering: that an extension nobody has scanned
 * does not read as an extension that scored badly, and that the worst finding is the
 * first thing on the page rather than the fourth.
 */
final class SecurityFindingsTest extends WebTestCase
{
    /**
     * Plumb reads Packagist. An extension published only to its own forge is not
     * unscanned, it is unscannable, and it will still be unscannable next year.
     * Saying "not scanned yet" there would be a promise nobody intends to keep.
     */
    public function testAnExtensionOutsidePackagistIsNotMerelyUnscanned(): void
    {
        self::bootKernel();
        $extension = $this->extension(DiscoverySource::GitHubTopic);

        self::assertSame('off_packagist', $this->findings()->forExtension($extension)['state']);
    }

    /**
     * On Packagist and not reached yet. Roughly half of what is eligible today.
     */
    public function testAnExtensionPlumbHasNotReachedReportsAsUnscanned(): void
    {
        self::bootKernel();
        $extension = $this->extension(DiscoverySource::Packagist);

        self::assertSame('unscanned', $this->findings()->forExtension($extension)['state']);
    }

    /**
     * A row exists, but Plumb answered that it has no scan. Same outcome as no row:
     * there is nothing to show, and it is not a verdict.
     */
    public function testARecordedAbsenceIsStillAnAbsence(): void
    {
        self::bootKernel();
        $extension = $this->extension(DiscoverySource::Packagist);

        $scan = new PlumbScan($extension);
        $scan->setScannedAt(null);
        $this->em()->persist($scan);
        $this->em()->flush();

        self::assertSame('unscanned', $this->findings()->forExtension($extension)['state']);
    }

    /**
     * The failures lead, heaviest first, and the passes come after.
     *
     * Two people read this panel with opposite needs. A maintainer wants the list of
     * things to fix; a merchant wants to know whether there is anything to worry
     * about. Both are answered by the first line, and neither is answered by a panel
     * that opens with four passes.
     */
    public function testTheWorstFindingComesFirst(): void
    {
        self::bootKernel();
        $extension = $this->extension(DiscoverySource::Packagist);

        $scan = new PlumbScan($extension);
        $scan->setScannedAt(new \DateTimeImmutable('2026-10-01'));
        $scan->setSecurityScore(52.0);
        $scan->setChecks([
            $this->check('Passing check', 'pass', 10),
            $this->check('Not applicable here', 'not_applicable', 9),
            $this->check('Light failure', 'fail', 3),
            $this->check('Heavy failure', 'fail', 8),
            $this->check('A warning', 'warn', 7),
        ]);
        $this->em()->persist($scan);
        $this->em()->flush();

        $result = $this->findings()->forExtension($extension);

        self::assertSame('scanned', $result['state']);
        self::assertSame(
            ['Heavy failure', 'Light failure', 'A warning', 'Passing check', 'Not applicable here'],
            array_column($result['checks'], 'title'),
        );
        self::assertSame(3, $result['actionable'], 'two failures and a warning are actionable');
        self::assertSame(PlumbStatus::Fail, $result['checks'][0]['status']);
    }

    /**
     * A check Plumb adds tomorrow must not take the panel down.
     *
     * Their vocabulary is theirs to extend, and this site finds out by receiving one.
     * An unknown status reads as "does not apply", which shows the finding without
     * inventing a verdict for it.
     */
    public function testAnUnfamiliarStatusDoesNotBreakThePanel(): void
    {
        self::bootKernel();
        $extension = $this->extension(DiscoverySource::Packagist);

        $scan = new PlumbScan($extension);
        $scan->setScannedAt(new \DateTimeImmutable('2026-10-01'));
        $scan->setChecks([$this->check('Something new', 'inconclusive', 4)]);
        $this->em()->persist($scan);
        $this->em()->flush();

        $result = $this->findings()->forExtension($extension);

        self::assertSame(PlumbStatus::NotApplicable, $result['checks'][0]['status']);
        self::assertSame(0, $result['actionable']);
    }

    /**
     * "We could not look" must not read as "this does not apply to you".
     *
     * Plumb answers unassessable when the repository has been renamed, made private
     * or taken down, which is thirty one of the scanned extensions today, and error
     * when its own run threw. Both are the absence of a verdict. Not applicable is a
     * verdict of a kind, that the question was never this extension's to answer, and
     * telling a maintainer their missing repository simply does not apply to a check
     * would send them looking for nothing.
     */
    public function testACheckPlumbCouldNotAssessDoesNotReadAsOneThatDoesNotApply(): void
    {
        self::bootKernel();
        $extension = $this->extension(DiscoverySource::Packagist);

        $scan = new PlumbScan($extension);
        $scan->setScannedAt(new \DateTimeImmutable('2026-10-01'));
        $scan->setChecks([
            $this->check('Could not be looked at', 'unassessable', 8),
            $this->check('Threw on their side', 'error', 8),
            $this->check('Genuinely irrelevant', 'not_applicable', 8),
        ]);
        $this->em()->persist($scan);
        $this->em()->flush();

        $result = $this->findings()->forExtension($extension);
        $byTitle = array_column($result['checks'], 'status', 'title');

        self::assertSame(PlumbStatus::Unassessable, $byTitle['Could not be looked at']);
        self::assertSame(PlumbStatus::Unassessable, $byTitle['Threw on their side']);
        self::assertSame(PlumbStatus::NotApplicable, $byTitle['Genuinely irrelevant']);
        self::assertSame('Not assessed', PlumbStatus::Unassessable->label());
        self::assertSame(0, $result['actionable'], 'no verdict is not a finding to act on');

        // And it sorts above the inapplicable ones, because it is the more informative
        // of the two kinds of nothing.
        self::assertSame(
            ['Could not be looked at', 'Threw on their side', 'Genuinely irrelevant'],
            array_column($result['checks'], 'title'),
        );
    }

    /**
     * The attribution Plumb's terms ask for, in their words, next to their number.
     *
     * Their API is free and needs no key, and the single condition attached to it is
     * that a public display of a score carries the phrase "Powered by Plumb" with
     * Plumb linked back, preferably to the package's own page. That is a term of use
     * rather than a preference, so it is pinned here: a later rewording of this panel
     * that reads better and drops the phrase would put the site in breach silently,
     * and nothing else in the build would notice.
     */
    public function testThePanelCarriesTheAttributionPlumbAsksFor(): void
    {
        $client = static::createClient();
        $extension = $this->extension(DiscoverySource::Packagist);

        $scan = new PlumbScan($extension);
        $scan->setScannedAt(new \DateTimeImmutable('2026-10-01'));
        $scan->setSecurityScore(52.0);
        $scan->setChecks([$this->check('Provides a security policy', 'fail', 8)]);
        $this->em()->persist($scan);
        $this->em()->flush();

        $panel = $client->request('GET', '/extension/acme-thing')->filter('#security');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Powered by Plumb', $panel->text());
        self::assertSame(
            'https://plumbphp.dev/acme/thing',
            $panel->filter('.board-rule a')->attr('href'),
            'their preferred target is the package page, not the homepage',
        );
    }

    /**
     * @return array{id: string, category: string, title: string, status: string, weight: int, guide: ?string, evidence: ?string}
     */
    private function check(string $title, string $status, int $weight): array
    {
        return [
            'id' => 'security.'.strtolower(str_replace(' ', '-', $title)),
            'category' => 'security',
            'title' => $title,
            'status' => $status,
            'weight' => $weight,
            'guide' => 'https://plumbphp.dev/checks/security/example',
            'evidence' => null,
        ];
    }

    private function extension(DiscoverySource $source): Extension
    {
        $vendor = new Vendor('acme', 'acme');
        $this->em()->persist($vendor);

        $extension = new Extension($vendor, 'acme/thing', 'acme-thing', 'Thing');
        $extension->setIndexStatus(IndexStatus::Listed);
        // forceDiscoverySource, not setDiscoverySource: the entity defaults to
        // Packagist and the ordinary setter refuses to downgrade away from it,
        // because a package that has appeared on Packagist has appeared.
        $extension->forceDiscoverySource($source);
        $extension->forceLicense('MIT', LicenseStatus::Permissive, FindingSource::ComposerJson);
        $this->em()->persist($extension);
        $this->em()->flush();

        return $extension;
    }

    private function findings(): SecurityFindings
    {
        $service = static::getContainer()->get(SecurityFindings::class);
        self::assertInstanceOf(SecurityFindings::class, $service);

        return $service;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
