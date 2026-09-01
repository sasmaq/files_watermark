<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use Com\Tecnick\Pdf\Tcpdf;
use Psr\Log\LoggerInterface;

/**
 * Rebuilds a watermarked PDF as a sequence of page images, so the watermark is fused into
 * the pixels instead of sitting in a separate, removable content stream.
 *
 * ---------------------------------------------------------------------------
 * THIS IS THE ONE EXCEPTION TO "NO EXTERNAL BINARIES".
 *
 * Everything else in this app is pure PHP and spawns no processes - see
 * `doc/development.md`. Rasterising a page is the one thing with no pure-PHP substitute
 * worth having, because doing it in-process means bundling a PDF *interpreter*, which is a
 * far larger surface than the watermarking this app exists to do. So this class shells out
 * to `pdftoppm` (poppler-utils) and **nothing else in the app does**.
 *
 * The exception is contained by three rules, and they are what make it affordable:
 *
 *  - **The binary is optional.** {@see isAvailable} probes PATH at runtime rather than
 *    assuming a distro layout - production is RHEL 9 (`dnf install poppler-utils`, in
 *    AppStream, no EPEL) while the dev containers are Debian. A host without it renders
 *    exactly as it did before this class existed.
 *  - **Nothing depends on it.** The admin control is hidden when the probe fails, the API
 *    refuses the setting when the probe fails, and a flatten that fails at render time
 *    falls back to the ordinary overlay-watermarked PDF ({@see WatermarkService}). The
 *    feature is additive from end to end.
 *  - **Only this one command line, and it is fully escaped.** No user-supplied string
 *    reaches the shell: the arguments are two temp paths this app created and two integers
 *    it clamped.
 * ---------------------------------------------------------------------------
 *
 * What it does and does not buy: an overlay can be dropped with `qpdf` or `mutool`, or
 * selected and deleted in some editors. Rasterising removes that seam - there is no overlay
 * left, only pixels. It makes removal *impractical*, not impossible: cropping, inpainting
 * or OCR-and-retypeset all still work. It raises cost; it is not a cryptographic guarantee.
 *
 * It also costs the text layer, and with it selection, copy, search and screen-reader
 * access. That is why the setting is off by default and why the form warns at the point of
 * switching it on.
 *
 * The rebuild leg is tc-lib-pdf, already a dependency. Only page->bitmap needs the external
 * renderer. Imagick is deliberately not a fallback: it is EPEL-only on RHEL 9 and its PDF
 * delegate *is* Ghostscript, disabled by `policy.xml` by default over the Ghostscript CVEs.
 */
class PdfFlattener {

	/** Rasteriser binary, looked up on PATH rather than assumed from the distro. */
	public const RENDERER = 'pdftoppm';

	public const DEFAULT_DPI = 150;
	public const MIN_DPI = 72;
	public const MAX_DPI = 600;

	/**
	 * Ceilings on the work one flatten may do, in the spirit of
	 * {@see \OCA\FilesWatermark\Dav\ZipInterceptorPlugin}'s limits. Rasterising costs CPU
	 * and temp disk per page, and this now runs *per fetch* rather than once per file, so
	 * an unbounded document would be an unbounded request on every download of it.
	 */
	private const MAX_PAGES = 200;
	private const MAX_BYTES = 268435456; // 256 MiB of source PDF

	/**
	 * Memoised for the life of this instance, which is one injection point per request.
	 * Cheap either way - the probe stats PATH and never shells out.
	 */
	private ?string $binary = null;
	private bool $probed = false;
	private bool $loggedUnavailable = false;

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether this host can flatten at all.
	 *
	 * The admin UI omits the setting when this is false and the API refuses it, so an
	 * admin is never offered a control this server could not honour.
	 */
	public function isAvailable(): bool {
		return $this->resolveBinary() !== null;
	}

	/**
	 * Rasterise every page of `$sourcePath` and write the rebuilt PDF to `$destPath`.
	 *
	 * Throws rather than half-writing: on any failure `$destPath` does not exist, and the
	 * caller still holds the overlay-watermarked file it passed in as `$sourcePath`, which
	 * is what it falls back to serving. Nothing here ever writes the source.
	 *
	 * @throws \RuntimeException if the renderer is missing, the document exceeds the
	 *                           ceilings, or any page fails to render
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

		$dpi = self::clampDpi($dpi);

		// Page geometry comes from the source so the rebuild is not assumed to be A4 -
		// mixed-size and landscape documents have to survive the round-trip. Points, so the
		// geometry read here needs no conversion before it is used as the output page size;
		// mixing units rebuilds every page at 1/2.835 of its size (points read as
		// millimetres).
		//
		// A *separate* document from the output one on purpose: importPage registers the
		// source page as a Form XObject, and reusing this instance would carry every one of
		// them into the rebuilt file - the original content the rebuild exists to destroy.
		$reader = new Tcpdf('pt', fileOptions: ['allowedPaths' => $this->allowedPaths($sourcePath)]);
		try {
			$sourceId = $reader->setImportSourceFile($sourcePath);
			$pageCount = $reader->getSourcePageCount($sourceId);
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
			$template = $reader->importPage($sourceId, $page);
			$sizes[$page] = [
				'width' => $reader->toUnit($template->getWidth()),
				'height' => $reader->toUnit($template->getHeight()),
			];
		}
		unset($reader);

		$out = new Tcpdf('pt', fileOptions: ['allowedPaths' => $this->allowedPaths($sourcePath)]);

		try {
			foreach ($sizes as $page => $size) {
				$rendered = $this->renderPage($binary, $sourcePath, $page, $dpi);

				try {
					$out->addPage([
						'format' => '',
						'width' => $size['width'],
						'height' => $size['height'],
						'orientation' => $size['width'] > $size['height'] ? 'L' : 'P',
					]);

					// Explicit width and height rather than derived ones: the bitmap is a
					// render of this very page, so it fills it exactly, and there is no
					// margin or auto page break to inset it the way TCPDF needed guarding
					// against.
					$imageId = $out->image->add($rendered);
					$out->page->addContent($out->image->getSetImage(
						$imageId,
						0,
						0,
						$size['width'],
						$size['height'],
						$size['height'],
					));
				} finally {
					// One page bitmap on disk at a time, whatever the document's length -
					// and none at all left behind when a page fails to be placed.
					unlink($rendered);
				}
			}

			$this->write($out, $destPath);
		} catch (\Throwable $e) {
			if (is_file($destPath)) {
				unlink($destPath);
			}
			throw $e;
		}
	}

	/** The render resolution actually used for `$dpi`, clamped to the supported range. */
	public static function clampDpi(int $dpi): int {
		return max(self::MIN_DPI, min(self::MAX_DPI, $dpi));
	}

	/**
	 * Directories the renderer may read from. Everything in play is a temp copy, and
	 * supplying this replaces the library's defaults rather than adding to them - see the
	 * same method on {@see PdfWatermarker} for why both path forms are listed.
	 *
	 * @return list<string>
	 */
	private function allowedPaths(string $sourcePath): array {
		$paths = [PdfFontPath::directory(), sys_get_temp_dir(), dirname($sourcePath)];

		$resolved = [];
		foreach ($paths as $path) {
			$resolved[] = $path;
			$real = realpath($path);
			if ($real !== false) {
				$resolved[] = $real;
			}
		}

		return array_values(array_unique(array_filter($resolved)));
	}

	/**
	 * tc-lib-pdf hands back a string where TCPDF wrote the file itself. A short write has
	 * to throw: a truncated rebuild is not a PDF, and the caller decides what to serve
	 * instead on the strength of this method having failed.
	 */
	private function write(Tcpdf $pdf, string $destPath): void {
		$raw = $pdf->getOutPDFString();
		if (file_put_contents($destPath, $raw) !== strlen($raw)) {
			if (is_file($destPath)) {
				unlink($destPath);
			}
			throw new \RuntimeException('Cannot flatten PDF: the rebuilt file could not be written.');
		}
	}

	/**
	 * Rasterise one page to PNG and return its path. PNG keeps glyph edges exact, and is a
	 * format every renderer handles without argument - the same reasoning that ruled SVG
	 * out of the logo upload.
	 *
	 * **The only command line in this application.** Both operands are paths this app
	 * created in its own temp directory and both are escaped; `$dpi` and `$page` are ints
	 * that have already been clamped or counted. Nothing a user supplies - filename, watermark
	 * text, display name - reaches it.
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
	 * Absolute path to the renderer, or null when this host cannot run it.
	 *
	 * Two things have to hold, and the second is the one that bites on a hardened server:
	 * the binary has to be on PATH, and PHP has to be allowed to spawn it. `exec` is
	 * commonly listed in `disable_functions`, and an install that has done so gets the
	 * feature hidden rather than an admin toggle that produces a fatal on every download.
	 *
	 * PATH is searched rather than a distro layout trusted: production is RHEL 9
	 * (`/usr/bin/pdftoppm` from AppStream) while the dev containers are Debian, so the same
	 * binary arrives by a different package manager.
	 */
	private function resolveBinary(): ?string {
		if ($this->probed) {
			return $this->binary;
		}
		$this->probed = true;

		if (!self::canSpawnProcesses()) {
			$this->logUnavailable('exec() is disabled in this PHP configuration');
			return null;
		}

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

		$this->logUnavailable(self::RENDERER . ' was not found on PATH; install poppler-utils to enable it');

		return null;
	}

	/** Whether `exec()` exists and this PHP is permitted to call it. */
	private static function canSpawnProcesses(): bool {
		if (!function_exists('exec')) {
			return false;
		}

		$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

		return !in_array('exec', $disabled, true);
	}

	/**
	 * The admin sees no control at all when the probe fails, by design - so the log is the
	 * only place the reason can surface. Once per request, whatever asks.
	 */
	private function logUnavailable(string $reason): void {
		if ($this->loggedUnavailable) {
			return;
		}
		$this->loggedUnavailable = true;

		$this->logger->info(
			'files_watermark: PDF flattening is unavailable - {reason}.',
			['app' => 'files_watermark', 'reason' => $reason],
		);
	}
}
