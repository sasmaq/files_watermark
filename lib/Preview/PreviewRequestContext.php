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
	 * True while this app is itself asking for a preview.
	 *
	 * The middleware generates the clean preview by calling `IPreview::getPreview()`, which
	 * fires the same event that put us here - so without this the listener records a second
	 * time from inside the render and the two halves chase each other. The guard is a flag
	 * rather than a call-stack check because it has to hold across the event dispatcher.
	 */
	private bool $generating = false;

	/**
	 * Note that this request is fetching a preview of $file at $width × $height.
	 *
	 * Only the *first* preview of a request is recorded. A request that renders several is
	 * not something core's preview endpoints do - they serve one image - and picking the
	 * last would mean an unrelated internal preview could displace the one being served.
	 */
	public function record(File $file, int $width, int $height): void {
		if ($this->generating || $this->file !== null) {
			return;
		}

		$this->file = $file;
		$this->width = $width;
		$this->height = $height;
	}

	public function file(): ?File {
		return $this->file;
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
