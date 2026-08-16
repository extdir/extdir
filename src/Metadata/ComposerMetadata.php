<?php

declare(strict_types=1);

namespace App\Metadata;

/**
 * The fields we care about from one release's composer.json, already normalised.
 *
 * Everything here comes from structured declarations. Nothing is scraped from a
 * README: docs/brief.md §7 says to read what is declared rather than guess from prose,
 * and README content carries its own licensing that we have no right to reuse.
 */
final readonly class ComposerMetadata
{
    /**
     * @param array<string, string>    $labels       locale => label
     * @param array<string, string>    $descriptions locale => description
     * @param list<string>             $keywords
     * @param string|list<string>|null $license      as declared, unnormalised
     */
    public function __construct(
        public string $label,
        public ?string $description,
        public array $labels,
        public array $descriptions,
        public array $keywords,
        public string|array|null $license,
        public ?string $pluginClass,
        public ?string $pluginIcon,
        public ?string $manufacturerLink,
        public ?string $supportLink,
        public ?string $repositoryUrl,
        public ?string $homepage,
    ) {
    }

    /**
     * The technical plugin name for `bin/console plugin:install --activate <name>`.
     *
     * Shopware derives it from the plugin class basename, which is why this is worth
     * extracting rather than printing a placeholder in the install snippet.
     */
    public function technicalName(): ?string
    {
        if (null === $this->pluginClass) {
            return null;
        }

        $parts = explode('\\', $this->pluginClass);

        return end($parts) ?: null;
    }
}
