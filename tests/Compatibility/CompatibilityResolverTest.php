<?php

declare(strict_types=1);

namespace App\Tests\Compatibility;

use App\Compatibility\CompatibilityResolver;
use App\Compatibility\ConstraintParser;
use App\Compatibility\Entity\ShopwareVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompatibilityResolver::class)]
final class CompatibilityResolverTest extends TestCase
{
    private CompatibilityResolver $resolver;
    private ConstraintParser $parser;

    protected function setUp(): void
    {
        $this->resolver = new CompatibilityResolver();
        $this->parser = new ConstraintParser();
    }

    /**
     * @param list<string> $expectedSupported
     */
    #[DataProvider('matrixProvider')]
    public function testMatrixRowMatchesTheDeclaredConstraint(
        string $constraint,
        array $expectedSupported,
    ): void {
        $parsed = $this->parser->parse(['require' => ['shopware/core' => $constraint]]);

        $result = $this->resolver->resolve($parsed, self::shopwareVersions());
        $supported = array_keys(array_filter($result));

        self::assertSame($expectedSupported, $supported, \sprintf('constraint "%s"', $constraint));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function matrixProvider(): iterable
    {
        yield 'caret covers every later minor of the major' => [
            '^6.5', ['6.5', '6.6', '6.7'],
        ];
        yield 'tilde covers exactly one minor' => [
            '~6.6.0', ['6.6'],
        ];
        yield 'explicit range excludes its upper bound' => [
            '>=6.5 <6.7', ['6.5', '6.6'],
        ];
        yield 'union of two minors' => [
            '~6.5.0 || ~6.7.0', ['6.5', '6.7'],
        ];
        yield 'wildcard covers everything' => [
            '*', ['6.4', '6.5', '6.6', '6.7'],
        ];
        yield 'floor only' => [
            '>=6.6', ['6.6', '6.7'],
        ];
    }

    /**
     * The reason compatibility is tested by interval intersection rather than by
     * matching a representative version.
     *
     * A plugin that raises its floor to a mid-minor patch, common after a core
     * bugfix, still supports that minor. Checking `>=6.6.5` against a stand-in
     * "6.6.0.0" would answer "no", and the resulting false negatives would land
     * disproportionately on the extensions that are most actively maintained.
     */
    public function testAMidMinorFloorStillCountsAsSupportingThatMinor(): void
    {
        $parsed = $this->parser->parse(['require' => ['shopware/core' => '>=6.6.5.0 <6.7.0.0']]);

        $result = $this->resolver->resolve($parsed, self::shopwareVersions());

        self::assertTrue($result['6.6'], '6.6.5+ must count as supporting 6.6');
        self::assertFalse($result['6.5']);
        self::assertFalse($result['6.7']);
    }

    /**
     * An untestable constraint must never be reported as compatible. Silence is
     * rendered as an empty cell in the UI; it is not evidence of support.
     */
    /**
     * @param array<string, mixed> $composerJson
     */
    #[DataProvider('untestableProvider')]
    public function testUntestableConstraintsNeverClaimSupport(array $composerJson): void
    {
        $parsed = $this->parser->parse($composerJson);

        $result = $this->resolver->resolve($parsed, self::shopwareVersions());

        self::assertSame([], array_keys(array_filter($result)));
        self::assertCount(4, $result, 'every minor still gets a row, all negative');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function untestableProvider(): iterable
    {
        yield 'nothing declared' => [[]];
        yield 'unreadable' => [['require' => ['shopware/core' => 'not-a-version']]];
        yield 'branch only' => [['require' => ['shopware/core' => 'dev-trunk']]];
    }

    public function testSatisfiesAgreesWithResolve(): void
    {
        $parsed = $this->parser->parse(['require' => ['shopware/core' => '^6.6']]);
        $versions = self::shopwareVersions();

        $bulk = $this->resolver->resolve($parsed, $versions);

        foreach ($versions as $version) {
            self::assertSame(
                $bulk[$version->getMajorMinor()],
                $this->resolver->satisfies($parsed, $version),
                $version->getMajorMinor(),
            );
        }
    }

    /**
     * Real dates from shopware/core on Packagist, so the fixture cannot drift into
     * fiction. Ranges are half-open, so no version is claimed by two minors.
     *
     * @return list<ShopwareVersion>
     */
    private static function shopwareVersions(): array
    {
        return [
            new ShopwareVersion('6.4', '6.4.0.0', '6.5.0.0', new \DateTimeImmutable('2021-05-03'), 0),
            new ShopwareVersion('6.5', '6.5.0.0', '6.6.0.0', new \DateTimeImmutable('2023-05-03'), 1),
            new ShopwareVersion('6.6', '6.6.0.0', '6.7.0.0', new \DateTimeImmutable('2024-03-18'), 2),
            new ShopwareVersion('6.7', '6.7.0.0', '6.8.0.0', new \DateTimeImmutable('2025-06-10'), 3),
        ];
    }
}
