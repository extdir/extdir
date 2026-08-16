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

    /**
     * Maintainers paste the name GitHub's licence picker shows them instead of the
     * SPDX identifier. Found live in the corpus. Rejecting it would strip
     * redistribution rights from a package whose author stated their licence
     * perfectly clearly, just not in the expected notation.
     */
    #[DataProvider('spelledOutNameProvider')]
    public function testSpelledOutLicenseNamesResolve(string $name, string $expectedSpdx): void
    {
        self::assertSame($expectedSpdx, $this->allowlist->normalise($name));
        self::assertTrue($this->allowlist->classify($name)->isRedistributable());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function spelledOutNameProvider(): iterable
    {
        yield 'GPL v3 as GitHub spells it' => ['GNU General Public License v3.0', 'GPL-3.0-only'];
        yield 'lowercase, no punctuation' => ['gnu general public license v3 0', 'GPL-3.0-only'];
        yield 'MIT spelled out' => ['MIT License', 'MIT'];
        yield 'Apache spelled out' => ['Apache License 2.0', 'Apache-2.0'];
        yield 'MPL spelled out' => ['Mozilla Public License 2.0', 'MPL-2.0'];
    }

    /**
     * OSL-3.0 appears in the corpus and is OSI-approved. It was previously rejected,
     * which wrongly marked three real open-source extensions as non-redistributable.
     */
    public function testOslIsRecognisedAsCopyleft(): void
    {
        self::assertSame(LicenseStatus::Copyleft, $this->allowlist->classify('OSL-3.0'));
        self::assertSame('OSL-3.0', $this->allowlist->normalise('OSL-3.0'));
    }

    /**
     * `proprietary` is the value composer's own project skeleton writes, and 71
     * packages in the corpus still carry it. It must stay rejected — the whole
     * point of §4.1 is that we do not decide on an author's behalf that they meant
     * something more permissive than what they wrote.
     */
    public function testSkeletonDefaultStaysRejected(): void
    {
        self::assertSame(LicenseStatus::Rejected, $this->allowlist->classify('proprietary'));
        self::assertFalse($this->allowlist->classify('proprietary')->isRedistributable());
    }

    public function testFirstAcceptedIdentifierIgnoresUnknownEntries(): void
    {
        self::assertSame('MIT', $this->allowlist->firstAcceptedIdentifier(['proprietary', 'MIT']));
        self::assertNull($this->allowlist->firstAcceptedIdentifier(['proprietary']));
        self::assertNull($this->allowlist->firstAcceptedIdentifier(null));
    }
}
