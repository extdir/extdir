<?php

declare(strict_types=1);

namespace App\Submission\Enum;

/**
 * How a maintainer proved they control an extension's repository.
 *
 * Both methods answer the same question — can this person write to the repository
 * this package is built from — and neither involves us deciding who someone is.
 * That matters for the conflict-of-interest rule: verification that rests on an external,
 * checkable fact cannot quietly acquire an exception for the maintainer's own
 * vendor, because there is no judgement call in it to bend.
 */
enum VerificationMethod: string
{
    /**
     * GitHub reports the signed-in user has push or admin permission on the
     * repository. The strongest signal available and the one that needs no action
     * from the maintainer beyond signing in.
     */
    case GitHubPermission = 'github_permission';

    /**
     * A file containing an issued token, committed to the repository's default
     * branch. The fallback for GitLab, Gitea and self-hosted forges, where we
     * cannot ask an API who has write access — but where committing a file proves
     * exactly that.
     */
    case ProofFile = 'proof_file';

    /**
     * A human verified ownership out of band, with the reason recorded. Exists
     * because edge cases exist; every use is in the audit log.
     */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::GitHubPermission => 'GitHub write access',
            self::ProofFile => 'Verification file in the repository',
            self::Manual => 'Verified manually',
        };
    }
}
