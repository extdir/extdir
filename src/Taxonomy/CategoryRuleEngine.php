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
 * builds compounds, "Versandkostenrechner" must match "versandkosten", so
 * substring matching is required, not sloppy. But applying it to short terms is how
 * "abo" starts matching "about", so anything under five characters is held to a
 * word boundary.
 *
 * Each category counts at most once per source, so a category cannot win merely by
 * listing more synonyms than its neighbours.
 */
final class CategoryRuleEngine
{
    /** Weight of a match found in composer.json `keywords`, the most deliberate signal. */
    private const WEIGHT_KEYWORD = 3;

    /** Weight of a match in the plugin label. */
    private const WEIGHT_LABEL = 2;

    /**
     * Weight of a match in the package name.
     *
     * Weighted like the label because it is equally deliberate, nobody names a
     * package `shopware-queue-monitor` by accident. This turned out to matter far
     * more than expected: 360 of the 422 indexed extensions declare no composer
     * keywords at all, so for most of the corpus the name and label are the only
     * strong signals available.
     */
    private const WEIGHT_PACKAGE_NAME = 2;

    /** Weight of a match in the description, the noisiest source. */
    private const WEIGHT_DESCRIPTION = 1;

    /**
     * A single generic term found only in the description scores 1 and does not clear
     * this bar. That is intentional: descriptions mention neighbouring concepts
     * constantly ("works alongside your payment provider"), and a wrong category is a
     * support email.
     *
     * What changed is that a description naming an unambiguous term, `klarna`,
     * `turnstile`, `datev`, is not mentioning a neighbouring concept, it is stating
     * its subject. Those reach the bar; generic words still do not, however many of
     * them appear.
     *
     * This mattered because of the shape of the corpus rather than theory: 520 of the
     * 595 indexed extensions declare no composer keywords at all, and every one of them
     * has a description. The strongest signal is missing for 87% of the catalogue and
     * the weakest is universal, which left 223 extensions uncategorised.
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

        foreach (CategoryDefinition::all() as $key => [, , $terms, $strong]) {
            foreach ($sources as [$weight, $haystack]) {
                if ('' === $haystack) {
                    continue;
                }

                if (self::WEIGHT_DESCRIPTION === $weight) {
                    $scores[$key] = ($scores[$key] ?? 0) + $this->descriptionWeight($haystack, $terms, $strong);

                    continue;
                }

                foreach ([...$strong, ...$terms] as $term) {
                    if ($this->matches($haystack, $this->normalise($term))) {
                        // Once per source, not once per synonym.
                        $scores[$key] = ($scores[$key] ?? 0) + $weight;
                        break;
                    }
                }
            }
        }

        arsort($scores);

        // Below the bar, nothing is assigned, deliberately, with no "best guess"
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
     * Which terms matched, per category, for auditing an assignment.
     *
     * Reports the terms themselves rather than a count, and says which source found
     * them and whether they were strong. A category assigned on the strength of one
     * word in a description is exactly the case worth reviewing before it ships, and a
     * number cannot tell you that; "payment, description: klarna, alipay" can.
     *
     * @param list<string>          $keywords
     * @param array<string, string> $labels
     * @param array<string, string> $descriptions
     *
     * @return array<string, array<string, list<string>>> category => source => terms,
     *                                                    strong terms marked with a leading *
     */
    public function explain(
        array $keywords,
        array $labels,
        array $descriptions,
        string $packageName = '',
    ): array {
        $matched = [];

        $sources = [
            'keywords' => $keywords,
            'name' => [$this->packageSegment($packageName)],
            'label' => array_values($labels),
            'description' => array_values($descriptions),
        ];

        foreach (CategoryDefinition::all() as $key => [, , $terms, $strong]) {
            $hits = [];

            foreach ($sources as $source => $texts) {
                $haystack = $this->normalise(implode(' ', $texts));

                if ('' === $haystack) {
                    continue;
                }

                foreach ($strong as $term) {
                    if ($this->matches($haystack, $this->normalise($term))) {
                        $hits[$source][] = '*'.$term;
                    }
                }

                foreach ($terms as $term) {
                    if ($this->matches($haystack, $this->normalise($term))) {
                        $hits[$source][] = $term;
                    }
                }
            }

            if ([] !== $hits) {
                $matched[$key] = $hits;
            }
        }

        return $matched;
    }

    /**
     * What one category's presence in a description is worth.
     *
     * Enough on its own only when the description names a term that means exactly one
     * thing. Any number of generic terms is worth one, which never assigns anything by
     * itself.
     *
     * Counting two generic terms as corroboration was tried and measured against the
     * corpus first. It was wrong, and wrong in a way worth recording: the term lists
     * carry English and German side by side, so `customer` and `kunde`, or `delivery`
     * and `lieferung`, or `payment` and `zahlung`, are the same concept written twice,
     * not two independent signals. A CDN plugin was filed under Shipping on "delivery,
     * lieferung" and a search-suggest plugin under Customers on "customer, kunde".
     * Roughly a third of the new assignments were wrong that way.
     *
     * A brand is different: nobody writes "Klarna" or "Turnstile" in a description
     * unless that is what the extension is for.
     *
     * @param list<string> $terms  generic terms, indicative but never conclusive
     * @param list<string> $strong terms that mean exactly one thing
     */
    private function descriptionWeight(string $haystack, array $terms, array $strong): int
    {
        foreach ($strong as $term) {
            if ($this->matches($haystack, $this->normalise($term))) {
                return self::MIN_SCORE;
            }
        }

        foreach ($terms as $term) {
            if ($this->matches($haystack, $this->normalise($term))) {
                return self::WEIGHT_DESCRIPTION;
            }
        }

        return 0;
    }

    /**
     * Only the part after the slash.
     *
     * Vendor names are excluded because they are company names, not descriptions of
     * function, a vendor called "shipping-gmbh" would otherwise file its entire
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
