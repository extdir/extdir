<?php

declare(strict_types=1);

namespace App\Signals\Forge;

use App\Catalog\Entity\Extension;
use App\Catalog\Enum\SourceHost;
use App\Http\SafeFetcher;

/**
 * GitLab, including self-hosted instances.
 *
 * Self-hosted support is the point rather than a bonus: three of the eight
 * GitLab-hosted extensions here live on instances like gitlab.jonathan-martz.de
 * rather than gitlab.com, and a client hardcoded to gitlab.com would leave exactly
 * the repositories the discovery plan worries about with no signals at all.
 *
 * No authentication. These are public projects, the volume is a handful of requests
 * per crawl, and asking a stranger's GitLab for a token to read something already
 * public would be both rude and unworkable.
 */
final readonly class GitLabClient implements ForgeClient
{
    public function __construct(private SafeFetcher $fetcher)
    {
    }

    public function supports(Extension $extension): bool
    {
        return SourceHost::GitLab === $extension->getSourceHost();
    }

    public function fetch(Extension $extension): ?ForgeSignals
    {
        $target = ForgeUrl::split($extension->getRepositoryUrl());

        if (null === $target) {
            return null;
        }

        [$base, $path] = $target;

        // The project path is URL-encoded whole, slashes included. Groups nest
        // arbitrarily deep — "fyrst/shopware/OrderStates" is one project, not a
        // project inside a project — so splitting on the first slash builds a URL
        // for something that does not exist.
        $body = $this->fetcher->fetch(\sprintf('%s/api/v4/projects/%s', $base, rawurlencode($path)));

        if (null === $body) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($body, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return new ForgeSignals(
            lastActivityAt: ForgeUrl::parseDate($data['last_activity_at'] ?? null),
            stars: \is_int($data['star_count'] ?? null) ? $data['star_count'] : null,
            forks: \is_int($data['forks_count'] ?? null) ? $data['forks_count'] : null,
            archived: true === ($data['archived'] ?? false),
        );
    }
}
