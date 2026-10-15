<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Preview;

use OCP\Files\File;

/**
 * What the current request asked a preview of, if anything.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS RATHER THAN A LIST OF PREVIEW ROUTES.
 *
 * Watermarking previews means intercepting them, and the obvious way to intercept them is
 * to enumerate the routes that serve one: `core.Preview.getPreviewByFileId`,
 * `core.Preview.getPreview`, the `files_sharing` public preview, `files_versions`,
 * `files_trashbin`, and whatever the Viewer and Photos apps reach for. **One missed route
 * is not a missing feature, it is an unwatermarked copy of a protected file**, and the list
 * is core's to change between releases without telling anyone.
 *
 * So nothing here knows a single route. Every one of those endpoints reaches
 * `OCP\IPreview`, which emits `BeforePreviewFetchedEvent` before it generates or serves
 * anything - so the event is the choke point, and a route this app has never heard of
 * arrives at it exactly like the ones it has. The listener records the node here; the
 * middleware, running after the controller, finds it and replaces the response.
 * ---------------------------------------------------------------------------
 *
 * Request-scoped state in a service, which is worth being explicit about: it holds for one
 * PHP request and is thrown away, and the two halves that use it are both inside that
 * request. It is registered as a shared service so both see the same instance.
 */
class PreviewRequestContext {

	private ?File $file = null;
	private int $width = 0;
	private int $height = 0;

	/**
	 * The rest of what core was asked for, so the middleware asks for the *same* preview.
	 *
	 * It used to ask for a square of the shorter side with neither crop nor mode: the
	 * Viewer's 1920x1080 request came back as a 1080 box, and the Files list's cropped
	 * squares came back uncropped - the preview a marked file showed was not the one core
	 * had just rendered, and not a cache hit either.
	 */
	private ?bool $crop = null;
	private ?string $mode = null;
	private ?string $mimeType = null;

	/**
	 * The file whose mark and identity govern this preview, when that is not the node being
	 * previewed. Set for an old version, whose node is a file of its own in `files_versions`
	 * - a different id, which no mark names. See {@see noteVersionSource}.
	 */
	private ?File $subject = null;

	/** The versioned file, noted by the middleware before the version's preview is fetched. */
	private ?File $versionSource = null;

	/**
	 * Whether the recorded file is to be watermarked on this fetch. False for a file this app
	 * *could* watermark but does not, here and now - it is recorded anyway, because it can be
	 * marked later and its clean preview must not be cached past that. See
	 * {@see \OCA\FilesWatermark\Middleware\WatermarkPreviewMiddleware::revalidating}.
	 */
	private bool $stamp = false;

	/**
	 * True while this app is itself asking for a preview.
	 *
	 * The middleware generates the clean preview by calling `IPreview::getPreview()`, which
	 * fires the same event that put us here - so without this the listener records a second
	 * time from inside the render and the two halves chase each other. The guard is a flag
	 * rather than a call-stack check because it has to hold across the event dispatcher.
	 */
	private bool $generating = false;

	/**
	 * Note that this request is fetching a preview of $file at $width × $height, and whether
	 * that preview is to be watermarked.
	 *
	 * Only the *first* preview of a request is recorded. A request that renders several is
	 * not something core's preview endpoints do - they serve one image - and picking the
	 * last would mean an unrelated internal preview could displace the one being served.
	 */
	public function record(
		File $file,
		int $width,
		int $height,
		bool $stamp = true,
		?bool $crop = null,
		?string $mode = null,
		?string $mimeType = null,
		?File $subject = null,
	): void {
		if ($this->generating || $this->file !== null) {
			return;
		}

		$this->file = $file;
		$this->width = $width;
		$this->height = $height;
		$this->stamp = $stamp;
		$this->crop = $crop;
		$this->mode = $mode;
		$this->mimeType = $mimeType;
		$this->subject = $subject;
	}

	/** The node whose preview is to be watermarked, or null when there is none. */
	public function file(): ?File {
		return $this->stamp ? $this->file : null;
	}

	/**
	 * The file the watermark is about - its name, its owner, its share - which is the
	 * previewed node itself except for an old version.
	 */
	public function subject(): ?File {
		return $this->stamp ? ($this->subject ?? $this->file) : null;
	}

	/**
	 * Note that this request previews a version of $source.
	 *
	 * The version controller is the one preview endpoint whose node cannot be traced back to
	 * the file it belongs to: the event carries the version's own node, under
	 * `files_versions`, with its own file id. So the middleware notes the source from the
	 * controller's arguments before the controller runs, and the listener reads it here.
	 */
	public function noteVersionSource(File $source): void {
		$this->versionSource = $source;
	}

	public function versionSource(): ?File {
		return $this->versionSource;
	}

	/** The requested width, or 0 when the request named none. */
	public function width(): int {
		return $this->width;
	}

	/** The requested height, or 0 when the request named none. */
	public function height(): int {
		return $this->height;
	}

	public function crop(): ?bool {
		return $this->crop;
	}

	public function mode(): ?string {
		return $this->mode;
	}

	public function mimeType(): ?string {
		return $this->mimeType;
	}

	/**
	 * Whether this request produced a preview of a file this app watches, stamped or not.
	 *
	 * What a controller that can answer with *either* a preview or the raw file - Text's
	 * attachment image - is asked, to tell which it did: a preview is
	 * {@see \OCA\FilesWatermark\Middleware\WatermarkPreviewMiddleware}'s to handle.
	 */
	public function previewed(): bool {
		return $this->file !== null;
	}

	/** Whether this request previews a file this app could watermark, and goes out clean. */
	public function previewsCleanCopy(): bool {
		return $this->file !== null && !$this->stamp;
	}

	/**
	 * The smaller side of the requested preview, in pixels, or 0 when unknown.
	 *
	 * Zero is a real answer: the event's dimensions are nullable, and a caller that asked
	 * for a preview without saying how big gets whatever core's defaults produce. The
	 * middleware measures the image it actually receives rather than trusting this.
	 */
	public function shorterSide(): int {
		return min($this->width, $this->height);
	}

	/**
	 * Run $callback with recording suppressed.
	 *
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public function whileGenerating(callable $callback): mixed {
		$this->generating = true;
		try {
			return $callback();
		} finally {
			$this->generating = false;
		}
	}
}
