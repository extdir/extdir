<?php

declare(strict_types=1);

namespace App\Metadata;

/**
 * Turns a raw composer.json array into normalised metadata.
 *
 * Shopware's plugin conventions are documented, but the corpus does not follow them
 * uniformly. Sampling forty live packages shows `extra.label` arriving as a
 * locale-keyed map in almost every case but as a bare string in others, half the
 * packages omitting `supportLink` entirely, and `plugin-icon` pointing at arbitrary
 * paths (`src/Resources/plugin.png`, `src/Resources/config/accesstive.png`) rather
 * than the conventional location. Every accessor here therefore tolerates both
 * shapes and absence, because a crawler that throws on the first non-conforming
 * package indexes nothing.
 */
final class ComposerMetadataExtractor
{
    /**
     * Locale preference for the single displayed string.
     *
     * English first because the site is English, then German — the Shopware
     * ecosystem is heavily German and many plugins ship German-only metadata, so
     * falling back to it is the difference between a description and a blank.
     *
     * @var list<string>
     */
    private const LOCALE_PREFERENCE = ['en-GB', 'en-US', 'en', 'de-DE', 'de'];

    /** The conventional icon path, used when `extra.plugin-icon` is absent. */
    private const DEFAULT_ICON = 'src/Resources/config/plugin.png';

    /**
     * @param array<string, mixed> $composerJson
     */
    public function extract(array $composerJson, string $packageName): ComposerMetadata
    {
        $extra = \is_array($composerJson['extra'] ?? null) ? $composerJson['extra'] : [];

        $labels = $this->localisedMap($extra['label'] ?? null);
        $descriptions = $this->localisedMap($extra['description'] ?? null);

        // composer.json's own top-level `description` is a decent fallback when the
        // Shopware-specific extra block omits one.
        if ([] === $descriptions && \is_string($composerJson['description'] ?? null)) {
            $descriptions = ['en-GB' => $composerJson['description']];
        }

        return new ComposerMetadata(
            label: $this->preferred($labels) ?? $packageName,
            description: $this->preferred($descriptions),
            labels: $labels,
            descriptions: $descriptions,
            keywords: $this->stringList($composerJson['keywords'] ?? null),
            license: $this->license($composerJson['license'] ?? null),
            pluginClass: $this->nonEmptyString($extra['shopware-plugin-class'] ?? null),
            pluginIcon: $this->nonEmptyString($extra['plugin-icon'] ?? null) ?? self::DEFAULT_ICON,
            manufacturerLink: $this->preferred($this->localisedMap($extra['manufacturerLink'] ?? null)),
            supportLink: $this->preferred($this->localisedMap($extra['supportLink'] ?? null)),
            repositoryUrl: $this->repositoryUrl($composerJson),
            homepage: $this->nonEmptyString($composerJson['homepage'] ?? null),
        );
    }

    /**
     * Accepts either a locale-keyed map or a bare string, always returns a map.
     *
     * A bare string is filed under `en-GB` rather than invented as untranslated: it
     * is the site's default locale, so it renders, and nothing pretends the other
     * translations exist.
     *
     * @return array<string, string>
     */
    private function localisedMap(mixed $value): array
    {
        if (\is_string($value)) {
            return '' === trim($value) ? [] : ['en-GB' => trim($value)];
        }

        if (!\is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $locale => $text) {
            if (\is_string($locale) && \is_string($text) && '' !== trim($text)) {
                $map[$locale] = trim($text);
            }
        }

        return $map;
    }

    /**
     * @param array<string, string> $map
     */
    private function preferred(array $map): ?string
    {
        foreach (self::LOCALE_PREFERENCE as $locale) {
            if (isset($map[$locale])) {
                return $map[$locale];
            }
        }

        // Some plugins ship only nl-NL or da-DK. Showing the maintainer's own words
        // in a language we did not anticipate beats showing nothing at all.
        return [] === $map ? null : reset($map);
    }

    /**
     * The canonical repository URL.
     *
     * `source.url` is preferred over `homepage` because it is the actual VCS
     * location — homepage frequently points at a marketing page, which is useless
     * for enrichment and wrong for the "view source" link.
     *
     * @param array<string, mixed> $composerJson
     */
    private function repositoryUrl(array $composerJson): ?string
    {
        $source = $composerJson['source'] ?? null;
        $url = \is_array($source) ? ($source['url'] ?? null) : null;

        if (!\is_string($url) || '' === trim($url)) {
            return null;
        }

        $url = trim($url);

        // Normalise git transport forms to a browsable https URL so the stored value
        // can be linked directly and matched against other sources.
        if (str_starts_with($url, 'git@')) {
            $url = str_replace([':', 'git@'], ['/', 'https://'], $url);
        }

        return preg_replace('/\.git$/', '', $url);
    }

    /**
     * @return string|list<string>|null
     */
    private function license(mixed $value): string|array|null
    {
        if (\is_string($value)) {
            return '' === trim($value) ? null : trim($value);
        }

        if (\is_array($value)) {
            $licenses = $this->stringList($value);

            return [] === $licenses ? null : $licenses;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (\is_string($item) && '' !== trim($item)) {
                $result[] = trim($item);
            }
        }

        return $result;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
