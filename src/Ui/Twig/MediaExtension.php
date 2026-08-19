<?php

declare(strict_types=1);

namespace App\Ui\Twig;

use App\Ui\Media\IconUrl;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the icon URL to templates without letting a template build it.
 */
final class MediaExtension extends AbstractExtension
{
    public function __construct(private readonly IconUrl $icons)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('extension_icon', $this->icons->forExtension(...)),
            new TwigFunction('extension_icon_host', $this->icons->hostFor(...)),
            new TwigFunction('extension_monogram', $this->icons->monogramFor(...)),
        ];
    }
}
