<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;
use Psr\Log\LoggerInterface;

/**
 * How many watermark renders one anonymous address may ask for in a period.
 *
 * ---------------------------------------------------------------------------
 * WHY A DOWNLOAD NEEDS A RATE LIMIT HERE WHEN IT DOES NOT IN CORE.
 *
 * Core serves a public-link download by streaming bytes off storage, which costs I/O and
 * nothing else. This app renders a fresh copy on every fetch of a protected file: the PDF
 * is parsed and rebuilt in PHP, up to `apply_max_bytes` of it, and flattening adds a
 * rasteriser run per page on top. Nothing is cached, because the copy names its reader. So
 * a public link to one large marked document turned every anonymous `GET` into seconds of
 * CPU on a PHP worker, and a loop of them from one address could occupy the pool.
 *
 * Signed-in readers are not counted: they are accountable, the render names them, and core
 * already rate-limits the actions that make a file renderable in the first place. This is
 * for the requests nobody can be asked about afterwards.
 * ---------------------------------------------------------------------------
 *
 * **One charge per request, not per render.** A folder archive renders every protected
 * member, but it is charged once - its own cost is bounded by {@see ArchiveLimits}, and
 * charging per member would turn an ordinary folder download into an instant 429. Previews
 * are not charged at all: they stamp an image core has already scaled down, and a public
 * gallery asks for dozens at a time.
 *
 * Counted through core's {@see ILimiter}, per address (an IPv6 address by its subnet, the
 * way core counts it), in the same backend as core's own limits - so
 * `ratelimit.protection.enabled => false` in `config.php` switches this off along with
 * everything else, and a Redis-backed instance shares the count across web servers.
 *
 * ```
 * occ config:app:set files_watermark anon_render_limit --value 200
 * occ config:app:set files_watermark anon_render_period --value 600
 * ```
 *
 * **Federated shares count as anonymous.** Another server fetching a share from this one
 * arrives without a session, from one address, and a sync of a large folder of protected
 * files will meet this limit; raise it for an instance that federates heavily. The remote
 * server sees an ordinary 429 and retries later.
 */
class AnonymousRenderLimit extends ConfiguredLimits {

	/** Renders one anonymous address may ask for within {@see KEY_PERIOD}. */
	public const KEY_LIMIT = 'anon_render_limit';

	/** The window, in seconds, that {@see KEY_LIMIT} is counted over. */
	public const KEY_PERIOD = 'anon_render_period';

	public const DEFAULT_LIMIT = 60;

	public const DEFAULT_PERIOD = 600;

	/** The bucket in core's limiter. Namespaced, since every app shares the backend. */
	private const IDENTIFIER = 'files_watermark::anonymous-render';

	public function __construct(
		IAppConfig $appConfig,
		LoggerInterface $logger,
		private IUserSession $userSession,
		private IRequest $request,
		private ILimiter $limiter,
	) {
		parent::__construct($appConfig, $logger);
	}

	public function maxRenders(): int {
		return $this->limit(self::KEY_LIMIT, self::DEFAULT_LIMIT);
	}

	public function periodSeconds(): int {
		return $this->limit(self::KEY_PERIOD, self::DEFAULT_PERIOD);
	}

	/**
	 * Count one render against the current request's address, or refuse it.
	 *
	 * A no-op for a signed-in reader.
	 *
	 * @throws RenderRateLimitedException the address has used up its renders for now
	 */
	public function charge(): void {
		if ($this->userSession->getUser() !== null) {
			return;
		}

		$period = $this->periodSeconds();

		try {
			$this->limiter->registerAnonRequest(
				self::IDENTIFIER,
				$this->maxRenders(),
				$period,
				$this->request->getRemoteAddress(),
			);
		} catch (IRateLimitExceededException $e) {
			throw new RenderRateLimitedException($period, $e);
		}
	}
}
