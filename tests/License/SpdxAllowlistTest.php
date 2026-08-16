<?php

declare(strict_types=1);

namespace App\Tests\License;

use App\License\Enum\LicenseStatus;
use App\License\SpdxAllowlist;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpdxAllowlist::class)]
final class SpdxAllowlistTest extends TestCase
{
    private SpdxAllowlist $allowlist;

    protected function setUp(): void
    {
        $this->allowlist = new SpdxAllowlist();
    }

    /**
     * The rule the whole project rests on: anything we cannot positively identify
     * is not redistributable. A regression here would not throw an error, it would
     * quietly start mirroring code nobody licensed us to mirror.
     *
     * @param string|list<string>|null $declared
     */
    #[DataProvider('nonRedistributableProvider')]
    public function testUnidentifiedLicensesAreNeverRedistributable(string|array|null $declared): void
    {
        $status = $this->allowlist->classifyDeclared($declared);

        self::assertFalse(
            $status->isRedistributable(),
            \sprintf('%s must not be treated as redistributable', var_export($declared, true)),
        );
    }

    /**
     * @return iterable<string, array{string|list<string>|null}>
     */
    public static function nonRedistributableProvider(): iterable
    {
        yield 'no license field at all' => [null];
        yield 'empty string' => [''];
        yield 'whitespace only' => ['   '];
        yield 'empty array' => [[]];
        yield 'explicitly proprietary' => ['proprietary'];
        yield 'commercial' => ['LicenseRef-Commercial'];
        yield 'invented identifier' => ['Shopware-Commercial-1.0'];
        yield 'array of unknowns' => [['proprietary', 'all-rights-reserved']];
    }

    #[DataProvider('permissiveProvider')]
    public function testPermissiveLicensesAreClassified(string $declared, string $expectedSpdx): void
    {
        self::assertSame(LicenseStatus::Permissive, $this->allowlist->classify($declared));
        self::assertSame($expectedSpdx, $this->allowlist->normalise($declared));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function permissiveProvider(): iterable
    {
        yield 'MIT' => ['MIT', 'MIT'];
        yield 'lowercase mit' => ['mit', 'MIT'];
        yield 'padded' => ['  MIT  ', 'MIT'];
        yield 'Apache' => ['Apache-2.0', 'Apache-2.0'];
        yield 'Apache shorthand' => ['Apache2', 'Apache-2.0'];
        yield 'BSD 3 clause' => ['BSD-3-Clause', 'BSD-3-Clause'];
    }

    /**
     * Copyleft is redistributable but must never be labelled as MIT-style open
     * source: the obligations it places on a merchant shipping a modified shop are
     * different, and conflating them is how a directory misleads its users.
     */
    #[DataProvider('copyleftProvider')]
    public function testCopyleftIsSeparateFromPermissive(string $declared, string $expectedSpdx): void
    {
        $status = $this->allowlist->classify($declared);

        self::assertSame(LicenseStatus::Copyleft, $status);
        self::assertTrue($status->isRedistributable());
        self::assertNotSame(LicenseStatus::Permissive, $status);
        self::assertSame($expectedSpdx, $this->allowlist->normalise($declared));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function copyleftProvider(): iterable
    {
        yield 'GPL-3.0-only' => ['GPL-3.0-only', 'GPL-3.0-only'];
        yield 'AGPL' => ['AGPL-3.0-or-later', 'AGPL-3.0-or-later'];
        yield 'MPL' => ['MPL-2.0', 'MPL-2.0'];
    }

    /**
     * Shopware plugins carry a lot of Shopware 5 heritage, and those composer.json
     * files predate the SPDX deprecations. Refusing "GPL-3.0" because the modern
     * spelling is "GPL-3.0-only" would wrongly strip rights from perfectly licensed
     * extensions.
     */
    #[DataProvider('deprecatedAliasProvider')]
    public function testDeprecatedIdentifiersStillResolve(string $legacy, string $canonical): void
    {
        self::assertSame($canonical, $this->allowlist->normalise($legacy));
        self::assertTrue($this->allowlist->classify($legacy)->isRedistributable());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function deprecatedAliasProvider(): iterable
    {
        yield 'GPL-3.0' => ['GPL-3.0', 'GPL-3.0-only'];
        yield 'GPL-2.0+' => ['GPL-2.0+', 'GPL-2.0-or-later'];
        yield 'LGPL-3.0' => ['LGPL-3.0', 'LGPL-3.0-only'];
        yield 'AGPL-3.0' => ['AGPL-3.0', 'AGPL-3.0-only'];
    }

    /**
     * composer.json allows an array of licenses, meaning the user may pick any one
     * of them. Taking the most permissive option is the correct reading of a
     * disjunction, not a convenience.
     */
    public function testDisjunctionTakesTheMostPermissiveOption(): void
    {
        self::assertSame(
            LicenseStatus::Permissive,
            $this->allowlist->classifyDeclared(['GPL-3.0-only', 'MIT']),
        );

        self::assertSame(
            LicenseStatus::Copyleft,
            $this->allowlist->classifyDeclared(['GPL-3.0-only', 'AGPL-3.0-only']),
        );
    }

    /**
     * "Said nothing" and "said something we reject" are different moderation cases,
     * so they stay distinguishable even though both block redistribution.
     */
    public function testSilenceIsDistinguishedFromRejection(): void
    {
        self::assertSame(LicenseStatus::Unknown, $this->allowlist->classify(null));
        self::assertSame(LicenseStatus::Rejected, $this->allowlist->classify('proprietary'));
    }

    public function testFirstAcceptedIdentifierIgnoresUnknownEntries(): void
    {
        self::assertSame('MIT', $this->allowlist->firstAcceptedIdentifier(['proprietary', 'MIT']));
        self::assertNull($this->allowlist->firstAcceptedIdentifier(['proprietary']));
        self::assertNull($this->allowlist->firstAcceptedIdentifier(null));
    }
}
