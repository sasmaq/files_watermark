<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCP\Files\FileInfo;
use OCP\Files\Storage\ISharedStorage;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * The address a **share by email** was sent to, when that is how this fetch arrived.
 *
 * Nextcloud's "share by email" (`IShare::TYPE_EMAIL`) is a link share addressed to one
 * recipient: the link is mailed to a single address, and the share row records it. That
 * makes it the one anonymous fetch this app can name precisely - not "somebody with the
 * link", but the person the owner sent it to - which is what {@see WatermarkService}'s
 * `{email}` placeholder resolves to when a copy goes out through such a share.
 *
 * Without it `{email}` falls back to the file owner's address, because a link visitor has
 * no session and the watermark's identity fallback names whoever published the file. That
 * is the right answer for an ordinary public link, where the reader really is unknown, and
 * the wrong one here: a mail share knows exactly who it was sent to, and stamping the
 * owner's address on it names the wrong person on the one copy that could have named the
 * right one.
 *
 * ---------------------------------------------------------------------------
 * TWO SIGNALS, FOR THE SAME REASON {@see ShareAccess} HAS TWO.
 *
 * A mail share is read through two different routes, and only one of them carries the
 * share on the node:
 *
 *  - **The download**, over `public.php/dav`, arrives with the storage wrapped in
 *    `OCA\DAV\Storage\PublicShareWrapper` - an {@see ISharedStorage}, whose `getShare()`
 *    hands back the very share the request authenticated against. Authoritative, so it is
 *    asked first.
 *  - **The preview**, over `/apps/files_sharing/publicpreview/{token}`, does not. That
 *    route resolves the node through the owner's own storage, so nothing about the node
 *    says a share is involved - measured, not assumed: the storage there is the plain
 *    home storage wrapper. What it does have is the token, in the route, which
 *    {@see \OCA\FilesWatermark\Middleware\PublicShareContextMiddleware} notes on the way
 *    in.
 *
 * Leaving the preview out would put the owner's address on the share page and the
 * recipient's on the file downloaded from it - the same inconsistency, between a preview
 * and its download, that the middleware itself exists to close.
 * ---------------------------------------------------------------------------
 *
 * **Only `TYPE_EMAIL`.** An internal share is an `ISharedStorage` too, and its share's
 * "shared with" is a uid rather than an address; an ordinary public link has no recipient
 * at all. Both fall through to the existing behaviour rather than being guessed at.
 *
 * Request-scoped, and registered as a shared service in
 * {@see \OCA\FilesWatermark\AppInfo\Application} for the reason `ShareAccess` is: the
 * middleware that notes the token and the service that reads it have to be looking at one
 * instance. Not `final`, for the same reason {@see ShareAccess} is not: both are collaborators
 * the service tests double.
 */
class ShareRecipient {

	/**
	 * The share token this request came in on, when it came in on a route that carries one.
	 *
	 * Only ever set from the middleware, which sees every controller authenticated by a
	 * token and nothing else.
	 */
	private ?string $token = null;

	public function __construct(
		private IShareManager $shareManager,
	) {
	}

	public function noteShareToken(string $token): void {
		if ($token !== '') {
			$this->token = $token;
		}
	}

	/**
	 * The address `$node` is being fetched under, or `null` when this fetch is not a share
	 * by email - which is every other kind of access, and the caller's cue to keep the
	 * identity it already had.
	 */
	public function email(FileInfo $node): ?string {
		return $this->fromStorage($node) ?? $this->fromNotedToken();
	}

	/**
	 * The share the node itself is mounted from, which is the authoritative answer wherever
	 * it exists: it is the share this request authenticated against, not one looked up
	 * afterwards from a token that might belong to a different file.
	 */
	private function fromStorage(FileInfo $node): ?string {
		try {
			$storage = $node->getStorage();
			if (!$storage->instanceOfStorage(ISharedStorage::class)) {
				return null;
			}

			/** @var ISharedStorage $storage */
			return $this->recipientOf($storage->getShare());
		} catch (\Throwable) {
			// A mount that will not resolve, or a wrapper that declines the question. The
			// watermark still has an identity to fall back on, and a failure to enrich it
			// must not cost the reader their download.
			return null;
		}
	}

	private function fromNotedToken(): ?string {
		if ($this->token === null) {
			return null;
		}

		try {
			return $this->recipientOf($this->shareManager->getShareByToken($this->token));
		} catch (ShareNotFound) {
			// Expired, revoked, or never ours to begin with.
			return null;
		} catch (\Throwable) {
			return null;
		}
	}

	/** The recipient address of `$share`, if it is a share by email that names one. */
	private function recipientOf(IShare $share): ?string {
		if ($share->getShareType() !== IShare::TYPE_EMAIL) {
			return null;
		}

		$recipient = trim((string)$share->getSharedWith());

		return $recipient === '' ? null : $recipient;
	}
}
