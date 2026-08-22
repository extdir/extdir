<?php

declare(strict_types=1);

namespace App\Tests\Metadata;

use App\Metadata\ComposerMetadataExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComposerMetadataExtractor::class)]
final class ComposerMetadataExtractorTest extends TestCase
{
    private ComposerMetadataExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new ComposerMetadataExtractor();
    }

    /**
     * Shaped after a real package (areanet/canonicalvariants), including its
     * non-conventional icon path.
     */
    public function testExtractsAFullyPopulatedShopwarePlugin(): void
    {
        $meta = $this->extractor->extract([
            'source' => ['type' => 'git', 'url' => 'https://github.com/AREA-NET/shopware6-canonical.git'],
            'license' => ['MIT'],
            'keywords' => ['shopware', 'seo'],
            'extra' => [
                'label' => [
                    'de-DE' => 'Varianten mit Canonical Tag auf Hauptprodukt',
                    'en-GB' => 'Variants with canonical tag',
                ],
                'plugin-icon' => 'src/Resources/plugin.png',
                'supportLink' => [
                    'de-DE' => 'https://github.com/AREA-NET/shopware6-canonical/issues',
                    'en-GB' => 'https://github.com/AREA-NET/shopware6-canonical/issues',
                ],
                'manufacturerLink' => [
                    'de-DE' => 'https://www.area-net.de',
                    'en-GB' => 'https://www.area-net.de',
                ],
                'shopware-plugin-class' => 'AreanetCanonicalVariants\\AreanetCanonicalVariants',
            ],
        ], 'areanet/canonicalvariants');

        self::assertSame('Variants with canonical tag', $meta->label);
        self::assertCount(2, $meta->labels);
        self::assertSame('src/Resources/plugin.png', $meta->pluginIcon);
        self::assertSame('https://www.area-net.de', $meta->manufacturerLink);
        self::assertSame(['MIT'], $meta->license);
        self::assertSame(['shopware', 'seo'], $meta->keywords);

        // The install snippet depends on this being the class basename.
        self::assertSame('AreanetCanonicalVariants', $meta->technicalName());

        // .git stripped so the URL is browsable and comparable across sources.
        self::assertSame('https://github.com/AREA-NET/shopware6-canonical', $meta->repositoryUrl);
    }

    /**
     * Roughly a third of sampled packages omit supportLink, many omit description,
     * and some omit the extra block entirely. None of that may stop ingestion, an
     * extractor that throws on the first non-conforming package indexes nothing.
     */
    public function testAnEmptyComposerJsonStillYieldsUsableMetadata(): void
    {
        $meta = $this->extractor->extract([], 'acme/plugin');

        self::assertSame('acme/plugin', $meta->label, 'falls back to the package name');
        self::assertNull($meta->description);
        self::assertNull($meta->supportLink);
        self::assertNull($meta->manufacturerLink);
        self::assertNull($meta->pluginClass);
        self::assertNull($meta->technicalName());
        self::assertNull($meta->license);
        self::assertNull($meta->repositoryUrl);
        self::assertSame([], $meta->labels);
        self::assertSame('src/Resources/config/plugin.png', $meta->pluginIcon);
    }

    /**
     * The documented shape is a locale map, but bare strings occur in the wild.
     */
    public function testBareStringLabelsAreAccepted(): void
    {
        $meta = $this->extractor->extract([
            'extra' => ['label' => 'My Plugin', 'description' => 'Does a thing'],
        ], 'acme/plugin');

        self::assertSame('My Plugin', $meta->label);
        self::assertSame(['en-GB' => 'My Plugin'], $meta->labels);
        self::assertSame('Does a thing', $meta->description);
    }

    /**
     * The Shopware ecosystem is heavily German and plenty of plugins ship German
     * metadata only. Falling back to it beats rendering an empty card.
     */
    public function testGermanOnlyMetadataIsUsedRatherThanNothing(): void
    {
        $meta = $this->extractor->extract([
            'extra' => ['label' => ['de-DE' => 'Versandkosten-Rechner']],
        ], 'acme/plugin');

        self::assertSame('Versandkosten-Rechner', $meta->label);
    }

    /**
     * And when neither English nor German is present, show whatever the maintainer
     * did write.
     */
    public function testAnUnanticipatedLocaleIsStillPreferredOverNothing(): void
    {
        $meta = $this->extractor->extract([
            'extra' => ['label' => ['nl-NL' => 'Verzendkosten']],
        ], 'acme/plugin');

        self::assertSame('Verzendkosten', $meta->label);
    }

    public function testEnglishWinsOverGermanWhenBothExist(): void
    {
        $meta = $this->extractor->extract([
            'extra' => ['label' => ['de-DE' => 'Deutsch', 'en-GB' => 'English']],
        ], 'acme/plugin');

        self::assertSame('English', $meta->label);
    }

    /**
     * Falls back to composer.json's own description when the Shopware extra block
     * has none, common in packages that predate the convention.
     */
    public function testTopLevelDescriptionIsUsedWhenExtraHasNone(): void
    {
        $meta = $this->extractor->extract([
            'description' => 'A Shopware 6 plugin',
            'extra' => ['label' => ['en-GB' => 'Thing']],
        ], 'acme/plugin');

        self::assertSame('A Shopware 6 plugin', $meta->description);
    }

    #[DataProvider('repositoryUrlProvider')]
    public function testRepositoryUrlsAreNormalised(mixed $source, ?string $expected): void
    {
        $meta = $this->extractor->extract(['source' => $source], 'acme/plugin');

        self::assertSame($expected, $meta->repositoryUrl);
    }

    /**
     * @return iterable<string, array{mixed, string|null}>
     */
    public static function repositoryUrlProvider(): iterable
    {
        yield 'https with .git' => [
            ['url' => 'https://github.com/acme/plugin.git'],
            'https://github.com/acme/plugin',
        ];
        yield 'https without .git' => [
            ['url' => 'https://github.com/acme/plugin'],
            'https://github.com/acme/plugin',
        ];
        yield 'ssh form becomes browsable' => [
            ['url' => 'git@github.com:acme/plugin.git'],
            'https://github.com/acme/plugin',
        ];
        yield 'self-hosted gitlab' => [
            ['url' => 'https://git.agency.de/shopware/plugin.git'],
            'https://git.agency.de/shopware/plugin',
        ];
        yield 'missing source' => [null, null];
        yield 'source without url' => [['type' => 'git'], null];
        yield 'empty url' => [['url' => '   '], null];
    }

    /**
     * Blank strings inside a locale map are dropped rather than displayed as an
     * empty label.
     */
    public function testBlankTranslationsAreDiscarded(): void
    {
        $meta = $this->extractor->extract([
            'extra' => ['label' => ['en-GB' => '   ', 'de-DE' => 'Etwas']],
        ], 'acme/plugin');

        self::assertSame(['de-DE' => 'Etwas'], $meta->labels);
        self::assertSame('Etwas', $meta->label);
    }

    public function testSingleStringLicenseIsPreserved(): void
    {
        $meta = $this->extractor->extract(['license' => 'MIT'], 'acme/plugin');

        self::assertSame('MIT', $meta->license);
    }

    public function testEmptyLicenseArrayBecomesNull(): void
    {
        self::assertNull($this->extractor->extract(['license' => []], 'acme/plugin')->license);
        self::assertNull($this->extractor->extract(['license' => ''], 'acme/plugin')->license);
    }
}
