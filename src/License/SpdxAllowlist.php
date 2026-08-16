<?php

declare(strict_types=1);

namespace App\License;

use App\License\Enum\LicenseStatus;

/**
 * The accepted-SPDX list that docs/brief.md §4.1 requires.
 *
 * Everything not named here resolves to Unknown, which means index-only. That
 * default is the entire point: the failure mode we are protecting against is
 * treating an unrecognised or absent license as permission, so the list is an
 * allowlist and never a denylist.
 */
final class SpdxAllowlist
{
    /**
     * Permissive licenses. Redistribution is allowed provided the copyright notice
     * and license text travel with every copy — which is why the packaging step
     * asserts that the original LICENSE file is present inside each ZIP rather than
     * trusting shopware-cli to have included it.
     *
     * @var list<string>
     */
    private const PERMISSIVE = [
        'MIT',
        'BSD-2-Clause',
        'BSD-3-Clause',
        'Apache-2.0',
        'ISC',
        'Unlicense',
        'CC0-1.0',
        'Zlib',
    ];

    /**
     * Copyleft licenses. Present in this ecosystem largely as Shopware 5 heritage,
     * and tracked separately so they are never presented under an "open source =
     * MIT" label (§4.1).
     *
     * @var list<string>
     */
    private const COPYLEFT = [
        'GPL-2.0-only',
        'GPL-2.0-or-later',
        'GPL-3.0-only',
        'GPL-3.0-or-later',
        'AGPL-3.0-only',
        'AGPL-3.0-or-later',
        'LGPL-2.1-only',
        'LGPL-2.1-or-later',
        'LGPL-3.0-only',
        'LGPL-3.0-or-later',
        'MPL-2.0',
        'EUPL-1.2',
        'OSL-3.0',
        'EPL-2.0',
        'CDDL-1.0',
    ];

    /**
     * Full license *names* mapped to SPDX identifiers.
     *
     * The `license` field is specified to hold an SPDX identifier, but maintainers
     * paste the human-readable name that GitHub's licence picker shows them. Seen
     * live in the corpus as "GNU General Public License v3.0". Treating that as
     * unrecognised would strip redistribution rights from a package whose author
     * stated their licence perfectly clearly, just not in the expected notation.
     *
     * @var array<string, string>
     */
    private const NAME_ALIASES = [
        'mitlicense' => 'MIT',
        'theunlicense' => 'Unlicense',
        'apachelicense20' => 'Apache-2.0',
        'apachelicenseversion20' => 'Apache-2.0',
        'bsd2clauselicense' => 'BSD-2-Clause',
        'bsd3clauselicense' => 'BSD-3-Clause',
        'gnugeneralpubliclicensev20' => 'GPL-2.0-only',
        'gnugeneralpubliclicensev20orlater' => 'GPL-2.0-or-later',
        'gnugeneralpubliclicensev30' => 'GPL-3.0-only',
        'gnugeneralpubliclicensev30orlater' => 'GPL-3.0-or-later',
        'gnuaffserogeneralpubliclicensev30' => 'AGPL-3.0-only',
        'gnuaffserogeneralpubliclicensev30orlater' => 'AGPL-3.0-or-later',
        'gnuaffgeneralpubliclicensev30' => 'AGPL-3.0-only',
        'gnulessergeneralpubliclicensev21' => 'LGPL-2.1-only',
        'gnulessergeneralpubliclicensev30' => 'LGPL-3.0-only',
        'mozillapubliclicense20' => 'MPL-2.0',
        'europeanunionpubliclicense12' => 'EUPL-1.2',
        'opensoftwarelicense30' => 'OSL-3.0',
    ];

    /**
     * Deprecated SPDX identifiers still common in older composer.json files, mapped
     * to their current form. Without this, a plugin declaring the perfectly valid
     * `GPL-3.0` would fall through to Unknown and be wrongly refused redistribution.
     *
     * @var array<string, string>
     */
    private const DEPRECATED_ALIASES = [
        'GPL-2.0' => 'GPL-2.0-only',
        'GPL-2.0+' => 'GPL-2.0-or-later',
        'GPL-3.0' => 'GPL-3.0-only',
        'GPL-3.0+' => 'GPL-3.0-or-later',
        'AGPL-3.0' => 'AGPL-3.0-only',
        'AGPL-3.0+' => 'AGPL-3.0-or-later',
        'LGPL-2.1' => 'LGPL-2.1-only',
        'LGPL-2.1+' => 'LGPL-2.1-or-later',
        'LGPL-3.0' => 'LGPL-3.0-only',
        'LGPL-3.0+' => 'LGPL-3.0-or-later',
        'BSD-2' => 'BSD-2-Clause',
        'BSD-3' => 'BSD-3-Clause',
        'Apache2' => 'Apache-2.0',
        'Apache-2' => 'Apache-2.0',
    ];

    /**
     * Normalises an SPDX-ish string to a canonical identifier, or null if it is not
     * recognised. Case-insensitive, because real composer.json files contain "mit"
     * as often as "MIT".
     */
    public function normalise(?string $raw): ?string
    {
        if (null === $raw) {
            return null;
        }

        $candidate = trim($raw);
        if ('' === $candidate) {
            return null;
        }

        foreach (self::DEPRECATED_ALIASES as $alias => $canonical) {
            if (0 === strcasecmp($alias, $candidate)) {
                return $canonical;
            }
        }

        foreach ([...self::PERMISSIVE, ...self::COPYLEFT] as $known) {
            if (0 === strcasecmp($known, $candidate)) {
                return $known;
            }
        }

        // Last resort: a spelled-out licence name. Compared with punctuation and
        // spacing stripped, so "GNU General Public License v3.0" and
        // "gnu general public license v3" both resolve.
        $collapsed = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $candidate));

        return self::NAME_ALIASES[$collapsed] ?? null;
    }

    /**
     * Classifies a single SPDX identifier.
     *
     * An unrecognised but non-empty value is Rejected rather than Unknown: the
     * maintainer did declare something, we simply do not accept it. That difference
     * is worth keeping, because "said nothing" and "said proprietary" call for
     * different follow-up during moderation.
     */
    public function classify(?string $raw): LicenseStatus
    {
        $normalised = $this->normalise($raw);

        if (null === $normalised) {
            return null === $raw || '' === trim($raw)
                ? LicenseStatus::Unknown
                : LicenseStatus::Rejected;
        }

        return \in_array($normalised, self::PERMISSIVE, true)
            ? LicenseStatus::Permissive
            : LicenseStatus::Copyleft;
    }

    /**
     * composer.json allows `license` to be an array, meaning the package may be used
     * under any one of several licenses (a disjunction). We take the most permissive
     * accepted option, since the licensee gets to choose.
     *
     * @param string|list<string>|null $declared
     */
    public function classifyDeclared(string|array|null $declared): LicenseStatus
    {
        if (null === $declared) {
            return LicenseStatus::Unknown;
        }

        if (\is_string($declared)) {
            return $this->classify($declared);
        }

        if ([] === $declared) {
            return LicenseStatus::Unknown;
        }

        $best = LicenseStatus::Unknown;
        foreach ($declared as $candidate) {
            $status = $this->classify($candidate);
            if (LicenseStatus::Permissive === $status) {
                return LicenseStatus::Permissive;
            }
            if (LicenseStatus::Copyleft === $status) {
                $best = LicenseStatus::Copyleft;
            } elseif (LicenseStatus::Rejected === $status && LicenseStatus::Unknown === $best) {
                $best = LicenseStatus::Rejected;
            }
        }

        return $best;
    }

    /**
     * @param string|list<string>|null $declared
     */
    public function firstAcceptedIdentifier(string|array|null $declared): ?string
    {
        foreach ((array) ($declared ?? []) as $candidate) {
            $normalised = $this->normalise($candidate);
            if (null !== $normalised) {
                return $normalised;
            }
        }

        return null;
    }
}
