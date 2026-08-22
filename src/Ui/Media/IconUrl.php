<?php

declare(strict_types=1);

namespace App\Ui\Media;

use App\Catalog\Entity\Extension;
use App\Catalog\Enum\SourceHost;
use App\Signals\Forge\ForgeUrl;

/**
 * Where an extension's icon lives on its own forge.
 *
 * We never fetch, store or serve these. The URL is handed to the browser and only
 * requested if the reader has asked for remote media, see the remote-media
 * controller and the privacy policy.
 *
 * That distinction is what keeps this permissible. Hosting the file would be
 * redistribution, and an extension icon is usually a logo: a code licence grants
 * rights to the software, not to a company's trademark. Linking is what the metadata
 * rules already prescribe for images.
 */
final class IconUrl
{
    /**
     * Used only when the crawler has not recorded a default branch yet.
     *
     * Guessing was measured against the corpus before this was written: `main` alone
     * found the icon for 23 of 40 sampled GitHub repositories, and adding `master`
     * brought it to 34. That is why the stored branch is preferred, the catalogue
     * also contains `develop`, `trunk`, `stable` and `main_65`, which no guess
     * reaches. This is the last resort for a repository crawled before the branch
     * was recorded, and the browser falls back to the monogram when it is wrong.
     */
    private const string FALLBACK_BRANCH = 'main';

    /**
     * The URL to offer a reader's browser, or null if there is nothing to offer.
     */
    public function forExtension(Extension $extension): ?string
    {
        // Unverified means no URL at all. The path is usually a convention rather
        // than a declaration, so an unchecked one is a guess, and a guess handed to
        // the browser is a third-party request that buys the reader nothing.
        return $extension->hasVerifiedIcon() ? $this->candidateFor($extension) : null;
    }

    /**
     * Where the icon would be if it exists, the URL before anyone has confirmed it.
     *
     * Only `app:ui:verify-icons` has any business calling this: it is what does the
     * confirming, so it necessarily needs the URL first. Everything that renders a
     * page uses forExtension() instead.
     */
    public function candidateFor(Extension $extension): ?string
    {
        $path = $extension->getIconPath();
        $target = ForgeUrl::split($extension->getRepositoryUrl());

        if (null === $path || '' === trim($path) || null === $target) {
            return null;
        }

        [$base, $project] = $target;

        // The real branch, crawled from the forge, rather than a guess that is wrong
        // for every repository still on master, develop or trunk.
        $branch = rawurlencode($extension->getDefaultBranch() ?? self::FALLBACK_BRANCH);

        return match ($extension->getSourceHost()) {
            // raw.githubusercontent.com directly rather than github.com/.../raw/...,
            // which answers a redirect. One request instead of two, on an image that
            // appears once per row.
            SourceHost::GitHub => \sprintf(
                'https://raw.githubusercontent.com/%s/%s/%s',
                $project,
                $branch,
                ltrim($path, '/'),
            ),
            SourceHost::GitLab => \sprintf('%s/%s/-/raw/%s/%s', $base, $project, $branch, ltrim($path, '/')),
            SourceHost::Gitea => \sprintf('%s/%s/raw/branch/%s/%s', $base, $project, $branch, ltrim($path, '/')),
            // Bitbucket and unidentified self-hosted forges. The shape is a guess, and
            // a wrong guess costs a broken image the browser hides rather than an
            // error anybody sees.
            SourceHost::Other => \sprintf('%s/%s/raw/%s/%s', $base, $project, $branch, ltrim($path, '/')),
        };
    }

    /**
     * A mark drawn from the package name, shown whether or not remote media is
     * allowed.
     *
     * This is what makes the consent gate bearable: the rows are never empty, so
     * saying no costs a reader nothing but fidelity. It is also the fallback when an
     * icon 404s, which is ordinary in a corpus where repositories get renamed.
     *
     * The hue is derived from the package name, so the same extension is the same
     * colour everywhere and two neighbours in a list are rarely the same.
     *
     * @return array{initials: string, hue: int}
     */
    public function monogramFor(Extension $extension): array
    {
        $name = $extension->getLabel();

        // The vendor prefix repeats across a vendor's whole catalogue, so initials
        // taken from the label distinguish rows that initials from the package name
        // would not.
        preg_match_all('/\b[\p{L}]/u', $name, $matches);
        $letters = $matches[0];

        $initials = mb_strtoupper(implode('', \array_slice($letters, 0, 2)));

        if ('' === $initials) {
            $initials = mb_strtoupper(mb_substr($extension->getPackageName(), 0, 2));
        }

        return [
            'initials' => $initials,
            'hue' => (int) (crc32($extension->getPackageName()) % 360),
        ];
    }

    /**
     * The host the reader's browser would contact, for naming it in the placeholder.
     *
     * Consent to "load remote media" means nothing if it does not say who from.
     */
    public function hostFor(Extension $extension): ?string
    {
        $url = $this->forExtension($extension);

        if (null === $url) {
            return null;
        }

        $host = parse_url($url, \PHP_URL_HOST);

        return \is_string($host) ? $host : null;
    }
}
