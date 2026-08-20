<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Catalog\Enum\DiscoverySource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The command the listing hands over when somebody presses Copy.
 *
 * It used to be `composer require <package>` for every row. For the extensions
 * Packagist does not carry — the ones the directory exists to surface — that command
 * fails with "could not be found in any version", and the detail page said so in as
 * many words while the listing kept offering it.
 */
final class InstallCommandTest extends KernelTestCase
{
    public function testAPackagistExtensionCopiesTheOneLiner(): void
    {
        $command = $this->copiedCommand($this->extension(DiscoverySource::Packagist));

        self::assertSame('composer require acme/widget', $command);
    }

    public function testAnExtensionMissingFromPackagistCopiesSomethingThatWorks(): void
    {
        $command = $this->copiedCommand($this->extension(DiscoverySource::GitHubTopic));

        $lines = explode("\n", $command);

        self::assertCount(2, $lines, 'A bare require cannot resolve this package.');
        self::assertStringStartsWith('composer config repositories.extdir composer http', $lines[0]);
        self::assertStringEndsWith('/repo', $lines[0], 'Composer appends /packages.json itself.');
        self::assertSame('composer require acme/widget', $lines[1]);
    }

    /**
     * The repository line must be readable in what gets pasted. It adds a third-party
     * repository to somebody's project, and that is not a thing to hide behind a
     * button labelled Copy.
     */
    public function testTheRepositoryLineIsVisibleInThePastedText(): void
    {
        $command = $this->copiedCommand($this->extension(DiscoverySource::Submitted));

        self::assertStringContainsString('composer config repositories.extdir', $command);
    }

    private function extension(DiscoverySource $source): Extension
    {
        $extension = new Extension(new Vendor('acme', 'acme'), 'acme/widget', 'acme-widget', 'Acme Widget');
        $extension->forceDiscoverySource($source);

        return $extension;
    }

    /**
     * Reads the value out of the rendered attribute rather than rebuilding it, so the
     * test fails if the template and the intent drift apart.
     */
    private function copiedCommand(Extension $extension): string
    {
        self::bootKernel();

        $twig = self::getContainer()->get(Environment::class);
        $html = $twig->render('catalog/_row.html.twig', [
            'extension' => $extension,
            'shopwareVersions' => [],
            'supported' => [],
            'compact' => false,
        ]);

        preg_match('/data-clipboard-text="([^"]*)"/s', $html, $matches);
        $copied = $matches[1] ?? null;

        self::assertIsString($copied, 'The row rendered no copy target.');

        return html_entity_decode($copied, \ENT_QUOTES, 'UTF-8');
    }
}
