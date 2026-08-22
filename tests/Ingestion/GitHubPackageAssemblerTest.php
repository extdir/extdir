<?php

declare(strict_types=1);

namespace App\Tests\Ingestion;

use App\Ingestion\GitHub\GitHubGraphQl;
use App\Ingestion\GitHub\GitHubPackageAssembler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Turning a repository into Packagist-shaped version data.
 *
 * The duplicate-tag case here is a regression test for a live failure. A repository
 * that had switched tagging convention carried both `1.0.0` and `v1.0.0`; both
 * normalise to `1.0.0.0`, the second insert hit the unique index on
 * (extension, version), and because Doctrine closes the EntityManager on a failed
 * query, the 95 repositories queued behind it all failed with "The EntityManager is
 * closed". One malformed package cost an entire sweep.
 */
final class GitHubPackageAssemblerTest extends TestCase
{
    public function testTwoSpellingsOfOneVersionYieldOneRelease(): void
    {
        $assembled = $this->assemble(
            tags: ['v1.0.0', '1.0.0'],
            manifests: ['v1.0.0' => '{"name":"acme/widget"}', '1.0.0' => '{"name":"acme/widget"}'],
        );

        self::assertNotNull($assembled);
        self::assertCount(1, $assembled['versions'], 'v1.0.0 and 1.0.0 are the same release.');

        // Tags arrive newest first, so the first spelling seen is the one kept.
        self::assertSame('v1.0.0', $assembled['versions'][0]['version']);
        self::assertSame('1.0.0.0', $assembled['versions'][0]['version_normalized']);
    }

    public function testGenuinelyDifferentVersionsAreAllKept(): void
    {
        $assembled = $this->assemble(
            tags: ['v2.0.0', 'v1.1.0', 'v1.0.0'],
            manifests: [
                'v2.0.0' => '{"name":"acme/widget"}',
                'v1.1.0' => '{"name":"acme/widget"}',
                'v1.0.0' => '{"name":"acme/widget"}',
            ],
        );

        self::assertNotNull($assembled);
        self::assertCount(3, $assembled['versions']);
    }

    /**
     * A hardcoded `version` in composer.json must not override the tag.
     *
     * From the live catalogue: a plugin tagged v1.0.1 whose manifest still said
     * 1.0.0. The release was listed as 1.0.0, directly above the real 1.0.0 with a
     * different date, indistinguishable from a duplicate, and simply wrong.
     * Packagist ignores that field for VCS packages for the same reason.
     */
    public function testAStaleVersionInTheManifestLosesToTheTag(): void
    {
        $assembled = $this->assemble(
            tags: ['v1.0.1', 'v1.0.0'],
            manifests: [
                'v1.0.1' => '{"name":"acme/widget","version":"1.0.0"}',
                'v1.0.0' => '{"name":"acme/widget","version":"1.0.0"}',
            ],
        );

        self::assertNotNull($assembled);
        self::assertCount(2, $assembled['versions'], 'These are two releases, not one.');
        self::assertSame('v1.0.1', $assembled['versions'][0]['version']);
        self::assertSame('1.0.1.0', $assembled['versions'][0]['version_normalized']);
        self::assertSame('v1.0.0', $assembled['versions'][1]['version']);
    }

    /**
     * Everything the manifest says that we do not derive ourselves still comes
     * through, the tag wins on identity, not on content.
     */
    public function testTheRestOfTheManifestSurvives(): void
    {
        $assembled = $this->assemble(
            tags: ['v1.0.0'],
            manifests: ['v1.0.0' => '{"name":"acme/widget","require":{"shopware/core":"~6.6.0"},"license":"MIT"}'],
        );

        self::assertNotNull($assembled);
        self::assertSame('MIT', $assembled['versions'][0]['license']);
        self::assertSame(['shopware/core' => '~6.6.0'], $assembled['versions'][0]['require']);
    }

    /**
     * The gate, exercised through the real entry point rather than the shared helper.
     */
    public function testARepositoryThatIsNotAPluginIsRejected(): void
    {
        self::assertNull($this->assemble(
            tags: ['v1.0.0'],
            manifests: ['v1.0.0' => '{"name":"acme/widget"}'],
            headType: 'library',
        ));
    }

    /**
     * An extension with no tagged release has no version to claim compatibility for,
     * which is the whole product. 253 search candidates land here.
     */
    public function testARepositoryWithNoUsableTagIsRejected(): void
    {
        self::assertNull($this->assemble(tags: [], manifests: []));
        self::assertNull($this->assemble(tags: ['nightly'], manifests: ['nightly' => '{"name":"acme/widget"}']));
    }

    /**
     * @param list<string>          $tags
     * @param array<string, string> $manifests raw composer.json per tag
     *
     * @return array{package: string, versions: list<array<string, mixed>>}|null
     */
    private function assemble(array $tags, array $manifests, string $headType = 'shopware-platform-plugin'): ?array
    {
        $repoResponse = [
            'repository' => [
                'nameWithOwner' => 'acme/widget',
                'url' => 'https://github.com/acme/widget',
                'isArchived' => false,
                'head' => ['text' => \sprintf('{"name":"acme/widget","type":"%s"}', $headType)],
                'refs' => [
                    'nodes' => array_map(
                        static fn (string $tag): array => [
                            'name' => $tag,
                            'target' => ['oid' => 'sha-'.$tag, 'committedDate' => '2026-01-01T00:00:00Z'],
                        ],
                        $tags,
                    ),
                ],
            ],
        ];

        $manifestResponse = ['repository' => []];

        foreach ($tags as $index => $tag) {
            $manifestResponse['repository']['t'.$index] = isset($manifests[$tag])
                ? ['text' => $manifests[$tag]]
                : null;
        }

        // A stub, not a mock: these tests care what the assembler does with a
        // response, never how many times it asked for one.
        $client = $this->createStub(GitHubGraphQl::class);
        $client->method('graphql')->willReturnOnConsecutiveCalls($repoResponse, $manifestResponse);

        return (new GitHubPackageAssembler($client, new NullLogger()))->assemble('acme/widget');
    }
}
