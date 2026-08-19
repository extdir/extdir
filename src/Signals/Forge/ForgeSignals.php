<?php

declare(strict_types=1);

namespace App\Signals\Forge;

/**
 * What a non-GitHub forge can tell us about a repository.
 *
 * Deliberately smaller than what the GitHub enricher collects. These APIs do not
 * expose issue counts, CI status or response times without authentication, and
 * inventing a plausible-looking value for a field a forge does not publish would
 * corrupt the ranking with fabricated data. Absent stays absent.
 */
final readonly class ForgeSignals
{
    public function __construct(
        /**
         * The last time the repository changed.
         *
         * GitLab and Gitea report a genuine last activity timestamp. Bitbucket's
         * `updated_on` covers repository-level changes rather than commits
         * specifically, which is close enough to answer "has anyone touched this in
         * two years" and not precise enough to be called a commit date anywhere in
         * the interface.
         */
        public ?\DateTimeImmutable $lastActivityAt,
        /**
         * Null where the forge has no equivalent, which is not the same as zero.
         * Bitbucket counts watchers, a different thing measured differently, so it
         * reports null rather than a lookalike number that would sit in the same
         * column as GitHub stars and invite comparison.
         */
        public ?int $stars,
        public ?int $forks,
        public bool $archived = false,
    ) {
    }
}
