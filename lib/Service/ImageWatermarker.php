<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCP\ILogger;

class ImageWatermarker {

    private bool $useImagick;

    public function __construct() {
        $this->useImagick = class_exists('Imagick');
    }

    public function apply(string $sourcePath, string $destPath, WatermarkConfig $config, array $placeholders): void {
        if ($this->useImagick) {
            $this->applyWithImagick($sourcePath, $destPath, $config, $placeholders);
        } else {
            $this->applyWithGd($sourcePath, $destPath, $config, $placeholders);
        }
    }

    private function applyWithImagick(string $sourcePath, string $destPath, WatermarkConfig $config, array $placeholders): void {
        $image  = new \Imagick($sourcePath);
        $width  = $image->getImageWidth();
        $height = $image->getImageHeight();
        $alpha  = $config->getOpacity() / 100;

        if (in_array($config->getType(), ['text', 'combined'], true)) {
            $text      = $this->resolvePlaceholders($config->getTextTemplate() ?? '{username} {date}', $placeholders);
            $color     = $config->getColor();
            $fontSize  = $config->getFontSize();
            $rotation  = $config->getRotation();

            $draw = new \ImagickDraw();
            $draw->setFont('DejaVu-Sans-Bold');
            $draw->setFontSize($fontSize);
            $draw->setFillColor(new \ImagickPixel($color));
            $draw->setFillOpacity($alpha);

            $stepX = max(150, $fontSize * 7);
            $stepY = max(100, $fontSize * 5);

            for ($x = 0; $x < $width + $stepX; $x += $stepX) {
                for ($y = 0; $y < $height + $stepY; $y += $stepY) {
                    $draw->rotate(-$rotation);
                    $draw->annotation($x, $y, $text);
                    $draw->rotate($rotation);
                }
            }

            $image->drawImage($draw);
        }

        if (in_array($config->getType(), ['image', 'combined'], true) && $config->getImagePath() && file_exists($config->getImagePath())) {
            $watermark = new \Imagick($config->getImagePath());
            $wmW = intval($width * 0.3);
            $wmH = intval($watermark->getImageHeight() * ($wmW / $watermark->getImageWidth()));
            $watermark->resizeImage($wmW, $wmH, \Imagick::FILTER_LANCZOS, 1);
            $watermark->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $alpha, \Imagick::CHANNEL_ALPHA);
            $image->compositeImage(
                $watermark,
                \Imagick::COMPOSITE_OVER,
                intval(($width - $wmW) / 2),
                intval(($height - $wmH) / 2)
            );
        }

        $image->writeImage($destPath);
        $image->clear();
    }

    private function applyWithGd(string $sourcePath, string $destPath, WatermarkConfig $config, array $placeholders): void {
        $mime = mime_content_type($sourcePath);
        $src  = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($sourcePath),
            'image/png'  => imagecreatefrompng($sourcePath),
            'image/webp' => imagecreatefromwebp($sourcePath),
            default      => throw new \RuntimeException("Unsupported image type: $mime"),
        };

        $width  = imagesx($src);
        $height = imagesy($src);

        $text     = $this->resolvePlaceholders($config->getTextTemplate() ?? '{username} {date}', $placeholders);
        $color    = $this->hexToRgb($config->getColor());
        $opacity  = intval((1 - $config->getOpacity() / 100) * 127);
        $textColor = imagecolorallocatealpha($src, $color[0], $color[1], $color[2], $opacity);

        $fontSize = max(1, intval($config->getFontSize() / 4));
        $stepX    = max(100, $fontSize * 40);
        $stepY    = max(60, $fontSize * 25);

        for ($x = 0; $x < $width; $x += $stepX) {
            for ($y = 0; $y < $height; $y += $stepY) {
                imagestring($src, $fontSize, $x, $y, $text, $textColor);
            }
        }

        match ($mime) {
            'image/jpeg' => imagejpeg($src, $destPath, 90),
            'image/png'  => imagepng($src, $destPath),
            'image/webp' => imagewebp($src, $destPath, 90),
        };

        imagedestroy($src);
    }

    private function resolvePlaceholders(string $template, array $placeholders): string {
        $search  = array_map(fn($k) => '{' . $k . '}', array_keys($placeholders));
        $replace = array_values($placeholders);
        return str_replace($search, $replace, $template);
    }

    private function hexToRgb(string $hex): array {
        $hex = ltrim($hex, '#');
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
