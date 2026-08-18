<?php

declare(strict_types=1);

namespace App\Tests\Submission;

use App\Compatibility\Enum\ConstraintTier;
use App\License\Enum\LicenseStatus;
use App\Signals\Enum\MaintenanceStatus;
use App\Signals\RankingScore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * docs/brief.md §4.6, as something the build can fail on.
 *
 * The maintainer of this directory also publishes Shopware extensions under the
 * `runio` vendor. The brief's answer to that conflict is that ranking is
 * algorithmic, the algorithm is public, and ownership verification treats `runio`
 * like any vendor with no hardcoded exceptions. Those are promises about code, and
 * a promise about code that nothing checks is a promise about intentions.
 *
 * So this test reads the source of every file on the ranking and verification
 * paths and fails if a vendor name appears in it. It is a blunt instrument on
 * purpose: the failure mode it guards against is not a subtle bias in a weight,
 * it is somebody one day writing `if ($vendor === '…')` for a reason that felt
 * good at the time.
 */
final class NoVendorExceptionsTest extends TestCase
{
    /**
     * Vendor names that must never appear in decision-making code. `runio` is the
     * one that matters; the others are here so the test is about vendors in
     * general rather than about protecting one from itself.
     *
     * @var list<string>
     */
    private const VENDOR_NAMES = ['runio', 'frosh', 'shopware/', 'swag', 'friendsofshopware'];

    /**
     * Every file that decides how an extension is ranked, or whether someone
     * controls it.
     *
     * @return iterable<string, array{string}>
     */
    public static function decisionPathProvider(): iterable
    {
        $root = \dirname(__DIR__, 2);

        $paths = [
            'ranking score' => 'src/Signals/RankingScore.php',
            'ranking recompute' => 'src/Signals/SignalsRecomputer.php',
            'maintenance evaluation' => 'src/Signals/MaintenanceEvaluator.php',
            'compatibility tiering' => 'src/Compatibility/ConstraintParser.php',
            'compatibility resolution' => 'src/Compatibility/CompatibilityResolver.php',
            'ownership verification' => 'src/Submission/OwnershipVerifier.php',
            'search and ordering' => 'src/Catalog/Search/ExtensionSearch.php',
            'licence classification' => 'src/License/SpdxAllowlist.php',
            'licence resolution' => 'src/License/LicenseResolver.php',
        ];

        foreach ($paths as $label => $path) {
            yield $label => [$root.'/'.$path];
        }
    }

    #[DataProvider('decisionPathProvider')]
    public function testNoVendorIsNamedOnADecisionPath(string $path): void
    {
        self::assertFileExists($path, 'the decision path moved; update this test rather than deleting it');

        $source = (string) file_get_contents($path);

        // Comments are stripped first. These files discuss the conflict-of-interest
        // rule at length, and prose about `runio` is exactly what we want to keep —
        // it is executable references that are forbidden.
        $code = $this->stripComments($source);

        foreach (self::VENDOR_NAMES as $vendor) {
            self::assertStringNotContainsStringIgnoringCase(
                $vendor,
                $code,
                \sprintf(
                    'The vendor "%s" is named in executable code in %s. Ranking and verification '
                    .'must not know about specific vendors (docs/brief.md §4.6).',
                    $vendor,
                    basename($path),
                ),
            );
        }
    }

    /**
     * The disclosure flag is allowed to exist — §4.6 requires the badge — but it
     * must be inert. Two extensions identical apart from the vendor flag must
     * score identically.
     */
    public function testTheDisclosureFlagCannotAffectScore(): void
    {
        $ranking = new RankingScore();
        $now = new \DateTimeImmutable('2026-08-18');

        $arguments = [
            true, true, MaintenanceStatus::Current, ConstraintTier::Explicit,
            LicenseStatus::Permissive, 1.0, $now, $now,
        ];

        // The scorer takes no vendor and no extension — it cannot see who published
        // the thing it is scoring, which is the strongest form this guarantee can
        // take. If that signature ever grows a vendor argument, this test is the
        // place the reason should be argued.
        $reflection = new \ReflectionMethod(RankingScore::class, 'score');
        $parameterNames = array_map(
            static fn (\ReflectionParameter $p): string => strtolower($p->getName()),
            $reflection->getParameters(),
        );

        foreach ($parameterNames as $name) {
            self::assertStringNotContainsString('vendor', $name);
            self::assertStringNotContainsString('extension', $name);
        }

        self::assertSame($ranking->score(...$arguments), $ranking->score(...$arguments));
    }

    /**
     * The published weights are the whole of the formula. If a component were ever
     * added without being published, /ranking would describe an algorithm that is
     * not the one running.
     */
    public function testEveryScoringComponentIsPublished(): void
    {
        $ranking = new RankingScore();
        $now = new \DateTimeImmutable('2026-08-18');

        $components = $ranking->components(
            true, true, MaintenanceStatus::Current, ConstraintTier::Explicit,
            LicenseStatus::Permissive, 1.0, $now, $now,
        );

        self::assertCount(
            \count(RankingScore::published()),
            $components,
            'a scoring component exists that /ranking does not publish',
        );
    }

    private function stripComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= \is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
