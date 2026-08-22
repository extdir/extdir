<?php

declare(strict_types=1);

namespace App\Submission\ProofFile;

use App\Catalog\Enum\SourceHost;

/**
 * Where a given forge serves a raw file from.
 *
 * Raw URLs rather than each forge's API, which is a deliberate trade. An API gives
 * better errors and a stable contract, but it has to be implemented per forge and
 * usually needs a token, and a third of the GitLab-hosted extensions here live on
 * instances like `gitlab.jonathan-martz.de` rather than gitlab.com, with two more on
 * hosts (`git.schubwerk.com`, `git.optiweb.serv.si`) whose software we can only
 * guess at. Raw paths are identical between gitlab.com and a self-hosted GitLab,
 * which is exactly the property needed here.
 *
 * Bitbucket is not an afterthought: it is 31 of the 42 non-GitHub extensions,
 * three quarters of everyone this mechanism exists for.
 *
 * Both `main` and `master` are tried because the corpus spans a decade of Shopware
 * history and the older repositories predate the rename.
 */
final readonly class RawFileUrls
{
    private const array BRANCHES = ['main', 'master'];

    /**
     * Candidate URLs for a file at the repository root, most likely first.
     *
     * @return list<string>
     */
    public function candidates(?string $repositoryUrl, SourceHost $host, string $filename): array
    {
        $parsed = $this->parse($repositoryUrl);

        if (null === $parsed) {
            return [];
        }

        [$base, $path] = $parsed;

        $shapes = match ($host) {
            SourceHost::GitLab => ['%s/%s/-/raw/%s/%s'],
            SourceHost::Gitea => ['%s/%s/raw/branch/%s/%s'],
            // Bitbucket falls in here along with anything unrecognised. Its raw path
            // happens to match neither of the other two, so unknown self-hosted
            // forges get all three shapes tried, cheap, since a wrong guess is one
            // 404 against a host we were going to contact anyway.
            SourceHost::Other, SourceHost::GitHub => [
                '%s/%s/raw/%s/%s',
                '%s/%s/-/raw/%s/%s',
                '%s/%s/raw/branch/%s/%s',
            ],
        };

        $urls = [];

        foreach (self::BRANCHES as $branch) {
            foreach ($shapes as $shape) {
                $urls[] = \sprintf($shape, $base, $path, $branch, $filename);
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Splits a repository URL into its origin and its project path.
     *
     * The path is kept whole rather than split into owner and repository, because
     * GitLab groups nest arbitrarily deep, `fyrst/shopware/OrderStates` is one
     * project, not a project inside a project, and splitting on the first slash
     * would build a URL for something that does not exist.
     *
     * @return array{string, string}|null
     */
    private function parse(?string $url): ?array
    {
        if (null === $url || '' === trim($url)) {
            return null;
        }

        $parts = parse_url(trim($url));

        if (false === $parts || !isset($parts['host'], $parts['path'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');

        // Anything that is not plain http(s), git://, ssh://, scp-style, is not
        // something a raw file can be fetched from, and SafeFetcher would refuse it
        // in any case. Returning null here keeps the refusal legible.
        if ('https' !== $scheme && 'http' !== $scheme) {
            return null;
        }

        $path = trim($parts['path'], '/');
        $path = preg_replace('/\.git$/i', '', $path) ?? $path;

        if ('' === $path) {
            return null;
        }

        // Always https for the fetch, whatever the metadata claimed. A repository
        // URL recorded as http is a stale entry, not an instruction.
        return [\sprintf('https://%s', $parts['host']), $path];
    }
}
