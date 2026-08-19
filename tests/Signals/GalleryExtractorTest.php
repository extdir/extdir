<?php

declare(strict_types=1);

namespace App\Tests\Signals;

use App\Signals\Gallery\GalleryExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cases here are not invented. Every rejected URL below was taken from a real
 * Shopware extension README while measuring whether this feature was worth building,
 * which is also why the badge list is as long as it is.
 */
final class GalleryExtractorTest extends TestCase
{
    private const string RAW = 'https://raw.githubusercontent.com/acme/widget/main';

    /** @var list<string> */
    private const array GITHUB_HOSTS = ['raw.githubusercontent.com', 'github.com', 'user-images.githubusercontent.com'];

    public function testStoreImagesComeFirst(): void
    {
        $readme = '![shot](docs/later.png)';
        $result = $this->extract($readme, ['src/Resources/store/images/1.png']);

        self::assertSame([
            self::RAW.'/src/Resources/store/images/1.png',
            self::RAW.'/docs/later.png',
        ], $result, 'Curated store images should outrank whatever the README happens to show.');
    }

    /**
     * A reader agreed to be fetched from the extension's forge. These hosts were all
     * found in real READMEs and none of them is that.
     */
    #[DataProvider('foreignHosts')]
    public function testImagesFromOtherHostsAreDropped(string $url): void
    {
        self::assertSame([], $this->extract(\sprintf('![screenshot](%s)', $url)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignHosts(): iterable
    {
        yield 'imgur' => ['https://i.imgur.com/XuUWCaz.png'];
        yield 'giphy' => ['https://media.giphy.com/media/VInadwfREBVz8QfIAI/giphy.gif'];
        yield 'cloudinary' => ['https://res.cloudinary.com/dtgdh7noz/image/upload/v1584603232/preview_h2cdb9.jpg'];
        yield 'vendor marketing site' => ['https://www.web-solutions-dresden.de/plugins/Sschreier/Image1.jpg'];
        yield 'vendor own server' => ['https://shopware.jeffblock.de/plugins/JblMaintenanceLogin/images/1.png'];
        yield 'plain http' => ['http://raw.githubusercontent.com/acme/widget/main/a.png'];
    }

    #[DataProvider('badges')]
    public function testBadgesAreNotScreenshots(string $url): void
    {
        self::assertSame([], $this->extract(\sprintf('[![build](%s)](https://example.com)', $url)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badges(): iterable
    {
        yield 'shields' => ['https://img.shields.io/badge/PHP-8.3-blue.png'];
        yield 'packagist' => ['https://poser.pugx.org/acme/widget/v/stable.png'];
        yield 'actions' => ['https://github.com/acme/widget/actions/workflows/ci.yml/badge.svg'];
        yield 'codecov' => ['https://codecov.io/gh/acme/widget/branch/main/graph/badge.png'];
        yield 'sponsor' => ['https://opencollective.com/acme/backers.png'];
    }

    public function testGitHubAttachmentsSurviveHavingNoFileExtension(): void
    {
        // How most maintainers add a screenshot today: drag it into the README editor.
        $readme = '![shot](https://github.com/user-attachments/assets/8ad00a67-7902-4e47-89ef-1af4b7426a50)';

        self::assertSame(
            ['https://github.com/user-attachments/assets/8ad00a67-7902-4e47-89ef-1af4b7426a50'],
            $this->extract($readme)
        );
    }

    public function testANonImageLinkOnGithubComIsNotTreatedAsAPicture(): void
    {
        // github.com is allow-listed for attachments, which must not turn every
        // github.com URL into an image.
        self::assertSame([], $this->extract('![x](https://github.com/acme/widget/blob/main/docs/guide.md)'));
    }

    #[DataProvider('relativeReferences')]
    public function testRelativeReferencesResolveAgainstTheRepository(string $reference, ?string $expected): void
    {
        $result = $this->extract(\sprintf('![shot](%s)', $reference));

        self::assertSame(null === $expected ? [] : [$expected], $result);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function relativeReferences(): iterable
    {
        yield 'plain' => ['docs/screenshot.png', self::RAW.'/docs/screenshot.png'];
        yield 'dot slash' => ['./docs/images/vipps.png', self::RAW.'/docs/images/vipps.png'];
        yield 'url encoded' => ['src%2FResources%2Fstore%2Fimages%2F2.png', self::RAW.'/src/Resources/store/images/2.png'];
        yield 'theme fragment' => ['./docs/wexo.png#gh-light-mode-only', self::RAW.'/docs/wexo.png'];
        yield 'climbing out' => ['../../../etc/passwd.png', null];
        yield 'root relative' => ['/docs/shot.png', null];
        yield 'protocol relative' => ['//evil.example/shot.png', null];
        yield 'not an image' => ['docs/guide.md', null];
        yield 'svg is a logo' => ['./docs/images/wexo.svg', null];
    }

    public function testHtmlImgTagsCount(): void
    {
        $readme = '<p align="center"><img src="docs/banner.png" width="600"></p>';

        self::assertSame([self::RAW.'/docs/banner.png'], $this->extract($readme));
    }

    public function testTheSameImageIsNotShownTwice(): void
    {
        $readme = "![a](docs/one.png)\n<img src=\"docs/one.png\">";

        self::assertCount(1, $this->extract($readme));
    }

    /**
     * One sampled README carried 24 images. That is a manual, not a preview.
     */
    public function testTooManyImagesAreCappedAtEight(): void
    {
        $readme = implode("\n", array_map(
            static fn (int $i): string => \sprintf('![shot %d](docs/%d.png)', $i, $i),
            range(1, 24),
        ));

        self::assertCount(8, $this->extract($readme));
    }

    public function testNoReadmeAndNoStoreImagesYieldsNothing(): void
    {
        self::assertSame([], $this->extract(null));
    }

    /**
     * A self-hosted GitLab serves its own raw files, so the rule has to be "the same
     * host as the repository" rather than a fixed list of the big forges.
     */
    public function testSelfHostedForgeAllowsItsOwnHost(): void
    {
        $result = (new GalleryExtractor())->extract(
            '![shot](docs/shot.png)',
            [],
            'https://git.example.de/acme/widget/-/raw/main',
            ['git.example.de'],
        );

        self::assertSame(['https://git.example.de/acme/widget/-/raw/main/docs/shot.png'], $result);
    }

    /**
     * @param list<string> $storePaths
     *
     * @return list<string>
     */
    private function extract(?string $readme, array $storePaths = []): array
    {
        return (new GalleryExtractor())->extract($readme, $storePaths, self::RAW, self::GITHUB_HOSTS);
    }
}
