<?php

declare(strict_types=1);

namespace App\Signals\Gallery;

/**
 * Finds the images that show what an extension actually looks like.
 *
 * Two sources, in order of trustworthiness:
 *
 * 1. `src/Resources/store/images/` — the images the maintainer prepared for the
 *    Shopware Store listing. Curated, in a known place, and unambiguously screenshots.
 *    Rare, though: of 28 randomly sampled repositories, none had the directory at all.
 * 2. The README. Far more common — 21 of 60 sampled repositories carry at least one
 *    non-badge image — and correspondingly messier, since a README mixes screenshots
 *    with build badges, sponsor banners and vendor logos.
 *
 * **Every result must be served by the extension's own forge.** A reader who allowed
 * "extension icons from their forges" did not agree to be fetched from imgur, giphy,
 * cloudinary or a vendor's own marketing server, and the sampled READMEs link to all
 * four. Those are dropped rather than quietly widening what the consent covers. It
 * costs some coverage and keeps the privacy policy true, which is the right trade.
 *
 * Nothing here is downloaded, resized or stored. The output is a list of URLs handed
 * to the browser only after the reader opts in, exactly like the icons.
 */
final class GalleryExtractor
{
    /**
     * More than this and it stops being a gallery. One sampled README carried 24
     * images, which is a manual, not a preview.
     */
    private const int MAX_IMAGES = 8;

    private const array IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    /**
     * Status badges, sponsor buttons and CI shields, which are images in a README and
     * never a screenshot. Matched against the whole URL because the giveaway is
     * sometimes the host (`shields.io`) and sometimes the path (`/actions/workflows/`).
     */
    private const string BADGE_PATTERN = '~(shields\.io|badgen\.net|badge|travis-ci|circleci|codecov|coveralls|scrutinizer|poser\.pugx|packagist\.org|/actions/workflow|/workflows/|opencollective|patreon|paypal|buymeacoffee|gitpod|styleci|sonarcloud|snyk\.io)~i';

    /**
     * @param string|null  $readme       the README's raw markdown, or null if absent
     * @param list<string> $storePaths   repository-relative paths under src/Resources/store/images
     * @param string       $rawBase      where a repository-relative path resolves, e.g.
     *                                   https://raw.githubusercontent.com/acme/widget/main
     * @param list<string> $allowedHosts hosts this extension's forge serves files from
     *
     * @return list<string>
     */
    public function extract(?string $readme, array $storePaths, string $rawBase, array $allowedHosts): array
    {
        $urls = [];

        // Store images first: they were prepared as screenshots, so they belong at the
        // front where a reader looks.
        foreach ($storePaths as $path) {
            if (self::looksLikeImage($path)) {
                $urls[] = rtrim($rawBase, '/').'/'.ltrim($path, '/');
            }
        }

        foreach (self::imagesIn($readme ?? '') as $reference) {
            $resolved = self::resolve($reference, $rawBase);

            if (null !== $resolved) {
                $urls[] = $resolved;
            }
        }

        $kept = [];

        foreach ($urls as $url) {
            if (\in_array($url, $kept, true)) {
                continue;
            }

            if (!self::isAllowed($url, $allowedHosts)) {
                continue;
            }

            $kept[] = $url;

            if (\count($kept) >= self::MAX_IMAGES) {
                break;
            }
        }

        return $kept;
    }

    /**
     * Image references in markdown and in the inline HTML that markdown permits.
     *
     * @return list<string>
     */
    private static function imagesIn(string $markdown): array
    {
        $found = [];

        // ![alt](url "title") — the title and any <> wrapper are stripped by the
        // character class, and alt text may itself contain brackets.
        if (preg_match_all('~!\[[^\]]*\]\(\s*<?([^)\s>]+)~', $markdown, $matches)) {
            $found = array_merge($found, $matches[1]);
        }

        if (preg_match_all('~<img[^>]+src\s*=\s*["\']([^"\']+)["\']~i', $markdown, $matches)) {
            $found = array_merge($found, $matches[1]);
        }

        return array_values(array_filter($found, static fn (string $url): bool => !preg_match(self::BADGE_PATTERN, $url)));
    }

    /**
     * Turns a README reference into an absolute URL, or null if it is not an image
     * we can place.
     */
    private static function resolve(string $reference, string $rawBase): ?string
    {
        // `#gh-dark-mode-only` and friends select between two variants of the same
        // picture; the fragment means nothing to a plain <img>.
        $reference = trim(explode('#', $reference, 2)[0]);

        if ('' === $reference) {
            return null;
        }

        // A README written for GitHub's own renderer may URL-encode the path.
        if (str_contains($reference, '%2F')) {
            $reference = rawurldecode($reference);
        }

        if (preg_match('~^https?://~i', $reference)) {
            return self::looksLikeImage($reference) || self::isAttachment($reference) ? $reference : null;
        }

        // Protocol-relative and root-relative references name a host we cannot know,
        // or a path outside the repository. Neither resolves to something we can trust.
        if (str_starts_with($reference, '//') || str_starts_with($reference, '/')) {
            return null;
        }

        if (!self::looksLikeImage($reference)) {
            return null;
        }

        $path = preg_replace('~^\./~', '', $reference) ?? $reference;

        // `../` would climb out of the repository root.
        if (str_contains($path, '..')) {
            return null;
        }

        return rtrim($rawBase, '/').'/'.ltrim($path, '/');
    }

    private static function looksLikeImage(string $path): bool
    {
        $withoutQuery = parse_url($path, \PHP_URL_PATH);
        $extension = strtolower(pathinfo(\is_string($withoutQuery) ? $withoutQuery : $path, \PATHINFO_EXTENSION));

        // SVG is deliberately absent. In a Shopware README it is almost always a logo
        // or a badge that slipped past the pattern above, never a screenshot.
        return \in_array($extension, self::IMAGE_EXTENSIONS, true);
    }

    /**
     * GitHub's own upload hosts serve images under opaque, extensionless URLs, so the
     * filename test above would reject every drag-and-dropped screenshot — which is
     * how most maintainers add one today.
     */
    private static function isAttachment(string $url): bool
    {
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));
        $path = (string) parse_url($url, \PHP_URL_PATH);

        return ('github.com' === $host && str_starts_with($path, '/user-attachments/assets/'))
            || 'user-images.githubusercontent.com' === $host;
    }

    /**
     * @param list<string> $allowedHosts
     */
    private static function isAllowed(string $url, array $allowedHosts): bool
    {
        if (!str_starts_with(strtolower($url), 'https://')) {
            return false;
        }

        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));

        return \in_array($host, array_map('strtolower', $allowedHosts), true);
    }
}
