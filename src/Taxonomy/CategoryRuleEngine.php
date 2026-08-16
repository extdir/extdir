<?php

declare(strict_types=1);

namespace App\Taxonomy;

/**
 * Assigns categories by deterministic keyword matching.
 *
 * Two decisions shape the matching, and both come from the corpus rather than from
 * theory.
 *
 * **German is transliterated, not special-cased.** Both haystack and terms have
 * umlauts folded (ä→a, ß→ss) before comparison, so "Übersetzung" and "ubersetzung"
 * are the same token and rules need only one spelling.
 *
 * **Longer terms match as substrings, short ones only as whole words.** German
 * builds compounds — "Versandkostenrechner" must match "versandkosten" — so
 * substring matching is required, not sloppy. But applying it to short terms is how
 * "abo" starts matching "about", so anything under five characters is held to a
 * word boundary.
 *
 * Each category counts at most once per source, so a category cannot win merely by
 * listing more synonyms than its neighbours.
 */
final class CategoryRuleEngine
{
    /** Weight of a match found in composer.json `keywords` — the most deliberate signal. */
    private const WEIGHT_KEYWORD = 3;

    /** Weight of a match in the plugin label. */
    private const WEIGHT_LABEL = 2;

    /**
     * Weight of a match in the package name.
     *
     * Weighted like the label because it is equally deliberate — nobody names a
     * package `shopware-queue-monitor` by accident. This turned out to matter far
     * more than expected: 360 of the 422 indexed extensions declare no composer
     * keywords at all, so for most of the corpus the name and label are the only
     * strong signals available.
     */
    private const WEIGHT_PACKAGE_NAME = 2;

    /** Weight of a match in the description — the noisiest source. */
    private const WEIGHT_DESCRIPTION = 1;

    /**
     * A description-only match scores 1 and does not clear this bar. That is
     * intentional: descriptions mention neighbouring concepts constantly ("works
     * alongside your payment provider"), and a wrong category is a support email.
     */
    private const MIN_SCORE = 2;

    private const MAX_CATEGORIES = 3;

    /**
     * @param list<string>          $keywords
     * @param array<string, string> $labels
     * @param array<string, string> $descriptions
     *
     * @return list<string> category keys, strongest first
     */
    public function categorise(
        array $keywords,
        array $labels,
        array $descriptions,
        string $packageName = '',
    ): array {
        $sources = [
            [self::WEIGHT_KEYWORD, $this->normalise(implode(' ', $keywords))],
            [self::WEIGHT_PACKAGE_NAME, $this->normalise($this->packageSegment($packageName))],
            [self::WEIGHT_LABEL, $this->normalise(implode(' ', array_values($labels)))],
            [self::WEIGHT_DESCRIPTION, $this->normalise(implode(' ', array_values($descriptions)))],
        ];

        $scores = [];

        foreach (CategoryDefinition::all() as $key => [, , $terms]) {
            foreach ($sources as [$weight, $haystack]) {
                if ('' === $haystack) {
                    continue;
                }

                foreach ($terms as $term) {
                    if ($this->matches($haystack, $this->normalise($term))) {
                        // Once per source, not once per synonym.
                        $scores[$key] = ($scores[$key] ?? 0) + $weight;
                        break;
                    }
                }
            }
        }

        arsort($scores);

        // Below the bar, nothing is assigned — deliberately, with no "best guess"
        // fallback. A description-only hit carries no signal that separates "Adds a
        // sitemap for better indexing" (genuinely SEO) from "Compatible with any
        // payment provider you already use" (not a payment extension at all). Since
        // no weighting can tell those apart, guessing would file the second under
        // Payment and mislead every merchant who browsed that category.
        //
        // Uncategorised extensions remain fully searchable, and the count reported
        // by app:taxonomy:classify is the queue of rules still to be written.
        $confident = array_filter($scores, static fn (int $score): bool => $score >= self::MIN_SCORE);

        return array_keys(\array_slice($confident, 0, self::MAX_CATEGORIES, true));
    }

    /**
     * Scores for every category that matched, for the explain command and for
     * auditing a disputed assignment.
     *
     * @param list<string>          $keywords
     * @param array<string, string> $labels
     * @param array<string, string> $descriptions
     *
     * @return array<string, int>
     */
    public function explain(
        array $keywords,
        array $labels,
        array $descriptions,
        string $packageName = '',
    ): array {
        $matched = [];

        foreach (CategoryDefinition::all() as $key => [, , $terms]) {
            $hits = [];
            $sources = [
                $keywords,
                [$this->packageSegment($packageName)],
                array_values($labels),
                array_values($descriptions),
            ];

            foreach ($sources as $texts) {
                $haystack = $this->normalise(implode(' ', $texts));
                foreach ($terms as $term) {
                    if ('' !== $haystack && $this->matches($haystack, $this->normalise($term))) {
                        $hits[] = $term;
                    }
                }
            }

            if ([] !== $hits) {
                $matched[$key] = \count(array_unique($hits));
            }
        }

        return $matched;
    }

    /**
     * Only the part after the slash.
     *
     * Vendor names are excluded because they are company names, not descriptions of
     * function — a vendor called "shipping-gmbh" would otherwise file its entire
     * catalogue under Shipping regardless of what the plugins actually do.
     */
    private function packageSegment(string $packageName): string
    {
        $slash = strpos($packageName, '/');

        return false === $slash ? $packageName : substr($packageName, $slash + 1);
    }

    private function matches(string $haystack, string $term): bool
    {
        if ('' === $term) {
            return false;
        }

        // Multi-word terms and anything long enough to be distinctive match as a
        // substring, which is what catches German compounds.
        if (str_contains($term, ' ') || mb_strlen($term) >= 5) {
            return str_contains($haystack, $term);
        }

        return 1 === preg_match('/\b'.preg_quote($term, '/').'\b/', $haystack);
    }

    /**
     * Lowercase, fold German characters, reduce everything else to single spaces.
     */
    private function normalise(string $value): string
    {
        $value = mb_strtolower($value);

        $value = strtr($value, [
            'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
            'á' => 'a', 'à' => 'a', 'é' => 'e', 'è' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        ]);

        $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
