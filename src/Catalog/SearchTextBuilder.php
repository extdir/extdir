<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Catalog\Entity\Extension;

/**
 * Builds the denormalised text that the FULLTEXT index actually searches.
 *
 * The index originally covered `label` and `description`, which are the
 * English-preferred strings. That silently made every other locale unsearchable —
 * an extension whose German label is "Versandkostenrechner" could not be found by
 * searching "Versandkosten" unless it also happened to say so in English. In an
 * ecosystem where a large share of plugins ship German-only metadata, that is not
 * an edge case; it is a chunk of the corpus hidden from the search box.
 *
 * So every locale's label and description is folded into one indexed column,
 * together with the package name and composer keywords.
 */
final class SearchTextBuilder
{
    /**
     * MariaDB's FULLTEXT column is TEXT; keeping well inside it avoids silent
     * truncation mid-word on the rare package with very long multilingual copy.
     */
    private const MAX_LENGTH = 60000;

    public function build(Extension $extension): string
    {
        $parts = [
            $extension->getPackageName(),
            // Vendor and package separately too, since the full name is one token
            // to the parser once slashes are stripped.
            str_replace(['/', '-', '_', '.'], ' ', $extension->getPackageName()),
            $extension->getLabel(),
            (string) $extension->getDescription(),
            implode(' ', array_values($extension->getLabels())),
            implode(' ', array_values($extension->getDescriptions())),
            implode(' ', $extension->getKeywords()),
        ];

        $text = implode(' ', array_filter(array_map('trim', $parts), static fn (string $p): bool => '' !== $p));

        // Collapse whitespace so the stored value stays compact and predictable.
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_substr($text, 0, self::MAX_LENGTH);
    }
}
