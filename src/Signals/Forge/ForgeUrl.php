<?php

declare(strict_types=1);

namespace App\Signals\Forge;

/**
 * Shared parsing for the forge clients.
 */
final class ForgeUrl
{
    /**
     * Splits a repository URL into its origin and project path.
     *
     * The path is returned whole rather than as owner and repository, because
     * GitLab groups nest arbitrarily deep and every caller either needs the whole
     * thing or can split it themselves.
     *
     * @return array{string, string}|null
     */
    public static function split(?string $url): ?array
    {
        if (null === $url || '' === trim($url)) {
            return null;
        }

        $parts = parse_url(trim($url));

        if (false === $parts || !isset($parts['host'], $parts['path'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');

        if ('https' !== $scheme && 'http' !== $scheme) {
            return null;
        }

        $path = trim($parts['path'], '/');
        $path = preg_replace('/\.git$/i', '', $path) ?? $path;

        if ('' === $path) {
            return null;
        }

        // Always https, whatever the metadata claimed. A repository URL recorded as
        // http is a stale entry rather than an instruction, and SafeFetcher refuses
        // plaintext in any case.
        return [\sprintf('https://%s', $parts['host']), $path];
    }

    /**
     * Forges disagree about date format; all of them produce something DateTime can
     * read, and a value we cannot parse is treated as absent rather than as now.
     */
    public static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
