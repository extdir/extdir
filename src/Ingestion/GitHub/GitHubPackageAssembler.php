<?php

declare(strict_types=1);

namespace App\Ingestion\GitHub;

use App\Ingestion\ShopwarePackageType;
use Composer\Semver\VersionParser;
use Psr\Log\LoggerInterface;

/**
 * Builds Packagist-shaped version data for a repository that is not on Packagist.
 *
 * The output deliberately matches the structure of a p2 metadata document, so
 * PackageIngestor consumes it unchanged and every downstream stage — constraint
 * parsing, licence classification, taxonomy, signals, download resolution — works
 * without knowing where the data came from. Teaching the ingestor about a second
 * source shape would have meant duplicating all of that.
 *
 * composer.json is read *per tag* rather than once at HEAD. That costs a second
 * query but it is the whole point: the compatibility matrix records what each
 * released version declared, and taking HEAD's constraint for every historical
 * release would invent support the maintainer never claimed.
 */
final class GitHubPackageAssembler
{
    /**
     * Tags fetched per repository, newest first.
     *
     * Deep history matters less here than for Packagist packages: an extension
     * never published to Packagist is usually young. Thirty covers the useful span
     * while keeping the aliased blob query small enough not to time out.
     */
    private const MAX_TAGS = 30;

    private const REPO_QUERY = <<<'GRAPHQL'
        query($owner: String!, $name: String!) {
            repository(owner: $owner, name: $name) {
                nameWithOwner
                url
                isArchived
                defaultBranchRef { name }
                head: object(expression: "HEAD:composer.json") { ... on Blob { text } }
                refs(refPrefix: "refs/tags/", first: 30, orderBy: {field: TAG_COMMIT_DATE, direction: DESC}) {
                    nodes {
                        name
                        target {
                            ... on Commit { oid committedDate }
                            ... on Tag { target { ... on Commit { oid committedDate } } }
                        }
                    }
                }
            }
        }
        GRAPHQL;

    public function __construct(
        private readonly GitHubGraphQl $github,
        private readonly LoggerInterface $logger,
        private readonly VersionParser $versionParser = new VersionParser(),
    ) {
    }

    /**
     * @return array{package: string, versions: list<array<string, mixed>>}|null
     *                                                                           null when the repository is not a Shopware 6 extension
     */
    public function assemble(string $fullName): ?array
    {
        [$owner, $name] = array_pad(explode('/', $fullName, 2), 2, '');

        if ('' === $owner || '' === $name) {
            return null;
        }

        $data = $this->github->graphql(self::REPO_QUERY, ['owner' => $owner, 'name' => $name]);
        $repo = $data['repository'] ?? null;

        if (!\is_array($repo)) {
            return null;
        }

        $headJson = $this->decode($repo['head']['text'] ?? null);

        // The composer.json type is the only reliable filter. A topic says what the
        // author thinks the repository is about; the type says what Composer will
        // actually install, and the topics carry plenty of themes, demos, docker
        // setups and Shopware 5 plugins that are none of our business.
        if (null === $headJson || !ShopwarePackageType::matches($headJson)) {
            return null;
        }

        $packageName = $headJson['name'] ?? null;
        if (!\is_string($packageName) || !str_contains($packageName, '/')) {
            // Without a Composer name there is nothing to key the catalog on.
            return null;
        }

        $tags = $this->extractTags($repo);
        $versions = $this->buildVersions($owner, $name, $packageName, $repo, $tags);

        if ([] === $versions) {
            // No usable tag. Rather than invent a release from an untagged default
            // branch — which no merchant can install a specific version of — the
            // repository is skipped.
            return null;
        }

        return ['package' => $packageName, 'versions' => $versions];
    }

    /**
     * @param array<string, mixed>                                 $repo
     * @param list<array{tag: string, oid: string, date: ?string}> $tags
     *
     * @return list<array<string, mixed>>
     */
    private function buildVersions(
        string $owner,
        string $name,
        string $packageName,
        array $repo,
        array $tags,
    ): array {
        $manifests = $this->fetchManifestsPerTag($owner, $name, $tags);
        $repoUrl = \is_string($repo['url'] ?? null) ? $repo['url'] : \sprintf('https://github.com/%s/%s', $owner, $name);

        $versions = [];
        $seen = [];

        foreach ($tags as $tag) {
            $composerJson = $manifests[$tag['tag']] ?? null;

            if (null === $composerJson) {
                continue;
            }

            $normalized = $this->normalise($tag['tag']);
            if (null === $normalized) {
                continue;
            }

            // Two tags can normalise to one version: `v1.0.0` and `1.0.0` are the same
            // release spelled twice, and repositories that switched convention carry
            // both. Emitting both violates the unique index on (extension, version)
            // and — because a failed insert closes the EntityManager — took the rest
            // of the sweep down with it when it was first hit.
            //
            // Tags arrive newest first, so the first spelling of a version wins.
            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;

            // Assembled into the same shape Packagist serves, so the ingestor cannot
            // tell the difference.
            $versions[] = $composerJson + [
                'name' => $packageName,
                'version' => $tag['tag'],
                'version_normalized' => $normalized,
                'time' => $tag['date'],
                'source' => [
                    'type' => 'git',
                    'url' => $repoUrl.'.git',
                    'reference' => $tag['oid'],
                ],
                'dist' => [
                    'type' => 'zip',
                    // The same zipball Packagist would point at. The download
                    // resolver upgrades this to a maintainer release archive where
                    // one exists.
                    'url' => \sprintf('https://api.github.com/repos/%s/%s/zipball/%s', $owner, $name, $tag['tag']),
                    'reference' => $tag['oid'],
                ],
            ];
        }

        return $versions;
    }

    /**
     * Fetches composer.json at each tag in one aliased query.
     *
     * @param list<array{tag: string, oid: string, date: ?string}> $tags
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchManifestsPerTag(string $owner, string $name, array $tags): array
    {
        if ([] === $tags) {
            return [];
        }

        $declarations = ['$owner: String!', '$name: String!'];
        $fields = [];
        $variables = ['owner' => $owner, 'name' => $name];

        foreach ($tags as $index => $tag) {
            // Tag names come from third-party repositories and can contain anything
            // git allows, so they travel as variables rather than being spliced into
            // the query text.
            $declarations[] = \sprintf('$e%d: String!', $index);
            $variables['e'.$index] = $tag['tag'].':composer.json';
            $fields[] = \sprintf('t%1$d: object(expression: $e%1$d) { ... on Blob { text } }', $index);
        }

        $query = \sprintf(
            'query(%s) { repository(owner: $owner, name: $name) { %s } }',
            implode(', ', $declarations),
            implode(' ', $fields),
        );

        $data = $this->github->graphql($query, $variables);
        $repo = $data['repository'] ?? null;

        if (!\is_array($repo)) {
            $this->logger->info('Could not read tagged manifests', ['repository' => $owner.'/'.$name]);

            return [];
        }

        $manifests = [];

        foreach ($tags as $index => $tag) {
            $decoded = $this->decode($repo['t'.$index]['text'] ?? null);

            // A tag without a composer.json predates the package or is not a
            // release of it. Skipping is correct; guessing is not.
            if (null !== $decoded) {
                $manifests[$tag['tag']] = $decoded;
            }
        }

        return $manifests;
    }

    /**
     * @param array<string, mixed> $repo
     *
     * @return list<array{tag: string, oid: string, date: ?string}>
     */
    private function extractTags(array $repo): array
    {
        $nodes = $repo['refs']['nodes'] ?? null;

        if (!\is_array($nodes)) {
            return [];
        }

        $tags = [];

        foreach (\array_slice($nodes, 0, self::MAX_TAGS) as $node) {
            if (!\is_array($node) || !\is_string($node['name'] ?? null)) {
                continue;
            }

            $target = \is_array($node['target'] ?? null) ? $node['target'] : [];

            // An annotated tag wraps the commit one level deeper than a lightweight
            // tag, and both are common.
            $commit = \is_array($target['target'] ?? null) ? $target['target'] : $target;

            $oid = $commit['oid'] ?? null;
            if (!\is_string($oid)) {
                continue;
            }

            $date = $commit['committedDate'] ?? null;

            $tags[] = [
                'tag' => $node['name'],
                'oid' => $oid,
                'date' => \is_string($date) ? $date : null,
            ];
        }

        return $tags;
    }

    private function normalise(string $tag): ?string
    {
        try {
            return $this->versionParser->normalize($tag);
        } catch (\UnexpectedValueException) {
            // Tags like "latest" or "nightly" are not versions. Common enough that
            // it is not worth logging.
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(mixed $text): ?array
    {
        if (!\is_string($text) || '' === trim($text)) {
            return null;
        }

        try {
            $decoded = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }
}
