<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCP\Files\FileInfo;
use OCP\Files\Storage\ISharedStorage;
use OCP\IUserSession;

/**
 * Whether the fetch happening right now is coming through a share, and which kind.
 *
 * ---------------------------------------------------------------------------
 * WHY THE TWO QUESTIONS ARE ANSWERED BY TWO DIFFERENT SIGNALS.
 *
 * They look like one question - "is somebody other than the owner reading this?" - and they
 * are not, because the two kinds of share reach the file by different routes.
 *
 * An **internal** share is a *mount*: the recipient's copy is an `ISharedStorage` wrapping
 * the owner's node, so the storage answers it. Comparing the session user against
 * `getOwner()` would seem simpler and is wrong - preview and viewer requests resolve the
 * owner inconsistently, and that inconsistency was a leak the last time this app relied on
 * it.
 *
 * A **public link** is not a mount at all. `public.php/dav` resolves the node through
 * `getUserFolder($shareOwner)` and wraps it only in a permissions mask, so the storage says
 * "the owner is reading their own file" for an anonymous stranger. Two signals answer it
 * instead: the DAV server that serves public links tells us directly
 * ({@see notePublicRequest}), and any request with **no session user at all** can only have
 * reached a file through a link.
 * ---------------------------------------------------------------------------
 *
 * Session-less server-side work - preview pre-generation, a background job - falls into the
 * public bucket, deliberately. It is the same call the watermark's own identity fallback
 * makes (nobody to name, so name the owner), and it errs towards watermarking, which is the
 * error whose cost is visible.
 *
 * Request-scoped, because {@see notePublicRequest} is: registered as a shared service in
 * {@see \OCA\FilesWatermark\AppInfo\Application} so the listener that raises the flag and
 * the service that reads it are looking at one instance.
 */
class ShareAccess {

	/**
	 * True once something that only ever serves public links has said so.
	 *
	 * Set from {@see \OCA\FilesWatermark\EventListener\SabrePublicPluginAddListener}, which
	 * runs when the public-link DAV server is built and never on the authenticated one. It
	 * covers the case the session test cannot: a **logged-in** user opening somebody else's
	 * public link, who has a session and is not on a shared mount.
	 */
	private bool $publicRequest = false;

	public function __construct(
		private IUserSession $userSession,
	) {
	}

	public function notePublicRequest(): void {
		$this->publicRequest = true;
	}

	/** Whether this fetch is an external one: a public link, or nobody at all. */
	public function isExternalShareAccess(): bool {
		return $this->publicRequest || $this->userSession->getUser() === null;
	}

	/**
	 * Whether $node is being read through a share mounted from somebody else's storage.
	 *
	 * External access is excluded rather than folded in, so the two admin switches stay
	 * independent: an instance that watermarks internal shares and not public links must not
	 * watermark public links through this method by accident.
	 */
	public function isInternalShareAccess(FileInfo $node): bool {
		if ($this->isExternalShareAccess()) {
			return false;
		}

		try {
			return $node->getStorage()->instanceOfStorage(ISharedStorage::class);
		} catch (\Throwable) {
			// A mount that will not resolve is the download path's problem to report. Saying
			// "not a share" here only means this switch does not claim the fetch.
			return false;
		}
	}
}
