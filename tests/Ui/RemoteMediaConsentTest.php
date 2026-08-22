<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Ui\Media\IconUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The site tells visitors it makes no third-party requests, and the privacy policy
 * says so in both languages. Extension icons are the one thing that could make that
 * false, because each one lives on the forge that hosts its extension.
 *
 * The guarantee is server-side and therefore testable without a browser: a URL that
 * never reaches the document in a *loading* position cannot be fetched, whatever the
 * JavaScript does. So these assertions are about the shape of the rendered HTML.
 *
 * The partial is rendered directly with extensions built in memory rather than
 * requested through the catalogue, because the test database holds no extensions on
 * purpose, a test that only passes once somebody has seeded data is a test that
 * fails on a clean clone. Rendering the template is also the stricter check: it
 * exercises the gate on every forge, including the ones the local corpus barely has.
 *
 * A failure here is a privacy-policy violation before it is a bug.
 */
final class RemoteMediaConsentTest extends KernelTestCase
{
    /**
     * Attributes the browser acts on by itself. `data-*` is deliberately absent:
     * that is precisely where the URL is parked until somebody consents.
     */
    private const array LOADING_ATTRIBUTES = ['src', 'srcset', 'href', 'poster', 'content', 'style'];

    #[DataProvider('extensionsAcrossForges')]
    public function testTheIconUrlNeverReachesALoadingAttribute(string $repositoryUrl, string $expectedHost): void
    {
        $html = $this->renderIcon($this->extension($repositoryUrl, 'src/Resources/config/plugin.png'));

        foreach (self::LOADING_ATTRIBUTES as $attribute) {
            self::assertDoesNotMatchRegularExpression(
                '/\b'.$attribute.'\s*=\s*"[^"]*'.preg_quote($expectedHost, '/').'/i',
                $html,
                \sprintf('%s appears in a %s attribute, which the browser would fetch without consent.', $expectedHost, $attribute)
            );
        }

        self::assertStringNotContainsString('<img', $html, 'The markup ships an <img>, so the request happens before anyone is asked.');

        // ...but the URL must still be there, parked, or this would pass equally well
        // on a page that had simply lost the feature.
        self::assertStringContainsString('data-remote-media-url="https://'.$expectedHost, $html);
    }

    /**
     * The monogram is what makes refusing cost nothing. If it stopped rendering
     * server-side, declining would leave a page full of holes and the "no" would
     * carry a penalty, which is how consent stops being freely given.
     */
    #[DataProvider('extensionsAcrossForges')]
    public function testAMarkRendersWithoutConsent(string $repositoryUrl, string $expectedHost): void
    {
        $html = $this->renderIcon($this->extension($repositoryUrl, 'src/Resources/config/plugin.png'));

        self::assertStringContainsString('ext-icon-slot', $html);
        self::assertMatchesRegularExpression('/>\s*[A-Z0-9]{1,2}\s*</u', $html, 'No initials rendered.');
        self::assertStringNotContainsString($expectedHost.'"', $html, 'The host leaked outside the data attribute.');
    }

    /**
     * An extension with no icon in its composer.json must produce no slot to reveal
     * at all, not an empty one that would resolve to a 404 on the forge.
     */
    public function testAnExtensionWithoutAnIconExposesNothingToLoad(): void
    {
        $html = $this->renderIcon($this->extension('https://github.com/acme/sw-widget', null));

        self::assertStringNotContainsString('data-remote-media-url', $html);
        self::assertStringContainsString('ext-icon-slot', $html);
    }

    /**
     * An icon nobody has confirmed exists is not offered at all.
     *
     * This is what stops consent being spent on requests that can only 404: roughly
     * a third of the catalogue's icon paths are the convention
     * `src/Resources/config/plugin.png` filled in when composer.json declares none,
     * and the file is often not there.
     */
    public function testAnUnverifiedIconIsNeverOffered(): void
    {
        $extension = $this->extension('https://github.com/acme/sw-widget', 'src/Resources/config/plugin.png', verified: false);

        self::assertNull((new IconUrl())->forExtension($extension));
        self::assertStringNotContainsString('data-remote-media-url', $this->renderIcon($extension));

        // ...and it still shows a mark, so the row does not go blank.
        self::assertStringContainsString('ext-icon-slot', $this->renderIcon($extension));
    }

    /**
     * An icon that has moved stops being offered rather than being offered stale.
     */
    public function testClearingVerificationWithdrawsTheUrl(): void
    {
        $extension = $this->extension('https://github.com/acme/sw-widget', 'src/Resources/config/plugin.png');
        self::assertNotNull((new IconUrl())->forExtension($extension));

        $extension->setIconVerifiedAt(null);
        self::assertNull((new IconUrl())->forExtension($extension));
    }

    /**
     * The branch comes from the crawler, not from a guess. `main` was measured
     * against the corpus and found the icon for 23 of 40 sampled repositories;
     * the catalogue also holds `develop`, `trunk`, `stable` and `main_65`.
     */
    public function testTheStoredBranchIsUsedRatherThanAGuess(): void
    {
        $extension = $this->extension('https://github.com/acme/sw-widget', 'src/Resources/config/plugin.png');
        $extension->setDefaultBranch('develop');

        self::assertSame(
            'https://raw.githubusercontent.com/acme/sw-widget/develop/src/Resources/config/plugin.png',
            (new IconUrl())->forExtension($extension)
        );
    }

    /**
     * The gallery is the case where a leak would be worst: screenshots come from
     * READMEs, and sampled READMEs link to imgur, giphy, cloudinary and vendors' own
     * marketing servers. None of that may reach a loading attribute either.
     */
    public function testGalleryUrlsAreInertUntilConsent(): void
    {
        $extension = $this->extension('https://github.com/acme/sw-widget', 'src/Resources/config/plugin.png');
        $extension->setGalleryImages([
            'https://raw.githubusercontent.com/acme/sw-widget/main/docs/one.png',
            'https://raw.githubusercontent.com/acme/sw-widget/main/docs/two.png',
        ]);

        $html = $this->renderGallery($extension);

        // The lightbox ships an <img> with no src, inert, since a source-less image
        // makes no request. What must never appear is an img the browser would fetch,
        // so the assertion is about the attribute rather than the tag.
        self::assertDoesNotMatchRegularExpression('/<img[^>]+\bsrc\s*=/i', $html);
        self::assertStringContainsString('hidden', $html, 'The section must ship hidden.');
        self::assertSame(2, substr_count($html, 'data-remote-media-url='));

        foreach (['src', 'srcset', 'poster', 'content', 'style'] as $attribute) {
            self::assertDoesNotMatchRegularExpression(
                '/\b'.$attribute.'\s*=\s*"[^"]*raw\.githubusercontent/i',
                $html,
                \sprintf('A screenshot URL reached a %s attribute.', $attribute)
            );
        }

        // href is the exception and is deliberate: it is a link the reader chooses to
        // follow, not something the browser fetches on its own.
        self::assertStringContainsString('href="https://raw.githubusercontent.com/acme/sw-widget/main/docs/one.png"', $html);
    }

    /**
     * The lightbox is an enhancement, not the mechanism. Each thumbnail stays a real
     * link to the original, so middle-click, "open in new tab" and a browser with no
     * JavaScript all keep working, the controller intercepts the plain click only.
     */
    public function testThumbnailsRemainRealLinks(): void
    {
        $extension = $this->extension('https://github.com/acme/sw-widget', 'src/Resources/config/plugin.png');
        $extension->setGalleryImages(['https://raw.githubusercontent.com/acme/sw-widget/main/docs/one.png']);

        $html = $this->renderGallery($extension);

        self::assertStringContainsString('href="https://raw.githubusercontent.com/acme/sw-widget/main/docs/one.png"', $html);
        self::assertStringContainsString('data-action="lightbox#open"', $html);
        self::assertStringContainsString('rel="noopener noreferrer nofollow"', $html);
    }

    /**
     * No screenshots means no heading, no empty strip, and nothing to reveal.
     */
    public function testAnExtensionWithoutScreenshotsRendersNoGallery(): void
    {
        $html = $this->renderGallery($this->extension('https://github.com/acme/sw-widget', null));

        self::assertSame('', trim($html));
    }

    /**
     * Every derived URL must be https. The forge host comes from Packagist, which is
     * untrusted input, and an http:// icon would downgrade the reader's connection.
     */
    #[DataProvider('extensionsAcrossForges')]
    public function testEveryDerivedUrlIsHttps(string $repositoryUrl, string $expectedHost): void
    {
        $url = (new IconUrl())->forExtension($this->extension($repositoryUrl, 'src/Resources/config/plugin.png'));

        self::assertIsString($url);
        self::assertStringStartsWith('https://', $url);
        self::assertSame($expectedHost, parse_url($url, \PHP_URL_HOST));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function extensionsAcrossForges(): iterable
    {
        yield 'github' => ['https://github.com/acme/sw-widget', 'raw.githubusercontent.com'];
        yield 'gitlab' => ['https://gitlab.com/acme/group/sw-widget', 'gitlab.com'];
        yield 'gitea' => ['https://codeberg.org/acme/sw-widget', 'codeberg.org'];
        yield 'self-hosted' => ['https://git.example.de/acme/sw-widget', 'git.example.de'];
    }

    private function extension(string $repositoryUrl, ?string $iconPath, bool $verified = true): Extension
    {
        $extension = new Extension(new Vendor('acme', 'acme'), 'acme/sw-widget', 'acme-sw-widget', 'Acme Widget');
        $extension->setRepositoryUrl($repositoryUrl);
        $extension->setIconPath($iconPath);
        $extension->setDefaultBranch('main');

        // Most icon paths in the real catalogue are a convention rather than a
        // declaration, so nothing is offered until it has been seen to exist.
        if ($verified) {
            $extension->setIconVerifiedAt(new \DateTimeImmutable());
        }

        return $extension;
    }

    private function renderGallery(Extension $extension): string
    {
        return $this->render('catalog/_gallery.html.twig', $extension);
    }

    private function renderIcon(Extension $extension): string
    {
        self::bootKernel();

        return $this->render('catalog/_icon.html.twig', $extension);
    }

    private function render(string $template, Extension $extension): string
    {
        self::bootKernel();

        $twig = self::getContainer()->get(Environment::class);

        return $twig->render($template, ['extension' => $extension]);
    }
}
