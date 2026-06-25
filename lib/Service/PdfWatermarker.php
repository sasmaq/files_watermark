<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use setasign\Fpdi\Tcpdf\Fpdi;
use TCPDF;

class PdfWatermarker {

    public function apply(string $sourcePath, string $destPath, WatermarkConfig $config, array $placeholders): void {
        $pdf = new Fpdi('P', 'pt');
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);

        $pageCount = $pdf->setSourceFile($sourcePath);

        for ($page = 1; $page <= $pageCount; $page++) {
            $tplIdx = $pdf->importPage($page);
            $size   = $pdf->getTemplateSize($tplIdx);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tplIdx);

            if (in_array($config->getType(), ['text', 'combined'], true)) {
                $this->applyTextOverlay($pdf, $config, $placeholders, $size['width'], $size['height']);
            }

            if (in_array($config->getType(), ['image', 'combined'], true) && $config->getImagePath()) {
                $this->applyImageOverlay($pdf, $config, $size['width'], $size['height']);
            }
        }

        $pdf->Output($destPath, 'F');
    }

    private function applyTextOverlay(Fpdi $pdf, WatermarkConfig $config, array $placeholders, float $width, float $height): void {
        $text   = $this->resolvePlaceholders($config->getTextTemplate() ?? '{username} {date}', $placeholders);
        $color  = $this->hexToRgb($config->getColor());
        $alpha  = round($config->getOpacity() / 100, 2);

        $pdf->SetFont('helvetica', 'B', $config->getFontSize());
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->SetAlpha($alpha);

        $angle    = -$config->getRotation();
        $stepX    = max(120, $config->getFontSize() * 6);
        $stepY    = max(80, $config->getFontSize() * 4);

        for ($x = -$stepX; $x < $width + $stepX; $x += $stepX) {
            for ($y = -$stepY; $y < $height + $stepY; $y += $stepY) {
                $pdf->StartTransform();
                $pdf->Rotate($angle, $x + $stepX / 2, $y + $stepY / 2);
                $pdf->SetXY($x, $y);
                $pdf->Cell($stepX, $stepY, $text, 0, 0, 'C');
                $pdf->StopTransform();
            }
        }

        $pdf->SetAlpha(1);
    }

    private function applyImageOverlay(Fpdi $pdf, WatermarkConfig $config, float $width, float $height): void {
        $imagePath = $config->getImagePath();
        if (!$imagePath || !file_exists($imagePath)) {
            return;
        }

        $alpha = round($config->getOpacity() / 100, 2);
        $pdf->SetAlpha($alpha);

        $imgW = $width * 0.3;
        $imgH = $imgW * 0.5;
        $x    = ($width - $imgW) / 2;
        $y    = ($height - $imgH) / 2;
        $pdf->Image($imagePath, $x, $y, $imgW, $imgH, '', '', '', false, 300, '', false, false, 0);

        $pdf->SetAlpha(1);
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
