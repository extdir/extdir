<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * How a listing row is exposed to assistive technology.
 *
 * The rows were a listbox of options, on the reasoning that the keyboard shortcuts
 * move a selection rather than DOM focus. That is a fair description of the
 * interaction and the wrong role: an option may not contain interactive descendants,
 * and every row holds a title link and a copy button. Lighthouse flagged it, and the
 * practical cost is that the row announced as one indivisible choice while the links
 * inside it were not reliably reachable.
 *
 * A row is a record in a list. The selection is still not focus — it is a class, and
 * the row's name goes to a live region the shortcuts controller owns.
 */
final class ListSemanticsTest extends KernelTestCase
{
    public function testARowIsAListItemAndNotAnOption(): void
    {
        $html = $this->renderRow();

        self::assertStringContainsString('role="listitem"', $html);
        self::assertStringNotContainsString('role="option"', $html);
    }

    /**
     * aria-selected belongs to an option. Left on a listitem it is invalid, and it
     * was the attribute the audit named.
     */
    public function testTheRowDoesNotClaimASelectionStateItCannotHold(): void
    {
        self::assertStringNotContainsString('aria-selected', $this->renderRow());
    }

    /**
     * The reason the role was wrong: these are the descendants an option may not have.
     */
    public function testTheRowContainsInteractiveDescendants(): void
    {
        $html = $this->renderRow();

        self::assertStringContainsString('<a ', $html, 'The title is a link.');
        self::assertStringContainsString('<button', $html, 'The copy control is a button.');
    }

    private function renderRow(): string
    {
        self::bootKernel();

        $extension = new Extension(new Vendor('acme', 'acme'), 'acme/widget', 'acme-widget', 'Acme Widget');
        $extension->setRepositoryUrl('https://github.com/acme/widget');

        $twig = self::getContainer()->get(Environment::class);

        return $twig->render('catalog/_row.html.twig', [
            'extension' => $extension,
            'shopwareVersions' => [],
            'supported' => [],
            'compact' => false,
        ]);
    }
}
