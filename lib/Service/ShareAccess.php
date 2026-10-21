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
 * ---------------------------------------------------------------------------
 * NO SESSION IS NOT THE SAME THING AS "NOT A SHARE".
 *
 * The internal test used to give up on any request without a session user, on the theory
 * that such a request could only be a public link. `/remote.php/direct/{token}` is the
 * counter-example: it has no session, and it serves a node resolved through the *creator's*
 * own folder - so a recipient's direct link to a file in a received share sits on that
 * share's mount, and the internal switch never saw it. A recipient could mint a direct link
 * and download the file clean.
 *
 * The internal test is therefore excluded only by the explicit public-link flag. Every
 * route that serves a public link raises it, and their nodes come off the owner's own
 * storage regardless, so the two switches stay as independent as they were.
 * ---------------------------------------------------------------------------
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

	/**
	 * True once a route has established that this reader reached the file through a share
	 * that is not a mount. See {@see noteIndirectShareAccess}.
	 */
	private bool $indirectShare = false;

	public function __construct(
		private IUserSession $userSession,
	) {
	}

	public function notePublicRequest(): void {
		$this->publicRequest = true;
	}

	/**
	 * Note that the reader is fetching somebody else's file through a share the storage
	 * cannot see.
	 *
	 * Two apps share files without mounting them: a Photos album shared with collaborators,
	 * and a Text document whose attachments are read through the *owner's* folder whatever
	 * the reader can see. Both hand over the owner's node, so the storage test says "the
	 * owner is reading their own file". The route that knows better says so here.
	 */
	public function noteIndirectShareAccess(): void {
		$this->indirectShare = true;
	}

	/** Whether this fetch is an external one: a public link, or nobody at all. */
	public function isExternalShareAccess(): bool {
		return $this->publicRequest || $this->userSession->getUser() === null;
	}

	/**
	 * Whether $node is being read through a share mounted from somebody else's storage.
	 *
	 * A public link is excluded rather than folded in, so the two admin switches stay
	 * independent: an instance that watermarks internal shares and not public links must not
	 * watermark public links through this method by accident. A request with no session but
	 * no public-link flag is still asked - see the class docblock for the direct link.
	 */
	public function isInternalShareAccess(FileInfo $node): bool {
		if ($this->publicRequest) {
			return false;
		}

		if ($this->indirectShare) {
			return true;
		}

		try {
			return $node->getStorage()->instanceOfStorage(ISharedStorage::class);
		} catch (\Throwable) {
			// A mount that will not resolve is the download path's problem to report. Saying
			// "not a share" here only means this switch does not claim the fetch.
			return false;
		}
	}

	/**
	 * Whether either switch *could* claim this fetch of $node, whether or not it is on.
	 *
	 * What a cacheable response has to ask: turning a switch on moves no etag and no URL, so
	 * a clean copy that went out through a share must not be one the reader can go on using
	 * without asking.
	 */
	public function isShareAccess(FileInfo $node): bool {
		return $this->isExternalShareAccess() || $this->isInternalShareAccess($node);
	}
}
