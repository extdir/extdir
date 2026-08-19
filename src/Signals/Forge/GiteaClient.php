<?php

declare(strict_types=1);

namespace App\Signals\Forge;

use App\Catalog\Entity\Extension;
use App\Catalog\Enum\SourceHost;
use App\Http\SafeFetcher;

/**
 * Gitea and Forgejo, including Codeberg.
 *
 * The API shape is stable across both projects and across self-hosted instances,
 * which is why one client covers all of them.
 */
final readonly class GiteaClient implements ForgeClient
{
    public function __construct(private SafeFetcher $fetcher)
    {
    }

    public function supports(Extension $extension): bool
    {
        return SourceHost::Gitea === $extension->getSourceHost();
    }

    public function fetch(Extension $extension): ?ForgeSignals
    {
        $target = ForgeUrl::split($extension->getRepositoryUrl());

        if (null === $target) {
            return null;
        }

        [$base, $path] = $target;
        $body = $this->fetcher->fetch(\sprintf('%s/api/v1/repos/%s', $base, $path));

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
            lastActivityAt: ForgeUrl::parseDate($data['updated_at'] ?? null),
            stars: \is_int($data['stars_count'] ?? null) ? $data['stars_count'] : null,
            forks: \is_int($data['forks_count'] ?? null) ? $data['forks_count'] : null,
            archived: true === ($data['archived'] ?? false),
        );
    }
}
