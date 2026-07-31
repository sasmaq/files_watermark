<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\ImageWatermarker;

/**
 * An {@see ImageWatermarker} whose extension availability is dictated rather than probed.
 *
 * Engine selection has to be assertable for hosts this suite will never run on — a GD built
 * without libwebp, a server with no Imagick, a server with neither. Probing the real machine
 * would make the covered branches depend on how PHP was compiled, which is exactly the
 * host-dependent coverage this app has spent its history removing.
 *
 * It overrides only the three capability probes; every selection rule under test is the real
 * one.
 */
class FakeImageStack extends ImageWatermarker {

	public function __construct(
		private bool $gd,
		private bool $gdWebp,
		private bool $imagick,
		?string $preferredEngine = null,
	) {
		parent::__construct($preferredEngine);
	}

	protected function hasGd(): bool {
		return $this->gd;
	}

	protected function hasGdWebpSupport(): bool {
		return $this->gdWebp;
	}

	protected function hasImagick(): bool {
		return $this->imagick;
	}
}
