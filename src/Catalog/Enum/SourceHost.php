<?php

declare(strict_types=1);

namespace App\Catalog\Enum;

/**
 * Where an extension's source repository lives.
 *
 * GitLab and Gitea are not an afterthought here: the discovery plan notes they hold a
 * non-trivial share of the German Shopware ecosystem, where self-hosting is common
 * among agencies. They have no equivalent of GitHub's global search, so discovery
 * for those hosts is seeded from a curated namespace list rather than crawled.
 */
enum SourceHost: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Gitea = 'gitea';
    case Other = 'other';

    /**
     * Whether rich maintenance signals (issue response times, CI status, commit
     * history) can be collected for this host. Only GitHub has an API we query at
     * scale today; the others fall back to whatever Packagist exposes.
     */
    public function supportsEnrichment(): bool
    {
        return self::GitHub === $this;
    }

    public static function fromRepositoryUrl(?string $url): self
    {
        if (null === $url || '' === $url) {
            return self::Other;
        }

        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));

        return match (true) {
            str_contains($host, 'github.com') => self::GitHub,
            str_contains($host, 'gitlab') => self::GitLab,
            str_contains($host, 'gitea') || str_contains($host, 'codeberg') => self::Gitea,
            default => self::Other,
        };
    }
}
