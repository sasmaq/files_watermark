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
 * Everything else in this app is pure PHP and spawns no processes. Rasterising a page is the
 * one thing with no pure-PHP substitute worth having, because doing it in-process means
 * bundling a PDF *interpreter*, which is a far larger surface than the watermarking this app
 * exists to do. So this class shells out to `pdftoppm` (poppler-utils) and **nothing else in
 * the app does**.
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
 *    reaches the shell: the arguments are two temp paths this app created, a page number it
 *    counted, and a resolution that is a constant of this class.
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

	/**
	 * Resolution every page is rasterised at, in dots per inch.
	 *
	 * Fixed rather than configurable. It was a slider once, and the range it offered was
	 * the problem: the low end produced pages an admin would not have accepted had they
	 * seen one, and the high end multiplied the cost of a feature that already rebuilds
	 * every page on every fetch - at 600 DPI a long scan is a denial of service dressed
	 * as a quality setting. 150 is what the help text recommended and what the default
	 * always was; it is the resolution at which body text stays sharp on screen and in
	 * ordinary print, which is the whole of what this feature has to do.
	 */
	public const RENDER_DPI = 150;

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
	public function flatten(string $sourcePath, string $destPath): void {
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

		// Page bitmaps are written into a directory of our own at 0700, never into the
		// shared temp directory. `pdftoppm` creates its output file itself, and a name we
		// have already unlinked, in a world-writable directory, is a file another local
		// account can win the race for - by planting a symlink the renderer then follows,
		// or an existing file it truncates. A private directory removes the race outright:
		// nothing else can create a name inside it.
		$workDir = $this->createWorkDir();
		$out = new Tcpdf('pt', fileOptions: ['allowedPaths' => $this->allowedPaths($sourcePath, $workDir)]);

		try {
			foreach ($sizes as $page => $size) {
				$rendered = $this->renderPage($binary, $sourcePath, $page, $workDir);

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
		} finally {
			$this->discardWorkDir($workDir);
		}
	}

	/**
	 * A private directory for this flatten's page bitmaps.
	 *
	 * 0700 and a random name, the same shape {@see WatermarkService} uses for its render
	 * temps. The mode is what matters: the renderer is a separate process writing files we
	 * then read back, and every other local account has to be unable to reach or replace
	 * them in between.
	 */
	private function createWorkDir(): string {
		$dir = sys_get_temp_dir() . '/wm_flat_' . bin2hex(random_bytes(8));
		if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
			throw new \RuntimeException('Cannot flatten PDF: no temp directory available for the page renders.');
		}

		return $dir;
	}

	/** Remove the work directory and anything a failed render left in it. */
	private function discardWorkDir(string $workDir): void {
		foreach (glob($workDir . '/*') ?: [] as $path) {
			@unlink($path);
		}
		@rmdir($workDir);
	}

	/**
	 * Directories the renderer may read from. Everything in play is a temp copy, and
	 * supplying this replaces the library's defaults rather than adding to them - see the
	 * same method on {@see PdfWatermarker} for why both path forms are listed.
	 *
	 * @return list<string>
	 */
	private function allowedPaths(string $sourcePath, ?string $workDir = null): array {
		$paths = [PdfFontPath::directory(), sys_get_temp_dir(), dirname($sourcePath)];
		if ($workDir !== null) {
			$paths[] = $workDir;
		}

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
	 * **The only command line in this application**, and it is built entirely from values
	 * this app controls:
	 *
	 * - `$binary` is an absolute path this class found on `PATH`, quoted rather than merely
	 *   `escapeshellcmd`-ed, so a directory with a space in it is passed as one argument
	 *   instead of being split into two;
	 * - both paths are absolute and inside temp directories this app created, so neither can
	 *   begin with `-` and be read as an option, and both are quoted;
	 * - the resolution is a constant of this class and `$page` is a counted int, both
	 *   formatted with `%d`.
	 *
	 * Nothing a user supplies - filename, watermark text, display name - reaches this string.
	 * The *file* the renderer opens is of course user content; see the class docblock.
	 */
	private function renderPage(string $binary, string $sourcePath, int $page, string $workDir): string {
		// Inside this flatten's own 0700 directory, so no other local account can create,
		// replace or symlink the name the renderer is about to write.
		$prefix = $workDir . '/page-' . $page;
		$expected = $prefix . '.png';

		$command = sprintf(
			'%s -png -r %d -f %d -l %d -singlefile %s %s 2>&1',
			escapeshellarg($binary),
			self::RENDER_DPI,
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
