<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use Psr\Log\LoggerInterface;

/**
 * Rewrites a PDF the renderer has refused into one it will accept.
 *
 * **Encryption is what this is for now.** tc-lib-pdf refuses every encrypted
 * document, and a common case is not really protected at all: files "encrypted"
 * with an *empty* user password purely to set permission flags, which circulate
 * freely and which a reader opens without ever prompting. `qpdf --decrypt` strips
 * that, and the watermark then goes on normally.
 *
 * It used to exist for a much larger gap. Under FPDI, PDF 1.5+ documents whose
 * cross-reference table is a compressed stream — most modern files, including two
 * of the three skeleton PDFs Nextcloud drops into every new account — were
 * unreadable, and this class was the only thing that made them watermarkable.
 * tc-lib-pdf reads them natively, so that job is gone and this one remains.
 *
 * A *pre-pass, not a replacement*: {@see PdfWatermarker} only reaches for it after
 * the parser has actually refused a file, so the common case pays nothing and
 * documents that already work are never rewritten. The overlay still goes on as a
 * real content stream, so the text layer survives — unlike {@see PdfFlattener},
 * which is the other way to get a difficult file processed and costs the text.
 *
 * Degrades rather than breaks. With no `qpdf` on the host every caller behaves
 * exactly as it did before this class existed: the render throws, and the trigger's
 * own policy (skip plus an audit row, or deny on `on_share`) takes over.
 */
class PdfNormalizer {

	/** Rewriter binary, looked up on PATH rather than assumed from the distro. */
	public const BINARY = 'qpdf';

	/**
	 * Ceiling on one rewrite, matching {@see PdfFlattener}. qpdf holds the whole
	 * document in memory, so an unbounded input is an unbounded request.
	 */
	private const MAX_BYTES = 268435456; // 256 MiB

	/** Per-request memo. The probe only stats PATH, so this never shells out. */
	private ?string $binary = null;
	private bool $probed = false;
	private bool $loggedUnavailable = false;

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether this host can normalize at all.
	 *
	 * Callers check this *before* building a temp path, so that a host without the
	 * binary takes the pre-existing failure path untouched — no temp file, and the
	 * original parse error kept as the cause.
	 */
	public function isAvailable(): bool {
		return $this->resolveBinary() !== null;
	}

	/**
	 * Rewrite `$sourcePath` to `$destPath` in a form the renderer accepts.
	 *
	 * `$destPath` is written only on success; every failure path removes a partial
	 * output, because a truncated PDF handed back to the renderer produces a far more
	 * confusing error than the one that got us here.
	 *
	 * @throws \RuntimeException if the binary is missing, the file is too large, or
	 *                           qpdf cannot read the document (a real password, or
	 *                           damage beyond its repair)
	 */
	public function normalize(string $sourcePath, string $destPath): void {
		$binary = $this->resolveBinary();
		if ($binary === null) {
			throw new \RuntimeException(
				'Cannot normalize PDF: ' . self::BINARY . ' is not installed.',
			);
		}

		$bytes = @filesize($sourcePath);
		if ($bytes === false) {
			throw new \RuntimeException('Cannot normalize PDF: the source file is unreadable.');
		}
		if ($bytes > self::MAX_BYTES) {
			throw new \RuntimeException(
				sprintf('Cannot normalize PDF: %d bytes exceeds the %d byte ceiling.', $bytes, self::MAX_BYTES),
			);
		}

		// --decrypt, and nothing else. It is the reason this class exists: it strips the
		// empty-password encryption the renderer refuses outright. A document with a real
		// password still fails here, and should.
		//
		// `--object-streams=disable` used to be the whole point, forcing a classic
		// cross-reference table for FPDI's free parser. It was kept briefly as a cheap
		// structural repair and is now gone: the renderer reads object streams natively,
		// and once the rescue was narrowed to encryption the flag could not affect any
		// file that reaches here. Stream data is likewise left compressed — Flate content
		// streams are read fine, and uncompressing them would multiply the size for
		// nothing.
		$command = sprintf(
			'%s --decrypt %s %s 2>&1',
			escapeshellcmd($binary),
			escapeshellarg($sourcePath),
			escapeshellarg($destPath),
		);

		$output = [];
		$status = 0;
		exec($command, $output, $status);

		// qpdf exits 3 for warnings and still writes a usable file — recoverable damage
		// in the source, which is a description of most of the PDFs that get here. Only
		// exit 2 (and anything else non-zero) is a genuine failure.
		if (($status !== 0 && $status !== 3) || !is_file($destPath) || filesize($destPath) === 0) {
			if (is_file($destPath)) {
				unlink($destPath);
			}
			throw new \RuntimeException(sprintf(
				'Cannot normalize PDF: %s failed (exit %d) %s',
				self::BINARY,
				$status,
				trim(implode(' ', $output)),
			));
		}

		if ($status === 3) {
			$this->logger->info(
				'files_watermark: ' . self::BINARY . ' reported warnings while normalizing a PDF, '
				. 'but produced a usable file: {detail}',
				['app' => 'files_watermark', 'detail' => trim(implode(' ', $output))],
			);
		}
	}

	/** Absolute path to the rewriter, or null when this host has none. */
	private function resolveBinary(): ?string {
		if ($this->probed) {
			return $this->binary;
		}
		$this->probed = true;
		$this->binary = BinaryLocator::find(self::BINARY);

		if ($this->binary === null && !$this->loggedUnavailable) {
			// There is no admin control for this — it is either possible or it is not —
			// so the log is the only place the remedy can surface. Logged once per
			// request, and only when a file has actually needed it.
			$this->loggedUnavailable = true;
			$this->logger->info(
				'files_watermark: ' . self::BINARY . ' not found on PATH; encrypted PDFs — including '
				. 'files locked only with an empty password to set permission flags — cannot be '
				. 'watermarked. Install ' . self::BINARY . ' to support them.',
				['app' => 'files_watermark'],
			);
		}

		return $this->binary;
	}
}
