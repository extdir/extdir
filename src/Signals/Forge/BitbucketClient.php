<?php

declare(strict_types=1);

namespace App\Signals\Forge;

use App\Catalog\Entity\Extension;
use App\Catalog\Enum\SourceHost;
use App\Http\SafeFetcher;

/**
 * Bitbucket Cloud.
 *
 * The largest group by far: 31 of the 42 non-GitHub extensions, three quarters of
 * everyone this exists for. Any design that quietly handled only GitLab would have
 * missed the majority.
 *
 * SourceHost has no Bitbucket case, so these are classified as Other. Matching on
 * the host here rather than adding an enum case keeps the classification honest —
 * Other really does mean "a forge we have no special knowledge of", and the two
 * unidentified self-hosted instances in the corpus share that case legitimately.
 */
final readonly class BitbucketClient implements ForgeClient
{
    public function __construct(private SafeFetcher $fetcher)
    {
    }

    public function supports(Extension $extension): bool
    {
        if (SourceHost::Other !== $extension->getSourceHost()) {
            return false;
        }

        $host = parse_url((string) $extension->getRepositoryUrl(), \PHP_URL_HOST);

        return \is_string($host) && str_ends_with(strtolower($host), 'bitbucket.org');
    }

    public function fetch(Extension $extension): ?ForgeSignals
    {
        $target = ForgeUrl::split($extension->getRepositoryUrl());

        if (null === $target) {
            return null;
        }

        [, $path] = $target;
        $body = $this->fetcher->fetch(\sprintf('https://api.bitbucket.org/2.0/repositories/%s', $path));

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
            // Repository-level activity rather than a commit timestamp. Good enough
            // to answer "has anyone touched this in two years", which is the
            // question the maintenance status actually asks.
            lastActivityAt: ForgeUrl::parseDate($data['updated_on'] ?? null),
            // Bitbucket counts watchers, not stars. Reporting that number in the
            // same column as a GitHub star count would invite a comparison between
            // two different measurements, so it stays null.
            stars: null,
            forks: null,
        );
    }
}
