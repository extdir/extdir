<?php

declare(strict_types=1);

namespace App\Ui\Badge;

/**
 * Renders a shields-style SVG badge.
 *
 * Hand-built rather than proxied to shields.io, for the same reason the fonts are
 * self-hosted: a badge embedded in a maintainer's README is a request every reader
 * of that README makes, and routing those through a third party would hand someone
 * else a log of who reads which Shopware repository. It also means the badge cannot
 * break because an external service changed its API.
 *
 * Text width is estimated rather than measured, there is no font metric library
 * here, and adding one for two short strings would be absurd. The estimate feeds
 * `textLength`, which makes the renderer fit the text to the box we reserved, so a
 * slightly wrong guess produces very slightly tighter or looser letter spacing
 * rather than text spilling out of the pill.
 */
final class CompatibilityBadge
{
    private const int HEIGHT = 20;
    private const int PADDING = 6;

    /** Verdana 11px averages a shade over 6px per character across mixed case. */
    private const float CHAR_WIDTH = 6.2;

    public function render(string $label, string $message, string $colour): string
    {
        $labelWidth = $this->widthOf($label);
        $messageWidth = $this->widthOf($message);
        $total = $labelWidth + $messageWidth;

        $labelCentre = $labelWidth / 2;
        $messageCentre = $labelWidth + ($messageWidth / 2);
        $h = self::HEIGHT;

        // Text is drawn twice: once in black at 30% opacity one pixel lower, then in
        // white on top. That is the shields convention and it is what stops light
        // text vanishing against a mid-tone background on a badly calibrated screen.
        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" width="{$total}" height="{$h}" role="img" aria-label="{$label}: {$message}">
              <title>{$label}: {$message}</title>
              <linearGradient id="s" x2="0" y2="100%">
                <stop offset="0" stop-color="#bbb" stop-opacity=".1"/>
                <stop offset="1" stop-opacity=".1"/>
              </linearGradient>
              <clipPath id="r"><rect width="{$total}" height="{$h}" rx="3" fill="#fff"/></clipPath>
              <g clip-path="url(#r)">
                <rect width="{$labelWidth}" height="{$h}" fill="#3a3a3a"/>
                <rect x="{$labelWidth}" width="{$messageWidth}" height="{$h}" fill="{$colour}"/>
                <rect width="{$total}" height="{$h}" fill="url(#s)"/>
              </g>
              <g fill="#fff" text-anchor="middle" font-family="Verdana,DejaVu Sans,Geneva,sans-serif" font-size="11">
                <text x="{$labelCentre}" y="15" fill="#010101" fill-opacity=".3" textLength="{$this->textLength($label)}">{$label}</text>
                <text x="{$labelCentre}" y="14" textLength="{$this->textLength($label)}">{$label}</text>
                <text x="{$messageCentre}" y="15" fill="#010101" fill-opacity=".3" textLength="{$this->textLength($message)}">{$message}</text>
                <text x="{$messageCentre}" y="14" textLength="{$this->textLength($message)}">{$message}</text>
              </g>
            </svg>
            SVG;
    }

    private function widthOf(string $text): int
    {
        return (int) round($this->textLength($text) + (self::PADDING * 2));
    }

    private function textLength(string $text): float
    {
        return round(mb_strlen($text) * self::CHAR_WIDTH, 1);
    }
}
