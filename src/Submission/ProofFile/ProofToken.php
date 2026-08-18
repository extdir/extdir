<?php

declare(strict_types=1);

namespace App\Submission\ProofFile;

use App\Catalog\Entity\Extension;
use App\Submission\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The string a maintainer publishes in their repository to prove they control it.
 *
 * Derived rather than stored. The token is an HMAC over the user and the extension,
 * so it can be recomputed on demand and there is no pending-challenge table, no
 * expiry sweep, and no half-finished verification state to reason about. A challenge
 * table is the kind of thing that quietly accumulates rows for people who started a
 * verification in 2026 and never came back.
 *
 * It is bound to the user as well as the extension on purpose: a file containing one
 * maintainer's token does not verify a different account, so a token left behind in a
 * public repository cannot be replayed by whoever reads it next. Publishing a token
 * is in any case not the secret being protected — using it still requires write
 * access to the repository, which is the whole thing being proven.
 *
 * The derivation depends on APP_SECRET, so rotating that secret invalidates every
 * unpublished token. Already-recorded claims survive, since they are rows rather than
 * recomputations.
 */
final readonly class ProofToken
{
    /**
     * Root-relative, and deliberately not a dotfile: several forge web interfaces
     * hide entries beginning with a dot, and a maintainer who cannot see the file
     * they just committed will assume the check is broken.
     */
    public const string FILENAME = 'extdir-verification.txt';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $appSecret,
    ) {
    }

    public function forUserAndExtension(User $user, Extension $extension): string
    {
        return hash_hmac(
            'sha256',
            \sprintf('extdir-ownership:%s:%s', $user->getId(), $extension->getId()),
            $this->appSecret,
        );
    }

    /**
     * The complete file body, which is what the instructions tell people to paste.
     *
     * A labelled line rather than a bare hash: the file lands in someone else's
     * repository, possibly for years, and a stray 64-character hex string with no
     * explanation is the sort of thing a future colleague deletes during a tidy-up.
     */
    public function fileContents(User $user, Extension $extension): string
    {
        return \sprintf(
            "extdir-ownership-verification\n%s\n",
            $this->forUserAndExtension($user, $extension),
        );
    }

    /**
     * Whether a fetched file proves control.
     *
     * Substring rather than equality, because editors add trailing newlines, forges
     * normalise line endings, and people paste the token into a file that already
     * had a comment in it. hash_equals guards the comparison itself; the search for
     * the token within the body is not secret-dependent.
     */
    public function matches(string $body, User $user, Extension $extension): bool
    {
        $expected = $this->forUserAndExtension($user, $extension);

        foreach (preg_split('/\s+/', trim($body)) ?: [] as $word) {
            if (hash_equals($expected, strtolower($word))) {
                return true;
            }
        }

        return false;
    }
}
