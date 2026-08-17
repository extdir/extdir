<?php

declare(strict_types=1);

namespace App\Tests\License;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Catalog\Enum\IndexStatus;
use App\License\Entity\LicenseFinding;
use App\License\Enum\FindingSource;
use App\License\Enum\LicenseStatus;
use App\License\LicenseResolver;
use App\License\SpdxAllowlist;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LicenseResolver::class)]
final class LicenseResolverTest extends TestCase
{
    private LicenseResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new LicenseResolver(
            new SpdxAllowlist(),
            $this->createStub(EntityManagerInterface::class),
        );
    }

    /**
     * The single most consequential rule in this class.
     *
     * Three packages in the corpus declare AGPL-3.0 in composer.json while
     * shipping an MIT licence file. Believing the file would publish them as MIT —
     * and unlike most mistakes here, that one has a victim who is not us: a
     * merchant who deploys a modified copy in a hosted shop believing they owe
     * nothing. §4.1 requires copyleft to be classified separately rather than
     * lumped under an "MIT/open source" label.
     */
    public function testCopyleftIsNeverDowngradedToPermissive(): void
    {
        $extension = $this->extension();
        $this->addFinding($extension, 'AGPL-3.0-or-later', LicenseStatus::Copyleft, FindingSource::ComposerJson);

        $this->resolver->applyDetectorResult($extension, 'MIT', 'licensee');

        self::assertSame(LicenseStatus::Copyleft, $extension->getLicenseStatus());
        self::assertSame('AGPL-3.0-or-later', $extension->getLicenseSpdx());
    }

    /**
     * The case that motivated reading licence files at all: `"license":
     * "proprietary"` is what `composer create-project` writes, and 13 indexed
     * repositories carry it while shipping a real open-source licence file. Taking
     * the manifest as final denied redistribution to authors who had granted it.
     */
    public function testALicenceFileRescuesAStaleProprietaryDeclaration(): void
    {
        $extension = $this->extension();
        $extension->applyLicense(null, LicenseStatus::Rejected);
        $extension->setIndexStatus(IndexStatus::IndexOnly);
        $this->addFinding($extension, null, LicenseStatus::Rejected, FindingSource::ComposerJson);

        $this->resolver->applyDetectorResult($extension, 'MIT', 'licensee');

        self::assertSame(LicenseStatus::Permissive, $extension->getLicenseStatus());
        self::assertSame('MIT', $extension->getLicenseSpdx());
        self::assertSame(IndexStatus::Listed, $extension->getIndexStatus());
        self::assertTrue($extension->getLicenseStatus()->isRedistributable());
    }

    /**
     * Copyleft found in the file where the manifest claimed permissive must also
     * take effect — the rule is "the stricter of the two", not "the manifest is
     * always wrong".
     */
    public function testCopyleftInTheFileOverridesAPermissiveManifest(): void
    {
        $extension = $this->extension();
        $this->addFinding($extension, 'MIT', LicenseStatus::Permissive, FindingSource::ComposerJson);

        $this->resolver->applyDetectorResult($extension, 'GPL-3.0', 'licensee');

        self::assertSame(LicenseStatus::Copyleft, $extension->getLicenseStatus());
        self::assertSame('GPL-3.0-only', $extension->getLicenseSpdx());
    }

    /**
     * GitHub answers NOASSERTION when it finds a licence file it cannot identify —
     * usually a modified or custom licence. That is evidence of a file, not of a
     * grant we can act on.
     */
    #[DataProvider('unusableDetectionProvider')]
    public function testUnusableDetectionsChangeNothing(?string $detected): void
    {
        $extension = $this->extension();
        $extension->applyLicense(null, LicenseStatus::Unknown);
        $this->addFinding($extension, null, LicenseStatus::Unknown, FindingSource::ComposerJson);

        $this->resolver->applyDetectorResult($extension, $detected, 'licensee');

        self::assertSame(LicenseStatus::Unknown, $extension->getLicenseStatus());
        self::assertFalse($extension->getLicenseStatus()->isRedistributable());
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function unusableDetectionProvider(): iterable
    {
        yield 'nothing detected' => [null];
        yield 'file present but unidentifiable' => ['NOASSERTION'];
        yield 'detected but not open source' => ['LicenseRef-Commercial'];
    }

    /**
     * A takedown outranks every automated conclusion, including a licence file
     * that says the extension is perfectly redistributable.
     */
    public function testADelistedExtensionIsNotRelisted(): void
    {
        $extension = $this->extension();
        $extension->delist('Requested by the maintainer.');
        $this->addFinding($extension, null, LicenseStatus::Rejected, FindingSource::ComposerJson);

        $this->resolver->applyDetectorResult($extension, 'MIT', 'licensee');

        self::assertSame(IndexStatus::Delisted, $extension->getIndexStatus());
    }

    /**
     * Reconciliation reads the accumulated evidence rather than whichever source
     * wrote last, so the outcome does not depend on crawl order.
     */
    public function testTheOutcomeDoesNotDependOnTheOrderEvidenceArrived(): void
    {
        $manifestFirst = $this->extension();
        $this->addFinding($manifestFirst, 'AGPL-3.0-or-later', LicenseStatus::Copyleft, FindingSource::ComposerJson);
        $this->addFinding($manifestFirst, 'MIT', LicenseStatus::Permissive, FindingSource::Detector);
        $this->resolver->reconcile($manifestFirst);

        $detectorFirst = $this->extension();
        $this->addFinding($detectorFirst, 'MIT', LicenseStatus::Permissive, FindingSource::Detector);
        $this->addFinding($detectorFirst, 'AGPL-3.0-or-later', LicenseStatus::Copyleft, FindingSource::ComposerJson);
        $this->resolver->reconcile($detectorFirst);

        self::assertSame($manifestFirst->getLicenseStatus(), $detectorFirst->getLicenseStatus());
        self::assertSame(LicenseStatus::Copyleft, $manifestFirst->getLicenseStatus());
    }

    private function extension(): Extension
    {
        return new Extension(new Vendor('acme', 'acme'), 'acme/plugin', 'acme-plugin', 'Plugin');
    }

    private function addFinding(
        Extension $extension,
        ?string $spdx,
        LicenseStatus $status,
        FindingSource $source,
    ): void {
        $extension->getLicenseFindings()->add(new LicenseFinding($extension, $status, $source, $spdx));
    }
}
