<?php

declare(strict_types=1);

namespace App\Tests\Submission;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Submission\Entity\User;
use App\Submission\ProofFile\ProofToken;
use PHPUnit\Framework\TestCase;

/**
 * The token stands in for a permission check, so the properties that matter are
 * about what it refuses to prove rather than what it produces.
 */
final class ProofTokenTest extends TestCase
{
    private ProofToken $tokens;

    protected function setUp(): void
    {
        $this->tokens = new ProofToken('test-secret-not-the-real-one');
    }

    public function testTheSamePairAlwaysProducesTheSameToken(): void
    {
        $user = $this->user(1);
        $extension = $this->extension(10);

        self::assertSame(
            $this->tokens->forUserAndExtension($user, $extension),
            $this->tokens->forUserAndExtension($user, $extension),
            'Recomputation is what lets this work without a stored challenge.',
        );
    }

    /**
     * The property the whole design rests on. A verification file left in a public
     * repository must not verify whoever reads it next.
     */
    public function testADifferentUserGetsADifferentToken(): void
    {
        $extension = $this->extension(10);

        self::assertNotSame(
            $this->tokens->forUserAndExtension($this->user(1), $extension),
            $this->tokens->forUserAndExtension($this->user(2), $extension),
        );
    }

    /**
     * Otherwise proving control of one repository would silently claim every other
     * extension the same person happens to look at.
     */
    public function testADifferentExtensionGetsADifferentToken(): void
    {
        $user = $this->user(1);

        self::assertNotSame(
            $this->tokens->forUserAndExtension($user, $this->extension(10)),
            $this->tokens->forUserAndExtension($user, $this->extension(11)),
        );
    }

    public function testADifferentSecretGetsADifferentToken(): void
    {
        $other = new ProofToken('a-different-secret');

        self::assertNotSame(
            $this->tokens->forUserAndExtension($this->user(1), $this->extension(10)),
            $other->forUserAndExtension($this->user(1), $this->extension(10)),
        );
    }

    /**
     * Real files pick up trailing newlines, CRLF from Windows editors, and the
     * occasional explanatory comment somebody added. None of that should fail a
     * maintainer who did the work correctly.
     */
    public function testTheTokenIsFoundDespiteSurroundingNoise(): void
    {
        $user = $this->user(1);
        $extension = $this->extension(10);
        $token = $this->tokens->forUserAndExtension($user, $extension);

        foreach ([
            $token,
            $token."\n",
            "\r\n".$token."\r\n",
            "extdir-ownership-verification\n".$token."\n",
            "# added by CI\n".$token,
            '   '.$token.'   ',
        ] as $body) {
            self::assertTrue($this->tokens->matches($body, $user, $extension), var_export($body, true));
        }
    }

    public function testAnotherPersonsTokenDoesNotMatch(): void
    {
        $extension = $this->extension(10);
        $theirs = $this->tokens->forUserAndExtension($this->user(2), $extension);

        self::assertFalse($this->tokens->matches($theirs, $this->user(1), $extension));
    }

    public function testAnEmptyOrIrrelevantFileDoesNotMatch(): void
    {
        $user = $this->user(1);
        $extension = $this->extension(10);

        self::assertFalse($this->tokens->matches('', $user, $extension));
        self::assertFalse($this->tokens->matches('   ', $user, $extension));
        self::assertFalse($this->tokens->matches('<!DOCTYPE html><title>404</title>', $user, $extension));
    }

    /**
     * The file body is what the instructions tell people to paste, so it has to
     * contain the token it claims to.
     */
    public function testTheFileContentsContainTheToken(): void
    {
        $user = $this->user(1);
        $extension = $this->extension(10);

        self::assertTrue($this->tokens->matches(
            $this->tokens->fileContents($user, $extension),
            $user,
            $extension,
        ));
    }

    private function user(int $id): User
    {
        $user = new User($id, 'user'.$id);
        $this->setId($user, $id);

        return $user;
    }

    private function extension(int $id): Extension
    {
        $extension = new Extension(
            new Vendor('acme', 'acme'),
            'acme/plugin-'.$id,
            'acme-plugin-'.$id,
            'Plugin '.$id,
        );
        $this->setId($extension, $id);

        return $extension;
    }

    /**
     * Ids are assigned by Doctrine on flush, and these objects never see a database.
     */
    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
