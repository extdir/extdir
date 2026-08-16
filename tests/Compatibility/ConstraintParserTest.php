<?php

declare(strict_types=1);

namespace App\Tests\Compatibility;

use App\Compatibility\ConstraintParser;
use App\Compatibility\Enum\ConstraintSource;
use App\Compatibility\Enum\ConstraintTier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConstraintParser::class)]
final class ConstraintParserTest extends TestCase
{
    private ConstraintParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ConstraintParser();
    }

    #[DataProvider('tierProvider')]
    public function testConstraintsAreTieredByHowMuchTheyActuallyPromise(
        string $constraint,
        ConstraintTier $expected,
    ): void {
        $parsed = $this->parser->parse(['require' => ['shopware/core' => $constraint]]);

        self::assertSame($expected, $parsed->tier, \sprintf('constraint "%s"', $constraint));
        self::assertSame($constraint, $parsed->raw);
    }

    /**
     * @return iterable<string, array{string, ConstraintTier}>
     */
    public static function tierProvider(): iterable
    {
        // Bounded within a single major: a deliberate statement about which
        // minors were tested.
        yield 'tilde pins a minor' => ['~6.6.0', ConstraintTier::Explicit];
        yield 'explicit range' => ['>=6.5 <6.7', ConstraintTier::Explicit];
        yield 'wildcard patch' => ['6.6.*', ConstraintTier::Explicit];
        yield 'exact version' => ['6.6.10.3', ConstraintTier::Explicit];
        yield 'union of two minors' => ['~6.5.0 || ~6.6.0', ConstraintTier::Explicit];

        // Ceiling at the next major, so it silently claims every future minor.
        yield 'caret minor' => ['^6.5', ConstraintTier::Caret];
        yield 'caret patch' => ['^6.4.18', ConstraintTier::Caret];

        // No usable ceiling at all.
        yield 'star' => ['*', ConstraintTier::Wildcard];
        yield 'open lower bound' => ['>=6.0', ConstraintTier::Wildcard];
        yield 'very open' => ['>=6.4.0.0', ConstraintTier::Wildcard];
    }

    /**
     * Extensions do not agree on which package to declare against. Reading only
     * shopware/core would drop Shopware 6.1-era plugins and admin-only plugins into
     * the "unknown" bucket, which is the same as telling a merchant we have no idea
     * when in fact the maintainer told us.
     */
    #[DataProvider('sourcePackageProvider')]
    public function testCompatibilityIsReadFromAnyOfTheShopwarePackages(
        string $package,
        ConstraintSource $expected,
    ): void {
        $parsed = $this->parser->parse(['require' => [$package => '~6.6.0']]);

        self::assertSame($expected, $parsed->source);
        self::assertSame(ConstraintTier::Explicit, $parsed->tier);
    }

    /**
     * @return iterable<string, array{string, ConstraintSource}>
     */
    public static function sourcePackageProvider(): iterable
    {
        yield 'core' => ['shopware/core', ConstraintSource::Core];
        yield 'legacy monorepo' => ['shopware/platform', ConstraintSource::Platform];
        yield 'storefront only' => ['shopware/storefront', ConstraintSource::Storefront];
        yield 'administration only' => ['shopware/administration', ConstraintSource::Administration];
    }

    public function testCoreWinsWhenSeveralShopwarePackagesAreDeclared(): void
    {
        $parsed = $this->parser->parse(['require' => [
            'shopware/storefront' => '^6.4',
            'shopware/core' => '~6.6.0',
            'shopware/administration' => '^6.4',
        ]]);

        self::assertSame(ConstraintSource::Core, $parsed->source);
        self::assertSame('~6.6.0', $parsed->raw);
    }

    /**
     * @param array<string, mixed> $composerJson
     */
    #[DataProvider('noConstraintProvider')]
    public function testMissingDeclarationsYieldNoTestableClaim(array $composerJson): void
    {
        $parsed = $this->parser->parse($composerJson);

        self::assertSame(ConstraintSource::None, $parsed->source);
        self::assertSame(ConstraintTier::Absent, $parsed->tier);
        self::assertFalse($parsed->isTestable());
        self::assertNull($parsed->raw);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function noConstraintProvider(): iterable
    {
        yield 'no require block' => [[]];
        yield 'require is not an array' => [['require' => 'php']];
        yield 'no shopware package' => [['require' => ['php' => '>=8.2', 'symfony/console' => '^7.0']]];
        yield 'empty constraint string' => [['require' => ['shopware/core' => '']]];
        yield 'whitespace constraint' => [['require' => ['shopware/core' => '   ']]];
    }

    /**
     * A garbled constraint is not the same as no constraint. We keep the raw text
     * so the detail page can show what the maintainer actually wrote, but refuse to
     * derive claims from something we could not read.
     */
    public function testUnreadableConstraintsAreKeptButNotTested(): void
    {
        $parsed = $this->parser->parse(['require' => ['shopware/core' => 'not-a-version']]);

        self::assertSame('not-a-version', $parsed->raw);
        self::assertSame(ConstraintSource::Core, $parsed->source);
        self::assertSame(ConstraintTier::Absent, $parsed->tier);
        self::assertFalse($parsed->isTestable());
    }

    /**
     * A branch-only constraint parses cleanly but matches no numeric version, so
     * there is nothing to intersect a Shopware range with.
     */
    public function testBranchOnlyConstraintsAreNotTestable(): void
    {
        $parsed = $this->parser->parse(['require' => ['shopware/core' => 'dev-trunk']]);

        self::assertFalse($parsed->isTestable());
        self::assertSame('dev-trunk', $parsed->raw);
    }

    public function testConstraintsAreTrimmedBeforeParsing(): void
    {
        $parsed = $this->parser->parse(['require' => ['shopware/core' => '  ~6.6.0  ']]);

        self::assertSame('~6.6.0', $parsed->raw);
        self::assertSame(ConstraintTier::Explicit, $parsed->tier);
    }
}
