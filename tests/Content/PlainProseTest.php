<?php

declare(strict_types=1);

namespace App\Tests\Content;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The writing stays plain, and stays short.
 *
 * Both halves of this were a real complaint about the site: the punctuation announced
 * that a machine wrote the copy, and there was far too much of it. Neither is the kind
 * of thing anyone notices creeping back, so both are checked here rather than
 * remembered.
 *
 * It reads source files, never rendered HTML. Indexed extension labels and descriptions
 * are written by the vendors who publish them, and a few contain em dashes; rewriting
 * somebody else's package description is not something a directory may do, so the
 * catalogue must never be measured by this rule.
 */
final class PlainProseTest extends TestCase
{
    /**
     * Characters that mark text as machine-set rather than typed.
     *
     * Deliberately a short list. Most of the non-ASCII on this site is doing real work:
     * German umlauts on the legal pages, the section sign in legal citations, the middot
     * separating metadata, arrows on outbound links, the angle quotes on carousel
     * buttons, and the ring that marks an open-ended version constraint. None of those
     * is a writing tic, and banning them would break the pages instead of improving
     * them.
     *
     * @var array<string, string>
     */
    private const array BANNED = [
        '—' => 'em dash',
        '–' => 'en dash',
        '‘' => 'left single quote',
        '’' => 'right single quote',
        '“' => 'left double quote',
        '”' => 'right double quote',
        '„' => 'German low quote',
        '…' => 'ellipsis',
        "\u{00A0}" => 'non-breaking space',
    ];

    /**
     * Third-party code we neither wrote nor may rewrite, a Symfony-generated file, and
     * this test, which has to name the banned characters in order to ban them.
     *
     * @var list<string>
     */
    private const array SKIP = [
        'assets/vendor/',
        'config/reference.php',
        'tests/Content/PlainProseTest.php',
    ];

    /**
     * Extensions worth reading.
     *
     * Fonts and images are bytes; a woff2 that happens to contain 0xE2 0x80 0x94 is not
     * an em dash and cannot be rewritten into one.
     *
     * @var list<string>
     */
    private const array TEXT = [
        'twig', 'php', 'js', 'css', 'yaml', 'yml', 'json', 'md', 'txt', 'sh', 'neon', 'xml',
    ];

    /**
     * Word ceiling for one template.
     *
     * A page over this has started explaining itself again. The heaviest non-legal page
     * currently holds 197 words of prose, so this is a real ceiling rather than a number
     * chosen to pass.
     *
     * Legal pages are exempt rather than generous: a privacy policy has required
     * content, and shortening one past adequacy to satisfy a test would be the wrong
     * trade.
     */
    private const int MAX_WORDS = 220;

    /** @var list<string> */
    private const array LEGAL = [
        'templates/legal/privacy.html.twig',
        'templates/legal/imprint.html.twig',
        'templates/legal/terms.html.twig',
        'templates/legal/takedown.html.twig',
    ];

    #[DataProvider('sourceFiles')]
    public function testNoMachineSetPunctuation(string $path): void
    {
        $lines = explode("\n", (string) file_get_contents(self::root().'/'.$path));
        $found = [];

        foreach ($lines as $number => $line) {
            foreach (self::BANNED as $character => $name) {
                if (str_contains($line, $character)) {
                    $found[] = \sprintf('%s:%d %s', $path, $number + 1, $name);
                }
            }
        }

        self::assertSame([], $found, implode("\n", $found));
    }

    #[DataProvider('templates')]
    public function testATemplateDoesNotGrowBackIntoAnEssay(string $path): void
    {
        if (\in_array($path, self::LEGAL, true)) {
            self::markTestSkipped('legal pages carry required content and are exempt');
        }

        $words = self::wordCount((string) file_get_contents(self::root().'/'.$path));

        self::assertLessThanOrEqual(
            self::MAX_WORDS,
            $words,
            \sprintf(
                '%s holds %d words. Over %d, a page has usually started explaining itself twice. '
                .'Cut it, or move the explanation to the one page that owns the subject and link there.',
                $path,
                $words,
                self::MAX_WORDS,
            ),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sourceFiles(): iterable
    {
        foreach (self::walk(['templates', 'src', 'tests', 'config', 'migrations', 'deploy', 'assets']) as $path) {
            yield $path => [$path];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function templates(): iterable
    {
        foreach (self::walk(['templates']) as $path) {
            if (str_ends_with($path, '.twig')) {
                yield $path => [$path];
            }
        }
    }

    /**
     * Words of prose, meaning what is inside a paragraph or a list item.
     *
     * Headings, table headers, button labels and command snippets are not prose and are
     * not what this rule is about. A spec sheet legitimately carries a lot of short
     * labels; counting those would push a page to fail for being useful.
     *
     * Twig expressions go too, because they render catalogue data rather than writing,
     * and a page listing twenty-four extensions would otherwise be measured on somebody
     * else's package names.
     */
    private static function wordCount(string $source): int
    {
        $source = (string) preg_replace('/\{#.*?#\}/s', ' ', $source);

        preg_match_all('/<(p|li)\b[^>]*>(.*?)<\/\1>/s', $source, $matches);
        $prose = implode(' ', $matches[2]);

        foreach (['/\{%.*?%\}/s', '/\{\{.*?\}\}/s', '/<[^>]+>/'] as $pattern) {
            $prose = (string) preg_replace($pattern, ' ', $prose);
        }

        // preg_match_all returns false on a malformed subject, which would silently
        // read as a zero-word page and pass.
        $count = preg_match_all("/[A-Za-zÄÖÜäöüß][A-Za-zÄÖÜäöüß'-]+/", $prose);

        self::assertNotFalse($count, 'the prose could not be scanned');

        return $count;
    }

    /**
     * @param list<string> $roots
     *
     * @return list<string>
     */
    private static function walk(array $roots): array
    {
        $paths = [];

        foreach ($roots as $root) {
            $directory = new \RecursiveDirectoryIterator(
                self::root().'/'.$root,
                \FilesystemIterator::SKIP_DOTS,
            );

            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator($directory) as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $relative = str_replace(self::root().'/', '', $file->getPathname());

                foreach (self::SKIP as $skip) {
                    if (str_starts_with($relative, $skip)) {
                        continue 2;
                    }
                }

                // The crontab carries no extension and is still prose worth checking.
                if (!\in_array(strtolower($file->getExtension()), self::TEXT, true)
                    && 'crontab' !== $file->getFilename()) {
                    continue;
                }

                $paths[] = $relative;
            }
        }

        sort($paths);

        return $paths;
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}
