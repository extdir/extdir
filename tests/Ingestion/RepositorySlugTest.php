<?php

declare(strict_types=1);

namespace App\Tests\Ingestion;

use App\Catalog\Repository\ExtensionRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Normalising a repository URL down to "owner/repo".
 *
 * This exists to skip work, never to decide correctness: an unrecognised shape costs
 * one redundant fetch that the package-name dedupe then catches. So the bar is that it
 * must never produce a *wrong* slug, a false match would silently skip a real
 * extension, which is the one failure that would not show up as a slower run.
 */
final class RepositorySlugTest extends TestCase
{
    #[DataProvider('urls')]
    public function testSlugsAreNormalised(?string $url, ?string $expected): void
    {
        self::assertSame($expected, ExtensionRepository::slugFromUrl($url));
    }

    /**
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function urls(): iterable
    {
        yield 'plain' => ['https://github.com/acme/widget', 'acme/widget'];
        yield 'trailing slash' => ['https://github.com/acme/widget/', 'acme/widget'];
        yield 'dot git' => ['https://github.com/acme/widget.git', 'acme/widget'];
        yield 'mixed case' => ['https://github.com/FriendsOfShopware/FroshTools', 'friendsofshopware/froshtools'];
        yield 'whitespace' => ['  https://github.com/acme/widget  ', 'acme/widget'];
        yield 'other forge' => ['https://codeberg.org/acme/widget', 'acme/widget'];

        // A subgroup path is not a two-segment repository root. Truncating it to the
        // first two segments would collide every project in that group into one slug.
        yield 'gitlab subgroup' => ['https://gitlab.com/acme/group/widget', null];

        yield 'no path' => ['https://github.com', null];
        yield 'one segment' => ['https://github.com/acme', null];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
        yield 'whitespace only' => ['   ', null];
    }

    /**
     * Two spellings of the same repository must collide, or the skip never fires.
     */
    public function testEquivalentUrlsProduceTheSameSlug(): void
    {
        $slugs = array_map(
            static fn (string $url): ?string => ExtensionRepository::slugFromUrl($url),
            [
                'https://github.com/Acme/Widget',
                'https://github.com/acme/widget.git',
                'https://github.com/acme/widget/',
            ],
        );

        self::assertSame(['acme/widget'], array_values(array_unique($slugs)));
    }
}
