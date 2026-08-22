<?php

declare(strict_types=1);

namespace App\Ui\Image;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The 1200x630 image that appears when a link is shared.
 *
 * Drawn rather than photographed, because what is worth showing about an extension
 * is its facts: which Shopware versions it declares, what its licence is, whether
 * anyone still maintains it. A stock illustration would say nothing and a screenshot
 * of a package listing would say less.
 *
 * Rendered on demand and cached on disk. The cache key includes the values drawn, so
 * a crawl that changes a compatibility range or a maintenance status invalidates the
 * image by construction rather than by anyone remembering to clear it.
 *
 * The font is resolved from a list of candidates because the same DejaVu Sans lives
 * at a different path on the development machine and on the server. A missing font
 * degrades to a text-free card rather than a 500, a social preview is never worth
 * an error page.
 */
final class SocialCard
{
    private const int WIDTH = 1200;
    private const int HEIGHT = 630;
    private const int MARGIN = 80;

    private const array FONT_CANDIDATES = [
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/liberation/LiberationSans-Regular.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    ];

    private int $titleSize = 60;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/og')]
        private readonly string $cacheDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $facts label => value, drawn in order
     */
    public function render(string $eyebrow, string $title, string $subtitle, array $facts): ?string
    {
        if (!class_exists(\Imagick::class)) {
            $this->logger->warning('Imagick is unavailable, so no social card was drawn.');

            return null;
        }

        $path = $this->cacheDir.'/'.$this->keyFor($eyebrow, $title, $subtitle, $facts).'.png';

        if (is_file($path)) {
            return $path;
        }

        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0o775, true) && !is_dir($this->cacheDir)) {
            return null;
        }

        try {
            $this->draw($path, $eyebrow, $title, $subtitle, $facts);
        } catch (\ImagickException $e) {
            $this->logger->warning('Social card could not be drawn', ['error' => $e->getMessage()]);

            return null;
        }

        return is_file($path) ? $path : null;
    }

    /**
     * @param array<string, string> $facts
     */
    private function draw(string $path, string $eyebrow, string $title, string $subtitle, array $facts): void
    {
        $image = new \Imagick();
        $image->newImage(self::WIDTH, self::HEIGHT, new \ImagickPixel('#0d0d0f'));
        $image->setImageFormat('png');

        // A hairline in the accent, so the card is recognisable as ours at thumbnail
        // size where no text is readable.
        $bar = new \ImagickDraw();
        $bar->setFillColor(new \ImagickPixel('#e8895a'));
        $bar->rectangle(0, 0, self::WIDTH, 8);
        $image->drawImage($bar);

        $font = $this->font();

        if (null === $font) {
            $image->writeImage($path);
            $image->clear();

            return;
        }

        $this->text($image, $font, $eyebrow, self::MARGIN, 120, 26, '#7e7e88');

        // Measured rather than estimated. The first version guessed a character
        // width, and "Open-source Shopware 6 extensions" ran off the right edge of
        // the canvas, on the card that represents the whole site.
        $y = 200;
        foreach ($this->fit($image, $font, $title, 60) as $line) {
            $this->text($image, $font, $line, self::MARGIN, $y, $this->titleSize, '#f2f2f3');
            $y += 76;
        }

        $this->text($image, $font, $subtitle, self::MARGIN, $y + 30, 30, '#a2a2ab');

        // Each column advances by the width of what it actually holds. A fixed
        // gutter put "6.3 6.4 6.5 6.6 6.7" straight through the licence beside it.
        $x = self::MARGIN;
        foreach ($facts as $label => $value) {
            $this->text($image, $font, strtoupper($label), $x, 500, 22, '#7e7e88');
            $this->text($image, $font, $value, $x, 545, 34, '#f2f2f3');

            $x += (int) max(
                $this->widthOf($image, $font, strtoupper($label), 22),
                $this->widthOf($image, $font, $value, 34),
            ) + 70;

            // Nothing may run off the edge, however many facts are passed.
            if ($x > self::WIDTH - self::MARGIN) {
                break;
            }
        }

        $this->text($image, $font, 'extdir.com', self::MARGIN, self::HEIGHT - 48, 24, '#7e7e88');

        $image->writeImage($path);
        $image->clear();
    }

    /**
     * Breaks a heading into lines that genuinely fit, shrinking the type if two
     * lines are still not enough.
     *
     * @return list<string>
     */
    private function fit(\Imagick $image, string $font, string $text, int $size): array
    {
        $available = self::WIDTH - (self::MARGIN * 2);

        for ($points = $size; $points >= 34; $points -= 4) {
            $lines = $this->wrapToWidth($image, $font, $text, $points, $available);

            if (\count($lines) <= 2) {
                $this->titleSize = $points;

                return $lines;
            }
        }

        $this->titleSize = 34;

        return \array_slice($this->wrapToWidth($image, $font, $text, 34, $available), 0, 2);
    }

    /**
     * @return list<string>
     */
    private function wrapToWidth(\Imagick $image, string $font, string $text, int $points, int $available): array
    {
        $draw = new \ImagickDraw();
        $draw->setFont($font);
        $draw->setFontSize($points);

        $lines = [];
        $current = '';

        foreach (explode(' ', $text) as $word) {
            $candidate = '' === $current ? $word : $current.' '.$word;
            $metrics = $image->queryFontMetrics($draw, $candidate);

            if ($metrics['textWidth'] > $available && '' !== $current) {
                $lines[] = $current;
                $current = $word;
                continue;
            }

            $current = $candidate;
        }

        if ('' !== $current) {
            $lines[] = $current;
        }

        return $lines;
    }

    private function text(\Imagick $image, string $font, string $text, int $x, int $y, int $size, string $colour): void
    {
        $draw = new \ImagickDraw();
        $draw->setFont($font);
        $draw->setFontSize($size);
        $draw->setFillColor(new \ImagickPixel($colour));
        $image->annotateImage($draw, $x, $y, 0, $text);
    }

    private function widthOf(\Imagick $image, string $font, string $text, int $size): float
    {
        $draw = new \ImagickDraw();
        $draw->setFont($font);
        $draw->setFontSize($size);

        return $image->queryFontMetrics($draw, $text)['textWidth'];
    }

    private function font(): ?string
    {
        foreach (self::FONT_CANDIDATES as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        $this->logger->warning('No usable font for social cards', ['looked_in' => self::FONT_CANDIDATES]);

        return null;
    }

    /**
     * Everything drawn goes into the key, so a crawl that changes a compatibility
     * range or a maintenance status invalidates the card by construction. Nothing
     * has to remember to clear a cache.
     */
    private function keyFor(mixed ...$parts): string
    {
        return substr(hash('sha256', implode('|', array_map(
            static fn (mixed $p): string => \is_array($p) ? serialize($p) : (string) $p,
            $parts,
        ))), 0, 32);
    }
}
