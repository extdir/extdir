<?php

declare(strict_types=1);

namespace App\Signals\Gallery;

use App\Catalog\Entity\Extension;
use App\Catalog\Enum\SourceHost;
use App\Signals\Forge\ForgeUrl;

/**
 * Which hosts count as "the extension's own forge" for the purpose of loading images.
 *
 * This is the list the reader's consent actually covers. The footer says icons load
 * "from their forges" and the privacy policy names raw.githubusercontent.com,
 * gitlab.com, bitbucket.org and self-hosted instances, so anything not derived from
 * the repository's own host has no business being in it, however convenient.
 *
 * GitHub needs three entries rather than one because it serves repository files, drag
 * -and-dropped attachments and legacy attachments from three different names, and a
 * README uses all three.
 */
final class ForgeHosts
{
    /**
     * @return list<string>
     */
    public static function for(Extension $extension): array
    {
        $target = ForgeUrl::split($extension->getRepositoryUrl());

        if (null === $target) {
            return [];
        }

        $forgeHost = strtolower((string) parse_url($target[0], \PHP_URL_HOST));

        if (SourceHost::GitHub === $extension->getSourceHost()) {
            return [
                'raw.githubusercontent.com',
                // Attachments a maintainer dropped into the README editor.
                'github.com',
                'user-images.githubusercontent.com',
            ];
        }

        // Every other forge serves raw files from the same host as the repository,
        // including self-hosted GitLab and Gitea instances, which is the case this
        // has to keep working, since a hardcoded list would exclude them.
        return '' === $forgeHost ? [] : [$forgeHost];
    }

    /**
     * Where a repository-relative path resolves to.
     */
    public static function rawBase(Extension $extension): ?string
    {
        $target = ForgeUrl::split($extension->getRepositoryUrl());
        $branch = $extension->getDefaultBranch();

        if (null === $target || null === $branch) {
            return null;
        }

        [$base, $project] = $target;
        $branch = rawurlencode($branch);

        return match ($extension->getSourceHost()) {
            SourceHost::GitHub => \sprintf('https://raw.githubusercontent.com/%s/%s', $project, $branch),
            SourceHost::GitLab => \sprintf('%s/%s/-/raw/%s', $base, $project, $branch),
            SourceHost::Gitea => \sprintf('%s/%s/raw/branch/%s', $base, $project, $branch),
            SourceHost::Other => \sprintf('%s/%s/raw/%s', $base, $project, $branch),
        };
    }
}
