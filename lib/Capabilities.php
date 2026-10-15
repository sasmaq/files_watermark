<?php

declare(strict_types=1);

namespace OCA\FilesWatermark;

use OCA\FilesWatermark\AppInfo\Application;
use OCA\FilesWatermark\Service\ApplyLimits;
use OCA\FilesWatermark\Service\ImageLimits;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;
use OCP\Capabilities\IInitialStateExcludedCapability;
use Psr\Log\LoggerInterface;

/**
 * What a native client needs to know about this app before its first listing, published
 * under `files_watermark` in `/ocs/v2.php/cloud/capabilities`. Every client already fetches
 * and caches that document after login, so this is the cheapest place to tell one that the
 * feature is there at all - before it, the only signal was the two DAV properties coming
 * back `404` in a listing - and to hand it the policy it gates the file actions on.
 * `doc/clients/README.md` is the contract this describes.
 *
 * **Accounts only.** This is not an `IPublicCapability`: a visitor on a public link can
 * neither apply nor remove a mark, so there is nothing here for an anonymous client to act
 * on, and no reason to publish the policy to one.
 *
 * **Not in the web UI's initial state.** Without {@see IInitialStateExcludedCapability} core
 * would inject this into every page it renders, in every app, at the cost of a policy query
 * for a value no script reads - the Files page already gets the trigger through its own
 * initial state, from `LoadAdditionalScriptsListener`.
 *
 * **It must not throw.** Core does not catch exceptions from `getCapabilities()`, so one
 * escaping here fails the whole capabilities document, and with it every client's login on
 * the server - for the sake of a hint the server enforces regardless.
 */
class Capabilities implements ICapability, IInitialStateExcludedCapability {

	/**
	 * The revision of the client contract in `doc/clients/README.md`. Bumped when that
	 * contract changes in a way a client has to act on - a new property, a changed status
	 * code, a moved route - and not for a release that leaves it alone. A client gates on
	 * this rather than on `version`, which also moves for fixes the contract never sees and
	 * runs on a different line on each Nextcloud branch.
	 */
	public const API_VERSION = 1;

	public function __construct(
		private IAppManager $appManager,
		private WatermarkService $watermarkService,
		private ApplyLimits $applyLimits,
		private ImageLimits $imageLimits,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{files_watermark: array<string, mixed>}
	 */
	public function getCapabilities(): array {
		$capabilities = [
			'version' => $this->appManager->getAppVersion(Application::APP_ID),
			'api_version' => self::API_VERSION,
			'mimetypes' => WatermarkService::SUPPORTED_ALL,
			// The values in force, after ConfiguredLimits has refused a bad one - so a client
			// pre-checks against the number the server will actually enforce, not a typo.
			// Named exactly as the `occ` keys, so the README's table explains both.
			'apply_max_bytes' => $this->applyLimits->maxBytes(),
			'image_max_pixels' => $this->imageLimits->maxPixels(),
		];

		$trigger = $this->trigger();
		if ($trigger !== null) {
			$capabilities['trigger'] = $trigger;
		}

		return [Application::APP_ID => $capabilities];
	}

	/**
	 * The stored trigger, raw - the same value the web client is handed, so a native client
	 * that offers the actions only on an exact `on_demand` agrees with it on everything,
	 * including a stale trigger left behind by an older version. Not `effectiveTrigger()`,
	 * which logs a warning per call, and clients fetch this far more often than anyone
	 * uploads.
	 *
	 * Null when the policy cannot be read at all. The key is then left out, and a client
	 * falls back exactly as it does against a version of this app that never published it.
	 */
	private function trigger(): ?string {
		try {
			return $this->watermarkService->resolveConfig()->getTrigger();
		} catch (\Throwable $e) {
			$this->logger->error('files_watermark: could not read the policy for the capabilities document, leaving the trigger out', [
				'exception' => $e,
			]);
			return null;
		}
	}
}
