<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\EventListener\NodeWrittenListener;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\ISharedStorage;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

class WatermarkService {

	public const SUPPORTED_PDF = ['application/pdf'];
	public const SUPPORTED_IMAGE = ['image/jpeg', 'image/png', 'image/webp'];
	public const SUPPORTED_ALL = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

	/** Log trigger recorded when a watermark is undone; see {@see removeWatermark}. */
	public const TRIGGER_REMOVED = 'removed';

	/**
	 * A user's own write replaced content this app had watermarked.
	 *
	 * Recorded rather than inferred: see {@see noteContentReplaced}.
	 */
	public const TRIGGER_REPLACED = 'replaced';

	/** Per-request memo for {@see resolveConfig}. One policy, so one slot. */
	private ?WatermarkConfig $configCache = null;

	public function __construct(
		private WatermarkConfigMapper $configMapper,
		private WatermarkLogMapper $logMapper,
		private PdfWatermarker $pdfWatermarker,
		private ImageWatermarker $imageWatermarker,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private ISystemTagObjectMapper $tagObjectMapper,
		private LoggerInterface $logger,
		private OriginalStore $originalStore,
		private WatermarkImageStore $imageStore,
		private IL10N $l,
	) {
	}

	/**
	 * Apply a watermark and return the path of the watermarked temporary copy.
	 * Caller is responsible for deleting the temp file after use.
	 *
	 * For the streaming triggers the render *is* the deliverable, so the audit row is
	 * recorded here. The in-place triggers must not use this - their deliverable is the
	 * persisted write, so they render via {@see renderToTemp} and log only once that
	 * write lands. See {@see watermarkInPlace}.
	 */
	public function watermarkFile(File $file, string $trigger, ?WatermarkConfig $config = null): string {
		[$tmpPath, $resolved] = $this->renderToTemp($file, $trigger, $config);
		$this->recordLog($file, $trigger, $resolved);

		return $tmpPath;
	}

	/**
	 * Render the watermarked copy to a temp path, without recording anything.
	 *
	 * @return array{0: string, 1: WatermarkConfig} the temp path and the config the
	 *                                              render actually resolved to (callers need its id for the audit row)
	 */
	private function renderToTemp(File $file, string $trigger, ?WatermarkConfig $config, ?IUser $actor = null): array {
		$mime = $file->getMimeType();
		$this->assertSupported($mime, $file);

		if ($config === null) {
			$config = $this->resolveConfig();
		}

		$this->assertMimeAllowed($mime, $config);
		$this->assertFolderTagMatches($file, $config);

		$placeholders = $this->buildPlaceholders($file, $trigger, $actor);
		$tmpPath = $this->createTempPath($file->getName());

		$srcTmp = $tmpPath . '_src';
		file_put_contents($srcTmp, $file->getContent());

		// The renderers open the logo as a plain file path, but the config only holds an
		// opaque reference to an uploaded image - resolve it to a temp copy for the render
		// and hand them a config pointing at that. Anything the store does not recognise
		// (notably a legacy hand-typed absolute path) resolves to null and renders as
		// text-only rather than reading the server's filesystem.
		$logoTmp = $this->imageStore->localPath($config->getImagePath());
		$config = $this->withImagePath($config, $logoTmp);

		try {
			if (in_array($mime, self::SUPPORTED_PDF, true)) {
				$this->pdfWatermarker->apply($srcTmp, $tmpPath, $config, $placeholders);
			} else {
				$this->imageWatermarker->apply($srcTmp, $tmpPath, $config, $placeholders);
			}
		} catch (\Throwable $e) {
			$this->discardLogo($logoTmp);
			// $srcTmp holds a plaintext copy of the file. A render failure is routine
			// (unparseable PDFs, and every on_share deny path goes through one), so
			// without this it accumulates readable copies of user content in the temp
			// dir indefinitely - the caller only ever gets an exception, never a path
			// it could clean up itself.
			$this->discardTemp($tmpPath, $srcTmp);
			throw $e;
		}

		$this->discardLogo($logoTmp);
		unlink($srcTmp);

		return [$tmpPath, $config];
	}

	/**
	 * Record the audit row for a watermark that has actually been delivered.
	 */
	private function recordLog(File $file, string $trigger, WatermarkConfig $config, ?IUser $actor = null): void {
		// Delivery rows are pure audit and are recorded only when the policy asks for
		// them: they are written per *fetch*, so an archive of 200 members downloaded
		// twice a day is 400 rows a day, forever. The in-place rows fall through - they
		// are not history, they are the app's record that a file's stored bytes carry a
		// watermark, and the badge and the double-burn guard both read them.
		if (
			!$config->getLogDelivery()
			&& in_array($trigger, WatermarkLogMapper::NON_DESTRUCTIVE_TRIGGERS, true)
		) {
			return;
		}

		$user = $actor ?? $this->userSession->getUser();
		$this->logMapper->insertLog(
			$user?->getUID() ?? $this->anonymousLabel($trigger, 'public-link', 'system'),
			$file->getId(),
			$file->getPath(),
			$trigger,
			$config->getId(),
		);
	}

	/**
	 * Render a watermarked copy for a file being fetched over WebDAV, or return null
	 * to serve the clean original. This is the single gate for both non-destructive
	 * delivery triggers:
	 *
	 *  - `on_download` - watermark on every download, whoever fetches the file.
	 *  - `on_share`    - watermark only when the file is fetched by someone other than
	 *                    its owner (a share recipient, or an anonymous public-link
	 *                    visitor). The owner reading their own file is left untouched.
	 *
	 * The applicable policy is the file *owner's* - they own the watermark rule for
	 * their file - not the downloader's, who may be a recipient with an unrelated
	 * personal config. Null is returned for an unsupported type, a trigger that does
	 * not apply to this access, or any rendering failure (which must degrade to the
	 * untouched original rather than break the download). On success the path of a
	 * watermarked temp copy is returned; the caller owns it and must delete it.
	 *
	 * @param bool $publicContext true when the fetch arrives over the public-link
	 *                            endpoint, where share access cannot be detected from
	 *                            the storage ({@see isShareAccess})
	 * @return string|null temp file path to stream, or null to serve the original
	 */
	public function watermarkForDownload(File $file, bool $publicContext = false): ?string {
		$delivery = $this->resolveDelivery($file, $publicContext);
		if ($delivery === null) {
			return null;
		}
		[$trigger, $config] = $delivery;

		try {
			return $this->watermarkFile($file, $trigger, $config);
		} catch (\Throwable $e) {
			$this->logger->error('files_watermark: failed to watermark on delivery: ' . $e->getMessage(), [
				'exception' => $e,
				'trigger' => $trigger,
				'path' => $file->getPath(),
			]);
			return null;
		}
	}

	/**
	 * The delivery trigger (`on_download` / `on_share`) that applies to the current
	 * fetch of $file, or null when the file should be served unmodified.
	 *
	 * The download interceptor uses this to tell an `on_share` recipient access apart:
	 * when {@see watermarkForDownload} cannot produce a watermarked copy (e.g. a PDF
	 * the renderer can't parse), the interceptor denies the request for `on_share`
	 * rather than leaking the clean original to the recipient.
	 */
	public function deliveryTrigger(File $file, bool $publicContext = false): ?string {
		$delivery = $this->resolveDelivery($file, $publicContext);
		return $delivery === null ? null : $delivery[0];
	}

	/**
	 * Whether $file is being accessed through a share mount - i.e. the current user is
	 * a share recipient (internal share) or an anonymous public-link visitor, not the
	 * file's owner.
	 *
	 * Detected from the storage backend ({@see ISharedStorage}) rather than by
	 * comparing user ids: `getOwner()` vs the session user is unreliable in preview and
	 * viewer request contexts, which let `on_share` content leak to recipients. A
	 * received share is always mounted on a shared storage; the owner's own copy is not.
	 */
	public function isReceivedShare(FileInfo $file): bool {
		try {
			return $file->getStorage()->instanceOfStorage(ISharedStorage::class);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Whether $file is being accessed by someone other than its owner - the full
	 * `on_share` audience: internal share recipients *and* public-link visitors.
	 *
	 * {@see isReceivedShare} alone does not cover public links. A public link is served
	 * from the *owner's* own storage (`public.php/dav` resolves the node through
	 * `getUserFolder($shareOwner)` and only wraps it in PermissionsMask /
	 * PublicOwnerWrapper), so the mount is never an ISharedStorage and the storage test
	 * reports "owner access" for an anonymous visitor - which would hand them the clean
	 * original. Two further signals close that hole:
	 *
	 *  - $publicContext - set by the caller that *knows* it is serving a public link
	 *    (the interceptor instance registered on the public DAV server).
	 *  - no session user - an anonymous request can only be reaching a file through a
	 *    public link, so it is never owner access. This also covers callers that have no
	 *    context flag to pass, such as public preview requests. Background jobs with no
	 *    session (e.g. preview pre-generation) fall in here too and are treated as share
	 *    access; erring towards watermarking/blocking keeps content from leaking.
	 */
	public function isShareAccess(FileInfo $file, bool $publicContext = false): bool {
		return $publicContext
			|| $this->isReceivedShare($file)
			|| $this->userSession->getUser() === null;
	}

	/**
	 * Decide which delivery trigger (if any) applies to the current fetch of $file.
	 *
	 * Encapsulates the whole gate: supported type, the on_download / on_share(+non-
	 * owner) rule resolved against the *owner's* policy, and the config exclusions
	 * (mime whitelist, folder tag). Folding the exclusions in here means a file the
	 * policy would deliberately skip is reported as "not applicable" (serve the
	 * original) rather than as a watermark that later fails (which the interceptor
	 * would treat as a leak to deny).
	 *
	 * @return array{0: string, 1: WatermarkConfig}|null [trigger, config] or null
	 */
	private function resolveDelivery(File $file, bool $publicContext = false): ?array {
		$mime = $file->getMimeType();
		if (!$this->isSupported($mime)) {
			return null;
		}

		// A preserved original is the app's own copy, kept precisely so the watermark can
		// be taken off again. Serving it stamped would hand back a "clean original" that
		// is nothing of the sort. Guarded here rather than in each plugin so the single
		// file download and the archive path are both covered.
		if ($this->originalStore->isBackup($file)) {
			return null;
		}

		$config = $this->deliveryConfig($file, $publicContext);
		if ($config === null) {
			return null;
		}

		// A file the config would skip (excluded mime, missing folder tag) is not a
		// watermark candidate - report "not applicable" so it is served untouched.
		try {
			$this->assertMimeAllowed($mime, $config);
			$this->assertFolderTagMatches($file, $config);
		} catch (\RuntimeException) {
			return null;
		}

		return [$config->getTrigger(), $config];
	}

	/**
	 * The config when a delivery trigger applies to this fetch of $node, or null when the
	 * node should be served unmodified.
	 *
	 * This is the type-agnostic half of {@see resolveDelivery}: the policy plus the
	 * on_download / on_share(+non-owner) rule, with no per-file exclusions.
	 *
	 * Only ever ask this about a *file*. A folder cannot answer for its members under
	 * on_share: a received single-file share is mounted inside the recipient's own home,
	 * so the containing folder reports owner access while the member is a share. Gating an
	 * archive on its container is what leaked clean originals; {@see deliveryTriggerFor}
	 * per member is the correct question.
	 */
	private function deliveryConfig(FileInfo $node, bool $publicContext = false): ?WatermarkConfig {
		try {
			$config = $this->resolveConfig();
		} catch (\Throwable) {
			return null;
		}

		$trigger = $config->getTrigger();

		if ($trigger !== 'on_download' && !($trigger === 'on_share' && $this->isShareAccess($node, $publicContext))) {
			return null;
		}

		return $config;
	}

	/**
	 * The delivery trigger that applies to $node ignoring per-file exclusions, or null.
	 *
	 * Lets the archive interceptor tell "this member had to be watermarked and the render
	 * failed" (deny) from "this member was never a candidate" (stream as-is).
	 */
	public function deliveryTriggerFor(FileInfo $node, bool $publicContext = false): ?string {
		return $this->deliveryConfig($node, $publicContext)?->getTrigger();
	}

	/**
	 * Whether the file has ever been watermarked (has any row in `watermark_log`).
	 *
	 * Mirrors the Files-list indicator's definition. It is the guard used to skip
	 * re-stamping a file whose content was already burned in place.
	 */
	public function isAlreadyWatermarked(int $fileId): bool {
		return $this->logMapper->findWatermarkedFileIds([$fileId]) !== [];
	}

	/**
	 * Apply watermark in-place - replaces the file content inside Nextcloud.
	 *
	 * Skips (and returns false) when the file has already been watermarked, so an
	 * in-place burn is never applied twice - this is the authoritative guard for the
	 * in-place triggers (`on_demand`, `on_upload`). Copy/stream triggers
	 * (`on_share`, `on_download`) go through {@see watermarkFile} against the clean
	 * original and are intentionally not guarded here.
	 *
	 * @return bool true when the watermark was applied, false when it was skipped
	 *              because the file is already watermarked
	 */
	public function watermarkInPlace(File $file, string $trigger, ?WatermarkConfig $config = null, ?IUser $actor = null): bool {
		// The app's own preserved originals live in the owner's storage, where they are
		// ordinary supported files as far as every trigger is concerned. Watermarking one
		// would burn a watermark into the copy kept to undo watermarks, and store a copy
		// of *that* - so this is the choke point every in-place path goes through, not
		// only the listener that would queue it.
		if ($this->originalStore->isBackup($file)) {
			return false;
		}

		if ($this->isAlreadyWatermarked($file->getId())) {
			$this->logger->info('files_watermark: skipping already-watermarked file {path}', [
				'path' => $file->getPath(),
				'fileId' => $file->getId(),
			]);
			return false;
		}

		[$tmpPath, $resolved] = $this->renderToTemp($file, $trigger, $config, $actor);

		try {
			// Preserve the pre-watermark bytes before they are overwritten - this burn is
			// destructive and irreversible, so this copy is the only route back. Read the
			// original *now*, while the stored content is still clean. A failed backup is
			// logged and does not abort the watermark; the user simply won't be able to undo
			// it, which removeWatermark() reports rather than pretending to restore.
			$this->originalStore->store($file, $file->getContent());

			// Read before writing, and checked: false here would otherwise reach putContent()
			// as the empty string and replace the file with nothing - the one outcome this
			// burn cannot be allowed to have, since the original is already overwritten by
			// the time anyone notices.
			$watermarked = file_get_contents($tmpPath);
			if ($watermarked === false) {
				throw new \RuntimeException($this->l->t('The watermarked file could not be read back.'));
			}

			$file->putContent($watermarked);
		} finally {
			// $tmpPath holds a plaintext watermarked copy of the file. putContent() can
			// throw (a locked node, a full quota), and without this the copy is left
			// readable in the temp dir - the same leak discardTemp() exists to prevent on
			// the render path.
			$this->discardTemp($tmpPath);
		}

		// Only once the write has landed. Logging before it would assert a watermark that
		// isn't in the file, and because isAlreadyWatermarked() reads this same log that
		// phantom row would then permanently skip the file on every retry.
		$this->recordLog($file, $trigger, $resolved, $actor);

		return true;
	}

	/**
	 * Undo an in-place watermark by restoring the preserved original.
	 *
	 * The watermark is burned into the content, so this is a restore, not a strip: it
	 * rewrites the file with the copy {@see watermarkInPlace} took beforehand. Once
	 * restored the backup is discarded and a `removed` row is recorded, which makes
	 * {@see isAlreadyWatermarked} report false again so the file can be re-watermarked.
	 *
	 * The removal is logged rather than the original rows being deleted - this is an audit
	 * log, so the apply and the undo both belong in the history.
	 *
	 * @return bool true when the original was restored, false when none is preserved
	 */
	public function removeWatermark(File $file): bool {
		$fileId = $file->getId();
		$content = $this->originalStore->read($file);

		if ($content === null) {
			$this->logger->info('files_watermark: no preserved original for {path}, cannot remove watermark', [
				'path' => $file->getPath(),
				'fileId' => $fileId,
			]);
			return false;
		}

		// Suppressed like every other write this app makes: without it the restore looks
		// to NodeWrittenListener like a user replacing watermarked content, and a
		// `replaced` row lands in the audit trail a moment before the `removed` one that
		// actually describes what happened.
		NodeWrittenListener::suppressFor($fileId, function () use ($file, $content): void {
			$file->putContent($content);
		});

		// Only drop the backup once the restore has actually landed, so a failed
		// putContent (which throws) leaves the original recoverable on a later attempt.
		$this->originalStore->discard($file);

		$this->logMapper->insertLog(
			$this->userSession->getUser()?->getUID() ?? 'system',
			$fileId,
			$file->getPath(),
			self::TRIGGER_REMOVED,
			null,
		);

		return true;
	}

	/**
	 * Record that a user's own write replaced watermarked content, and drop the copy that
	 * write orphaned.
	 *
	 * **This is what makes an overwrite behave.** The double-burn guard asks the log
	 * whether this *file id* has a standing watermark, and a file id survives having its
	 * content replaced - so without this, re-uploading over a watermarked file left the
	 * new bytes clean while the badge, the guard and the audit row all still described the
	 * bytes that had just been thrown away. Two uploads of the same path were enough to
	 * store an unwatermarked file under a policy whose whole purpose is preventing that.
	 *
	 * Caught at the write rather than inferred afterwards, because every way of inferring
	 * it later is wrong: mtime is client-supplied on sync uploads (`X-OC-MTime`), so a
	 * fresh upload routinely looks *older* than the watermark it replaced, and hashing the
	 * content on every badge lookup would read every file in a directory listing. The write
	 * itself is unambiguous, and {@see NodeWrittenListener::suppressFor} already tells this
	 * app's own writes apart from a user's.
	 *
	 * The preserved original goes with it. It belongs to content that no longer exists, and
	 * keeping it would let "remove watermark" restore *the previous file* over the one the
	 * user just uploaded - losing their data to a feature that exists to protect it.
	 * {@see OriginalStore::store()} never overwrites, so discarding here is also what lets
	 * the next burn preserve the right bytes.
	 *
	 * A `replaced` row rather than a `removed` one: nothing was restored and nobody asked
	 * for a removal. Both cancel the apply for the guard's purposes; only one of them is
	 * true.
	 */
	public function noteContentReplaced(File $file): void {
		$fileId = $file->getId();
		if (!$this->isAlreadyWatermarked($fileId)) {
			// Nothing standing to replace: a first upload, or content already superseded.
			return;
		}

		$this->originalStore->discard($file);

		$this->logMapper->insertLog(
			$this->userSession->getUser()?->getUID() ?? 'system',
			$fileId,
			$file->getPath(),
			self::TRIGGER_REPLACED,
			null,
		);
	}

	/**
	 * Whether a watermark on this file can be undone (a preserved original exists).
	 */
	public function canRemoveWatermark(File $file): bool {
		return $this->originalStore->has($file);
	}

	/**
	 * Whether any configured policy uses a delivery trigger.
	 *
	 * Coarse, owner-agnostic gate for the archive interceptor - see
	 * {@see WatermarkConfigMapper::hasDeliveryTrigger}.
	 */
	public function hasDeliveryTriggerConfigured(): bool {
		return $this->configMapper->hasDeliveryTrigger();
	}

	/**
	 * The policy in force: the admin's global config, or the built-in default.
	 *
	 * It takes no user, deliberately. The policy is server-wide, so every caller - every
	 * trigger, every access path, owner and recipient alike - resolves the same one, and
	 * there is no "whose config is this" question to get wrong.
	 */
	public function resolveConfig(): WatermarkConfig {
		// Memoised for the request: a folder download resolves the policy once per member,
		// which is a query a file without this. Configs are not mutated mid-request -
		// the settings endpoints write and return, they do not then re-read through here.
		if ($this->configCache !== null) {
			return $this->configCache;
		}

		try {
			return $this->configCache = $this->configMapper->findGlobal();
		} catch (DoesNotExistException) {
			return $this->configCache = $this->defaultConfig();
		}
	}

	private function defaultConfig(): WatermarkConfig {
		$config = new WatermarkConfig();
		$config->setType('text');
		// The display name, not the account name: a watermark is read by a person, and
		// "Alice Smith" identifies the leak to them in a way "asmith3" does not.
		$config->setTextTemplate('{displayname} - {date}');
		// Faint enough to read the document through, dark enough to survive a screenshot.
		$config->setOpacity(40);
		$config->setFontSize(24);
		$config->setColor('#808080');
		$config->setRotation(45);
		$config->setTrigger('on_demand');
		return $config;
	}

	/**
	 * Throws if the MIME type is not in the config's whitelist (when one is configured).
	 */
	private function assertMimeAllowed(string $mime, WatermarkConfig $config): void {
		$allowed = $config->getAllowedMimeTypes();
		if (!empty($allowed) && !in_array($mime, $allowed, true)) {
			throw new \RuntimeException($this->l->t('MIME type "%s" is not in the configured whitelist.', [$mime]));
		}
	}

	/**
	 * Throws if the file's parent folder does not carry the required system tag.
	 */
	private function assertFolderTagMatches(File $file, WatermarkConfig $config): void {
		$tagId = $config->getFolderTag();
		if ($tagId === null || $tagId === '') {
			return;
		}

		$parent = $file->getParent();

		// A tag that is not a numeric id raises InvalidArgumentException, and one that
		// no longer exists raises TagNotFoundException. `saveConfig` rejects both now,
		// but a config stored before it did - or edited straight in the database - must
		// still degrade to this app's ordinary "cannot watermark" path. Left uncaught,
		// InvalidArgumentException is not a RuntimeException, so it sailed past every
		// caller's handling and turned each watermark request into an HTTP 500.
		try {
			$taggedFileIds = $this->tagObjectMapper->getObjectIdsForTags(
				[$tagId],
				'files',
				0,
			);
		} catch (TagNotFoundException|\InvalidArgumentException $e) {
			$this->logger->warning(
				'files_watermark: config {config} targets system tag {tag}, which is not a usable tag id; '
					. 'no file matches it. Re-pick the tag in the watermark settings.',
				['app' => 'files_watermark', 'config' => $config->getId(), 'tag' => $tagId, 'exception' => $e],
			);
			throw new \RuntimeException(
				$this->l->t('The configured system tag ("%s") does not exist on this server.', [$tagId]),
				0,
				$e,
			);
		}

		if (!in_array((string)$parent->getId(), $taggedFileIds, true)) {
			throw new \RuntimeException(
				$this->l->t('This file\'s folder does not have the required system tag (id: %s).', [$tagId])
			);
		}
	}

	/**
	 * The name to stamp / log when there is no session user. Under `on_share` that can
	 * only be an anonymous public-link visitor, so naming them as such makes a leaked
	 * copy show how it was obtained; other triggers keep the old generic fallbacks.
	 */
	private function anonymousLabel(string $trigger, string $publicLabel, string $default): string {
		return $trigger === 'on_share' ? $publicLabel : $default;
	}

	/**
	 * @param ?IUser $actor the user the watermark is attributed to; null falls back to the
	 *                      session user. Background jobs have no session, so they must pass it explicitly
	 *                      or every watermark would render as "Unknown".
	 */
	private function buildPlaceholders(File $file, string $trigger, ?IUser $actor = null): array {
		$user = $actor ?? $this->userSession->getUser();
		$anonymous = $this->anonymousLabel($trigger, 'Public link', 'Unknown');
		return [
			// Two different identities, and the difference matters in a watermark. The
			// account name is the uid: unique, stable, and what an admin greps the audit
			// log or the user list for. The display name is what a human recognises, and
			// is neither unique nor fixed - a user can change it, and two people can share
			// one. `{username}` used to render the *display* name, which made the account
			// name unreachable and the token a lie; both are now available under the name
			// that describes them. Existing templates were rewritten to `{displayname}` by
			// Version1004Date20260731000000 so no watermark changed on upgrade.
			'username' => $user?->getUID() ?? $anonymous,
			'displayname' => $user?->getDisplayName() ?? $anonymous,
			'email' => $user?->getEMailAddress() ?? '',
			'date' => date('Y-m-d'),
			'datetime' => date('Y-m-d H:i:s'),
			'filename' => $file->getName(),
		];
	}

	/**
	 * Whether a MIME type can be watermarked at all (single source of truth for routing).
	 */
	public function isSupported(string $mime): bool {
		return in_array($mime, self::SUPPORTED_ALL, true);
	}

	/**
	 * Skips (aborts) processing of an unsupported file, recording an audit-log entry first.
	 */
	private function assertSupported(string $mime, ?File $file = null): void {
		if (!$this->isSupported($mime)) {
			$this->logger->info('files_watermark: skipping unsupported file type {mime}', [
				'mime' => $mime,
				'path' => $file?->getPath(),
			]);
			throw new \RuntimeException($this->l->t('Unsupported file type: %s', [$mime]));
		}
	}

	/**
	 * A copy of $config whose image path is $path, so the renderers see a real file (or
	 * none) instead of the stored reference. Copied rather than mutated: $config is often
	 * the entity the mapper handed us, and it must not be dirtied by a render.
	 */
	private function withImagePath(WatermarkConfig $config, ?string $path): WatermarkConfig {
		$resolved = clone $config;
		$resolved->setImagePath($path);
		return $resolved;
	}

	private function discardLogo(?string $tmpPath): void {
		if ($tmpPath !== null && file_exists($tmpPath)) {
			@unlink($tmpPath);
		}
	}

	/**
	 * Remove the temp working files for a render, and the private dir holding them.
	 */
	private function discardTemp(string ...$paths): void {
		$dir = null;
		foreach ($paths as $path) {
			$dir ??= dirname($path);
			if (file_exists($path)) {
				@unlink($path);
			}
		}
		if ($dir !== null) {
			@rmdir($dir);
		}
	}

	private function createTempPath(string $filename): string {
		$dir = sys_get_temp_dir() . '/nc_watermark_' . bin2hex(random_bytes(8));
		mkdir($dir, 0700, true);
		return $dir . '/' . $filename;
	}
}
