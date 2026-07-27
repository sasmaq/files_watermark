<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use Psr\Log\LoggerInterface;
// The TCPDF-backed variant: plain setasign\Fpdi\Fpdi extends FPDF, which this
// project does not depend on.
use setasign\Fpdi\Tcpdf\Fpdi;
use TCPDF;

/**
 * Rebuilds a watermarked PDF as a sequence of page images, so the watermark is
 * fused into the pixels instead of sitting in a separate, removable content
 * stream.
 *
 * What this does and does not buy: an overlay can be dropped with `qpdf` or
 * `mutool`, or selected and deleted in some editors. Rasterising removes that
 * seam — there is no overlay left, only pixels. It makes removal *impractical*,
 * not impossible: cropping, inpainting or OCR-and-retypeset all still work. It
 * raises cost; it is not a cryptographic guarantee.
 *
 * The rebuild leg is TCPDF, already a dependency. Only page→bitmap needs an
 * external renderer, and that is `pdftoppm` from poppler-utils — in RHEL 9's
 * AppStream, so no EPEL and no Ghostscript. Imagick is deliberately not a
 * fallback: it is EPEL-only on RHEL 9 and its PDF delegate *is* Ghostscript,
 * disabled by `policy.xml` by default over the Ghostscript CVEs.
 */
class PdfFlattener {

	/** Rasteriser binary, looked up on PATH rather than assumed from the distro. */
	public const RENDERER = 'pdftoppm';

	public const DEFAULT_DPI = 150;
	public const MIN_DPI = 72;
	public const MAX_DPI = 600;

	/**
	 * Ceilings on the work one flatten may do, in the spirit of
	 * {@see \OCA\FilesWatermark\Dav\ZipInterceptorPlugin}'s limits. Rasterising
	 * costs CPU and temp disk per page, so an unbounded document would otherwise
	 * be an unbounded request.
	 */
	private const MAX_PAGES = 200;
	private const MAX_BYTES = 268435456; // 256 MiB of source PDF

	/** Per-request memo. The probe only stats PATH, so this never shells out. */
	private ?string $binary = null;
	private bool $probed = false;
	private bool $loggedUnavailable = false;

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether this host can flatten at all. The admin UI hides the setting when
	 * this is false, and {@see WatermarkService} forces the setting off, so a
	 * missing binary can never silently yield an unflattened PDF.
	 */
	public function isAvailable(): bool {
		return $this->resolveBinary() !== null;
	}

	/**
	 * Rasterise every page of `$sourcePath` and write the rebuilt PDF to
	 * `$destPath`.
	 *
	 * Fails closed. Every error path throws rather than leaving the caller with
	 * the unflattened file, because that file is precisely the removable-overlay
	 * version the setting exists to avoid handing out.
	 *
	 * @throws \RuntimeException if the renderer is missing, the document exceeds
	 *                           the ceilings, or any page fails to render
	 */
	public function flatten(string $sourcePath, string $destPath, int $dpi = self::DEFAULT_DPI): void {
		$binary = $this->resolveBinary();
		if ($binary === null) {
			throw new \RuntimeException(
				'Cannot flatten PDF: ' . self::RENDERER . ' is not installed (package poppler-utils).',
			);
		}

		$bytes = @filesize($sourcePath);
		if ($bytes === false) {
			throw new \RuntimeException('Cannot flatten PDF: the watermarked file is unreadable.');
		}
		if ($bytes > self::MAX_BYTES) {
			throw new \RuntimeException(
				sprintf('Cannot flatten PDF: %d bytes exceeds the %d byte ceiling.', $bytes, self::MAX_BYTES),
			);
		}

		$dpi = max(self::MIN_DPI, min(self::MAX_DPI, $dpi));

		// Page geometry comes from the source so the rebuild is not assumed to be
		// A4 — mixed-size and landscape documents have to survive the round-trip.
		// The reader must use the same unit as the output document below, or every
		// page is rebuilt at 1/2.835 of its size (points read as millimetres).
		$reader = new Fpdi('P', 'pt');
		try {
			$pageCount = $reader->setSourceFile($sourcePath);
		} catch (\Exception $e) {
			throw new \RuntimeException('Cannot flatten PDF: ' . $e->getMessage(), 0, $e);
		}

		if ($pageCount > self::MAX_PAGES) {
			throw new \RuntimeException(
				sprintf('Cannot flatten PDF: %d pages exceeds the %d page ceiling.', $pageCount, self::MAX_PAGES),
			);
		}

		$sizes = [];
		for ($page = 1; $page <= $pageCount; $page++) {
			$sizes[$page] = $reader->getTemplateSize($reader->importPage($page));
		}

		$out = new TCPDF('P', 'pt');
		$out->SetPrintHeader(false);
		$out->SetPrintFooter(false);
		// Without all three the page image is inset by the margins and spills onto
		// a second page, turning every source page into two.
		$out->SetMargins(0, 0, 0);
		$out->SetAutoPageBreak(false);

		$rendered = null;
		try {
			for ($page = 1; $page <= $pageCount; $page++) {
				$size = $sizes[$page];
				$rendered = $this->renderPage($binary, $sourcePath, $page, $dpi);

				$out->AddPage($size['orientation'], [$size['width'], $size['height']]);
				$out->Image(
					$rendered,
					0,
					0,
					$size['width'],
					$size['height'],
					'PNG',
					'',
					'',
					false,
					$dpi,
					'',
					false,
					false,
					0,
				);

				// One page bitmap in memory and on disk at a time, whatever the
				// document's length.
				unlink($rendered);
				$rendered = null;
			}

			$out->Output($destPath, 'F');
		} catch (\Throwable $e) {
			if ($rendered !== null && file_exists($rendered)) {
				unlink($rendered);
			}
			throw $e;
		}
	}

	/**
	 * Rasterise one page to PNG and return its path. PNG keeps glyph edges exact;
	 * it is also, with JPEG, one of the only two formats TCPDF's `Image()` handles
	 * reliably — the same constraint that ruled SVG out of the logo upload.
	 */
	private function renderPage(string $binary, string $sourcePath, int $page, int $dpi): string {
		$prefix = tempnam(sys_get_temp_dir(), 'wm_flat_');
		if ($prefix === false) {
			throw new \RuntimeException('Cannot flatten PDF: no temp file available for the page render.');
		}
		// pdftoppm appends its own extension, so the prefix must not be the target.
		unlink($prefix);
		$expected = $prefix . '.png';

		$command = sprintf(
			'%s -png -r %d -f %d -l %d -singlefile %s %s 2>&1',
			escapeshellcmd($binary),
			$dpi,
			$page,
			$page,
			escapeshellarg($sourcePath),
			escapeshellarg($prefix),
		);

		$output = [];
		$status = 0;
		exec($command, $output, $status);

		if ($status !== 0 || !file_exists($expected)) {
			if (file_exists($expected)) {
				unlink($expected);
			}
			throw new \RuntimeException(sprintf(
				'Cannot flatten PDF: rendering page %d failed (exit %d) %s',
				$page,
				$status,
				trim(implode(' ', $output)),
			));
		}

		return $expected;
	}

	/**
	 * Absolute path to the renderer, or null when this host has none.
	 *
	 * Searches PATH rather than trusting a distro layout: production is RHEL 9
	 * (`/usr/bin/pdftoppm` from AppStream) while the dev containers are Debian, so
	 * the same binary arrives by a different package manager.
	 */
	private function resolveBinary(): ?string {
		if ($this->probed) {
			return $this->binary;
		}
		$this->probed = true;

		$path = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
		foreach (explode(PATH_SEPARATOR, $path) as $dir) {
			if ($dir === '') {
				continue;
			}
			$candidate = rtrim($dir, '/') . '/' . self::RENDERER;
			if (is_file($candidate) && is_executable($candidate)) {
				$this->binary = $candidate;
				return $this->binary;
			}
		}

		// The admin sees no control at all when this happens, by design — so the
		// only place the reason can surface is the log.
		if (!$this->loggedUnavailable) {
			$this->loggedUnavailable = true;
			$this->logger->info(
				'files_watermark: ' . self::RENDERER . ' not found on PATH; PDF flattening is unavailable. '
				. 'Install poppler-utils to enable it.',
				['app' => 'files_watermark'],
			);
		}

		return null;
	}
}
