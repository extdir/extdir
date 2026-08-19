<?php

declare(strict_types=1);

namespace App\Ui\Twig;

use App\Catalog\Entity\Extension;
use App\Ui\Media\IconUrl;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes forge media to templates without letting a template build a URL.
 */
final class MediaExtension extends AbstractExtension
{
    public function __construct(private readonly IconUrl $icons)
    {
    }

    /**
     * Collected at crawl time and already restricted to hosts the extension's own
     * forge serves, so the template needs no filtering of its own.
     *
     * @return list<string>
     */
    private static function gallery(Extension $extension): array
    {
        return $extension->getGalleryImages();
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('extension_icon', $this->icons->forExtension(...)),
            new TwigFunction('extension_icon_host', $this->icons->hostFor(...)),
            new TwigFunction('extension_monogram', $this->icons->monogramFor(...)),
            new TwigFunction('extension_gallery', self::gallery(...)),
        ];
    }
}
