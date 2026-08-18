<?php

declare(strict_types=1);

namespace App\Tests\Submission;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Submission\Entity\User;
use App\Submission\OwnershipVerifier;
use App\Submission\ProofFile\DnsHostResolver;
use App\Submission\ProofFile\ProofToken;
use App\Submission\ProofFile\RawFileUrls;
use App\Submission\ProofFile\SafeFetcher;
use App\Submission\Repository\OwnershipClaimRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(OwnershipVerifier::class)]
final class OwnershipVerifierTest extends TestCase
{
    /**
     * The whole point of the check. Every public repository grants read access to
     * everyone, so `pull` proves only that the repository is public — granting
     * control on the strength of it would let any GitHub account delist any
     * extension in the directory.
     */
    public function testReadAccessAloneDoesNotProveOwnership(): void
    {
        $result = $this->verify(['permissions' => ['admin' => false, 'push' => false, 'pull' => true]]);

        self::assertFalse($result->isVerified);
        self::assertTrue($result->isAvailable, 'GitHub answered, so this is a denial rather than an outage');
        self::assertStringContainsString('write access', $result->message);
    }

    #[DataProvider('writeAccessProvider')]
    public function testWriteAccessProvesOwnership(bool $admin, bool $push): void
    {
        $result = $this->verify(['permissions' => ['admin' => $admin, 'push' => $push, 'pull' => true]]);

        self::assertTrue($result->isVerified);
        self::assertNotNull($result->claim);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function writeAccessProvider(): iterable
    {
        yield 'admin' => [true, false];
        yield 'push' => [false, true];
        yield 'both' => [true, true];
    }

    /**
     * An outage is not a judgement about the person. Telling a maintainer "you do
     * not own this" because GitHub timed out is both wrong and insulting, and it
     * pushes them toward the takedown mailbox for a problem that will fix itself.
     */
    public function testATransportFailureIsNotADenial(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('connection reset');
        });

        $result = $this->verifierWith($client)->verifyWithGitHub(
            new User(1, 'maintainer'),
            $this->extension('https://github.com/acme/plugin'),
            'token',
        );

        self::assertFalse($result->isVerified);
        self::assertFalse($result->isAvailable, 'an outage must be distinguishable from a denial');
    }

    /**
     * GitLab, Gitea and self-hosted forges have no equivalent permission API, so
     * the automatic route is unavailable rather than failed — and the caller is
     * expected to offer the proof-file method instead of turning the person away.
     */
    public function testANonGitHubExtensionReportsUnavailableRatherThanDenied(): void
    {
        $result = $this->verifierWith(new MockHttpClient())->verifyWithGitHub(
            new User(1, 'maintainer'),
            $this->extension('https://gitlab.com/acme/plugin'),
            'token',
        );

        self::assertFalse($result->isVerified);
        self::assertFalse($result->isAvailable);
        self::assertStringContainsString('verification file', $result->message);
    }

    /**
     * A repository the account cannot see returns 404 from GitHub rather than a
     * permissions block — which is a denial, not an error.
     */
    public function testAnInaccessibleRepositoryIsDenied(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 404]));

        $result = $this->verifierWith($client)->verifyWithGitHub(
            new User(1, 'maintainer'),
            $this->extension('https://github.com/acme/plugin'),
            'token',
        );

        self::assertFalse($result->isVerified);
        self::assertTrue($result->isAvailable);
    }

    /**
     * Moderators can act without a claim — someone has to handle a rights
     * complaint from a person who is not the maintainer — but only moderators.
     */
    public function testOnlyVerifiedMaintainersAndModeratorsMayAct(): void
    {
        $claims = $this->createStub(OwnershipClaimRepository::class);
        $claims->method('findFor')->willReturn(null);

        $verifier = new OwnershipVerifier(
            new MockHttpClient(),
            $claims,
            $this->createStub(EntityManagerInterface::class),
            ...self::proofFileCollaborators(new MockHttpClient()),
        );

        $extension = $this->extension('https://github.com/acme/plugin');

        $stranger = new User(1, 'stranger');
        self::assertFalse($verifier->mayActOn($stranger, $extension));

        $moderator = new User(2, 'moderator');
        $moderator->setModerator(true);
        self::assertTrue($verifier->mayActOn($moderator, $extension));
    }

    /**
     * @param array<string, mixed> $repoPayload
     */
    private function verify(array $repoPayload): \App\Submission\VerificationResult
    {
        $client = new MockHttpClient(new MockResponse(json_encode($repoPayload, \JSON_THROW_ON_ERROR)));

        return $this->verifierWith($client)->verifyWithGitHub(
            new User(1, 'maintainer'),
            $this->extension('https://github.com/acme/plugin'),
            'token',
        );
    }

    private function verifierWith(MockHttpClient $client): OwnershipVerifier
    {
        $claims = $this->createStub(OwnershipClaimRepository::class);
        $claims->method('findFor')->willReturn(null);

        return new OwnershipVerifier(
            $client,
            $claims,
            $this->createStub(EntityManagerInterface::class),
            ...self::proofFileCollaborators($client),
        );
    }

    private function extension(string $repositoryUrl): Extension
    {
        $extension = new Extension(new Vendor('acme', 'acme'), 'acme/plugin', 'acme-plugin', 'Plugin');
        $extension->setRepositoryUrl($repositoryUrl);

        return $extension;
    }

    /**
     * The proof-file half of the verifier, which the GitHub tests never reach.
     *
     * Real objects rather than stubs: they are pure enough to construct freely, and
     * a stub here would only assert that the constructor takes six arguments.
     *
     * @return array{ProofToken, RawFileUrls, SafeFetcher}
     */
    private static function proofFileCollaborators(MockHttpClient $client): array
    {
        return [
            new ProofToken('test-secret'),
            new RawFileUrls(),
            new SafeFetcher($client, new DnsHostResolver(), new NullLogger()),
        ];
    }
}
