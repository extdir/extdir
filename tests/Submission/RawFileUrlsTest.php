<?php

declare(strict_types=1);

namespace App\Tests\Submission;

use App\Catalog\Enum\SourceHost;
use App\Submission\ProofFile\RawFileUrls;
use PHPUnit\Framework\TestCase;

/**
 * The URL shapes are the whole substance of this class, and getting one wrong fails
 * silently: a maintainer publishes the file correctly, the fetch 404s, and the
 * message tells them their file is missing. So each forge in the real corpus is
 * pinned to the path it actually serves.
 */
final class RawFileUrlsTest extends TestCase
{
    private RawFileUrls $urls;

    protected function setUp(): void
    {
        $this->urls = new RawFileUrls();
    }

    public function testGitLabUsesTheDashRawPath(): void
    {
        $candidates = $this->urls->candidates(
            'https://gitlab.com/acme/plugin',
            SourceHost::GitLab,
            'extdir-verification.txt',
        );

        self::assertContains('https://gitlab.com/acme/plugin/-/raw/main/extdir-verification.txt', $candidates);
        self::assertContains('https://gitlab.com/acme/plugin/-/raw/master/extdir-verification.txt', $candidates);
    }

    /**
     * Groups nest arbitrarily deep on GitLab, and a third of the GitLab-hosted
     * extensions here are on self-hosted instances. Splitting the path on the first
     * slash would build a URL for a project that does not exist.
     */
    public function testASelfHostedGitLabWithNestedGroupsKeepsTheWholePath(): void
    {
        $candidates = $this->urls->candidates(
            'https://gitlab.jonathan-martz.de/fyrst/shopware/OrderStates',
            SourceHost::GitLab,
            'f.txt',
        );

        self::assertContains(
            'https://gitlab.jonathan-martz.de/fyrst/shopware/OrderStates/-/raw/main/f.txt',
            $candidates,
        );
    }

    public function testGiteaUsesRawBranch(): void
    {
        $candidates = $this->urls->candidates('https://codeberg.org/acme/plugin', SourceHost::Gitea, 'f.txt');

        self::assertContains('https://codeberg.org/acme/plugin/raw/branch/main/f.txt', $candidates);
    }

    /**
     * Bitbucket is 31 of the 42 extensions this mechanism exists for, and it is
     * classified as Other because the enum has no case for it.
     */
    public function testBitbucketIsCoveredByTheOtherShapes(): void
    {
        $candidates = $this->urls->candidates('https://bitbucket.org/acme/plugin', SourceHost::Other, 'f.txt');

        self::assertContains('https://bitbucket.org/acme/plugin/raw/main/f.txt', $candidates);
        self::assertContains('https://bitbucket.org/acme/plugin/raw/master/f.txt', $candidates);
    }

    /**
     * Two hosts in the corpus run software we cannot identify, so an unknown forge
     * gets every shape tried. A wrong guess costs one 404 against a host we were
     * contacting anyway.
     */
    public function testAnUnknownForgeGetsEveryShape(): void
    {
        $candidates = $this->urls->candidates('https://git.schubwerk.com/acme/plugin', SourceHost::Other, 'f.txt');

        self::assertContains('https://git.schubwerk.com/acme/plugin/raw/main/f.txt', $candidates);
        self::assertContains('https://git.schubwerk.com/acme/plugin/-/raw/main/f.txt', $candidates);
        self::assertContains('https://git.schubwerk.com/acme/plugin/raw/branch/main/f.txt', $candidates);
    }

    public function testTheGitSuffixIsStripped(): void
    {
        $candidates = $this->urls->candidates('https://gitlab.com/acme/plugin.git', SourceHost::GitLab, 'f.txt');

        self::assertContains('https://gitlab.com/acme/plugin/-/raw/main/f.txt', $candidates);
    }

    /**
     * A repository URL recorded as http is stale metadata, not an instruction to
     * fetch over plaintext — and SafeFetcher would refuse it anyway.
     */
    public function testFetchingIsAlwaysUpgradedToHttps(): void
    {
        $candidates = $this->urls->candidates('http://gitlab.com/acme/plugin', SourceHost::GitLab, 'f.txt');

        foreach ($candidates as $url) {
            self::assertStringStartsWith('https://', $url);
        }
    }

    public function testUnusableUrlsProduceNoCandidates(): void
    {
        self::assertSame([], $this->urls->candidates(null, SourceHost::GitLab, 'f.txt'));
        self::assertSame([], $this->urls->candidates('', SourceHost::GitLab, 'f.txt'));
        self::assertSame([], $this->urls->candidates('git@gitlab.com:acme/plugin.git', SourceHost::GitLab, 'f.txt'));
        self::assertSame([], $this->urls->candidates('https://gitlab.com', SourceHost::GitLab, 'f.txt'));
    }
}
