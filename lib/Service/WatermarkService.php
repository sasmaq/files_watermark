<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCA\FilesWatermark\BackgroundJob\MeasureDeliveryJob;
use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Db\WatermarkMarkMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Marking files, and watermarking them when they are fetched.
 *
 * ---------------------------------------------------------------------------
 * THE MODEL, IN ONE PARAGRAPH.
 *
 * **Nothing this class does changes a stored file.** A trigger decides *which files carry a
 * mark* - `on_demand` when a user asks for one, `on_upload` for everything supported that is
 * written - and the mark is a row, not a byte. The watermark itself is drawn on a temporary
 * copy at the moment somebody fetches the file, against the identity of whoever that is: a
 * share recipient sees their own name, and a public-link visitor, who has no identity to
 * read, sees the file's owner. Two people downloading the same marked file get two
 * different files, and neither is the one on disk.
 *
 * That is the whole reason the app no longer writes to storage. A watermark burned into the
 * content can only name the person who triggered it, which for a shared file is the wrong
 * person - it names whoever uploaded the document rather than whoever walked out with it.
 * ---------------------------------------------------------------------------
 *
 * **A share can force the watermark without a mark.** Two admin switches - internal shares
 * and public links - are read at delivery and watermark whatever leaves through a share,
 * marked or not ({@see isForcedByShare}). They are the one place where *who is asking*
 * decides whether there is a watermark rather than only what it says, and they are
 * deliberately not triggers: they place no mark, so turning one off stops the watermarking
 * everywhere at once and leaves nothing behind to undo.
 *
 * Two consequences worth stating because they look like bugs from the outside:
 *
 *  - **The owner is watermarked too.** There is no exemption for anybody. The watermark
 *    carries the reader's identity, and an owner reading their own file is a reader. (The
 *    share switches are the exception that proves it: they watermark a *recipient's* fetch
 *    of a file whose owner still gets it clean, because they are about the copy leaving,
 *    not about the file.)
 *  - **A marked file that cannot be rendered is not served at all.** See
 *    {@see WatermarkRequiredException}.
 */
class WatermarkService {

	public const SUPPORTED_PDF = ['application/pdf'];
	public const SUPPORTED_IMAGE = ['image/jpeg', 'image/png', 'image/webp'];
	public const SUPPORTED_ALL = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

	/** A user asked for this file to be marked, from the Files action menu. */
	public const TRIGGER_ON_DEMAND = 'on_demand';

	/** The file was written and the policy marks everything supported. */
	public const TRIGGER_ON_UPLOAD = 'on_upload';

	/** The two policies an admin can choose between. There are no others. */
	public const TRIGGERS = [self::TRIGGER_ON_DEMAND, self::TRIGGER_ON_UPLOAD];

	/**
	 * The mark came with a copy of an already-protected file - {@see inheritMark}.
	 *
	 * Not one of {@see TRIGGERS}: no admin chooses it, and it describes how one particular
	 * mark got here rather than a policy about which files get marked. It is the one trigger
	 * that is also load-bearing rather than audit - the remove gate reads it.
	 */
	public const TRIGGER_INHERITED = 'inherited';

	/**
	 * Audit-only triggers - recorded in `watermark_log`, never stored on a mark.
	 *
	 * `unmarked` is a user taking the mark off; `delivered` is one watermarked copy handed
	 * to one reader. Neither is a policy, which is why neither appears in TRIGGERS.
	 */
	public const TRIGGER_UNMARKED = 'unmarked';
	public const TRIGGER_DELIVERED = 'delivered';

	/**
	 * Audit-only: a file the policy would have marked and did not, because no watermark can
	 * be drawn on it - a PDF locked with a real password. See {@see PasswordProtectedPdfException}.
	 */
	public const TRIGGER_SKIPPED = 'skipped';

	/** What the renderers draw when a policy carries no template at all. */
	private const DEFAULT_TEMPLATE = '{username} {date}';

	/** The extension a render is written under, so an engine that infers the format from it agrees with the bytes. */
	private const EXTENSIONS = [
		'application/pdf' => 'pdf',
		'image/jpeg' => 'jpg',
		'image/png' => 'png',
		'image/webp' => 'webp',
	];

	/** {@see unmarkVerdict} - this user may take the mark off. */
	public const UNMARK_OK = 'ok';
	/** They do not own the file. */
	public const UNMARK_NOT_OWNER = 'not_owner';
	/** They own it, and the mark came from somebody else's file. */
	public const UNMARK_INHERITED = 'inherited';
	/** Nobody may: the policy is not `on_demand` - {@see marksAreRemovable}. */
	public const UNMARK_POLICY = 'policy';

	/** Per-request memo for {@see resolveConfig}. One policy, so one slot. */
	private ?WatermarkConfig $configCache = null;

	public function __construct(
		private WatermarkConfigMapper $configMapper,
		private WatermarkLogMapper $logMapper,
		private WatermarkMarkMapper $markMapper,
		private PdfWatermarker $pdfWatermarker,
		private PdfFlattener $pdfFlattener,
		private ImageWatermarker $imageWatermarker,
		private IUserSession $userSession,
		private ISystemTagObjectMapper $tagObjectMapper,
		private LoggerInterface $logger,
		private WatermarkImageStore $imageStore,
		private ImageLimits $imageLimits,
		private ApplyLimits $applyLimits,
		private ShareAccess $shareAccess,
		private ShareRecipient $shareRecipient,
		private IL10N $l,
		private InstanceTimeZone $timeZone,
		private ?DeliveryReservation $reservation = null,
		private ?IJobList $jobList = null,
		private ?AnonymousRenderLimit $renderLimit = null,
	) {
	}

	// -----------------------------------------------------------------------
	// Marking
	// -----------------------------------------------------------------------

	/**
	 * Mark $file so that every fetch of it is watermarked.
	 *
	 * The stored file is never written - the mark is one row. What is *read* depends on who
	 * is waiting:
	 *
	 *  - **`on_demand` renders the file once, before the mark goes in.** A person clicked
	 *    Apply and is waiting on the answer, so this is the moment to find out whether the
	 *    file can be watermarked at all: one that cannot is refused here, with a reason,
	 *    instead of being marked into a file every later download answers 403 for. The same
	 *    render is the owner's delivery measurement, so it costs nothing extra.
	 *  - **`on_upload` reads a few kilobytes**, and only of a PDF - enough to see whether it
	 *    might be encrypted ({@see PdfEncryptionProbe}). A document locked with a real
	 *    password is skipped and the skip is audited; everything else is marked, and its
	 *    measurement is queued ({@see MeasureDeliveryJob}) rather than rendered inside the
	 *    upload.
	 *
	 * The two size ceilings are enforced *here*, at the only moment where refusing is still
	 * a choice: past this point the file is promised a watermark on every fetch.
	 *
	 * @param string $trigger one of {@see TRIGGERS}
	 * @param ?IUser $actor who is marking; null falls back to the session user
	 * @return bool true when this call placed the mark, false when one was already there
	 *
	 * @throws FileTooLargeException|ImageTooLargeException the file is past a ceiling
	 * @throws PasswordProtectedPdfException the file is a PDF locked with a real password
	 * @throws \RuntimeException unsupported type, excluded by the policy's own scope, or -
	 *                           on demand - a file whose watermark could not be generated
	 */
	public function mark(File $file, string $trigger, ?IUser $actor = null, ?WatermarkConfig $config = null): bool {
		$config ??= $this->resolveConfig();

		$this->assertMarkable($file, $config);

		// Already marked: nothing to probe, nothing to render, nothing to insert. The unique
		// index still settles a race below; this only spares the common re-write the work.
		if ($this->isMarked($file->getId())) {
			return false;
		}

		$user = $actor ?? $this->userSession->getUser();

		$measured = null;
		try {
			if ($trigger === self::TRIGGER_ON_DEMAND) {
				$measured = $this->renderForMarking($file, $config, $user);
			} else {
				$this->refuseIfPasswordProtected($file, $config, $user);
			}

			$placed = $this->markMapper->mark(
				$file->getId(),
				$user?->getUID() ?? 'system',
				$trigger,
				$config->getId(),
				// Whose protection this is, fixed now: the file id survives a move into
				// somebody else's storage, and the owner does not. See unmarkVerdict().
				$this->ownerOf($file)?->getUID(),
			);

			if (!$placed) {
				// Marked between the check above and the insert. Not an error anywhere.
				return false;
			}

			$this->recordLog($file, $trigger, $config, $user);

			if ($measured !== null) {
				$this->reserveMeasured($file, $config, $measured);
			} else {
				$this->queueMeasurement($file);
			}

			return true;
		} finally {
			if ($measured !== null) {
				$this->discardTemp($measured['path']);
			}
		}
	}

	/**
	 * Render $file for its owner, as the on-demand action's proof that it can be watermarked.
	 *
	 * @return array{path: string, mime: string, uid: ?string} the render, which the caller
	 *                                                         owns and must discard
	 * @throws \RuntimeException naming why the file cannot be watermarked
	 */
	private function renderForMarking(File $file, WatermarkConfig $config, ?IUser $actor): array {
		$owner = $this->ownerOf($file) ?? $actor;
		$mime = $this->deliveryMime($file) ?? $file->getMimeType();

		try {
			[$path] = $this->renderToTemp($file, $config, $owner, $mime);
		} catch (PasswordProtectedPdfException $e) {
			$this->logMapper->insertLog(
				$actor?->getUID() ?? 'system',
				$file->getId(),
				$file->getPath(),
				self::TRIGGER_SKIPPED,
				$config->getId(),
			);
			throw new PasswordProtectedPdfException($this->passwordProtectedMessage(), 0, $e);
		} catch (\Throwable $e) {
			$this->logger->warning('files_watermark: refusing to mark {path}, its watermark could not be generated: {reason}', [
				'path' => $file->getPath(),
				'reason' => $e->getMessage(),
				'exception' => $e,
			]);
			throw new \RuntimeException($this->l->t('The watermark could not be generated for this file, so it was not marked.'), 0, $e);
		}

		return ['path' => $path, 'mime' => $mime, 'uid' => $owner?->getUID()];
	}

	/**
	 * Skip a PDF that is locked with a real password, and say so in the audit log.
	 *
	 * Reads a few kilobytes of every PDF and the whole of only those that mention `/Encrypt`
	 * - see {@see PdfEncryptionProbe}.
	 *
	 * @throws PasswordProtectedPdfException
	 */
	private function refuseIfPasswordProtected(File $file, WatermarkConfig $config, ?IUser $actor): void {
		if (!in_array($file->getMimeType(), self::SUPPORTED_PDF, true) || !PdfEncryptionProbe::mayBeEncrypted($file)) {
			return;
		}

		$dir = $this->createTempDir();
		$copy = $dir . '/source';
		try {
			$this->copySource(fn () => $file->fopen('rb'), $copy);
			$locked = $this->pdfWatermarker->isPasswordProtected($copy);
		} catch (\Throwable) {
			// Unreadable is not locked. The file is marked, and a delivery that cannot render
			// it refuses - the posture for every other broken document.
			$locked = false;
		} finally {
			$this->discardTemp($copy);
		}

		if (!$locked) {
			return;
		}

		$this->logMapper->insertLog(
			$actor?->getUID() ?? 'system',
			$file->getId(),
			$file->getPath(),
			self::TRIGGER_SKIPPED,
			$config->getId(),
		);

		throw new PasswordProtectedPdfException($this->passwordProtectedMessage());
	}

	private function passwordProtectedMessage(): string {
		return $this->l->t('This PDF is protected with a password, so it cannot be watermarked.');
	}

	/**
	 * Promise the owner the length the on-demand render just measured.
	 *
	 * @param array{path: string, mime: string, uid: ?string} $measured
	 */
	private function reserveMeasured(File $file, WatermarkConfig $config, array $measured): void {
		if ($this->reservation === null || $measured['uid'] === null || $measured['uid'] === '') {
			return;
		}

		$size = @filesize($measured['path']);
		if ($size === false || $size === 0) {
			return;
		}

		try {
			$this->reservation->record($file, $measured['uid'], $config, $size, $measured['mime']);
		} catch (\Throwable $e) {
			$this->logger->info('files_watermark: could not record a delivery length at mark time', [
				'exception' => $e,
				'path' => $file->getPath(),
			]);
		}
	}

	/**
	 * Leave the owner's delivery measurement to {@see MeasureDeliveryJob}.
	 *
	 * Never fails the mark: a queue that will not take the job costs the self-correcting
	 * first download a measurement exists to spare, and nothing worse.
	 */
	private function queueMeasurement(File $file): void {
		if ($this->jobList === null || $this->reservation === null) {
			return;
		}

		try {
			$this->jobList->add(MeasureDeliveryJob::class, ['fileId' => $file->getId()]);
		} catch (\Throwable $e) {
			$this->logger->info('files_watermark: could not queue a delivery measurement', [
				'exception' => $e,
				'path' => $file->getPath(),
			]);
		}
	}

	/**
	 * Measure $file's delivery length for its owner, now. The body of {@see MeasureDeliveryJob}.
	 */
	public function measure(File $file): void {
		$this->measureForDelivery($file, $this->resolveConfig());
	}

	private function ownerOf(File $file): ?IUser {
		try {
			return $file->getOwner();
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Take the mark off $file, so it is served as it is stored again.
	 *
	 * Instant, and complete: there is nothing to restore because nothing was ever
	 * overwritten. The removal is logged rather than the mark's own history being deleted -
	 * this is an audit trail, so the marking and the unmarking both belong in it.
	 *
	 * @return bool true when a mark was removed, false when there was none
	 */
	public function unmark(File $file): bool {
		if (!$this->markMapper->unmark($file->getId())) {
			return false;
		}

		// A promised length describes a watermarked download, and there is none now. The
		// listing already stops advertising it once the mark is gone; this keeps the row from
		// outliving the reason it was written.
		$this->reservation?->forget($file->getId());

		$this->logMapper->insertLog(
			$this->userSession->getUser()?->getUID() ?? 'system',
			$file->getId(),
			$file->getPath(),
			self::TRIGGER_UNMARKED,
			null,
		);

		return true;
	}

	/**
	 * Carry $source's protection onto $target, which is a copy of it.
	 *
	 * ---------------------------------------------------------------------------
	 * WHY A COPY HAS TO BE MARKED, AND WHY THIS ONE IGNORES EVERY CHECK `mark()` MAKES.
	 *
	 * Nothing this app does changes a stored file, so the bytes behind a marked file are the
	 * clean original and a copy of them is a clean original with a *new file id* - which the
	 * mark table knows nothing about. Copying was therefore a two-click way to obtain the
	 * unwatermarked document: copy the shared file into your own folder, download it. (A
	 * *move* was never this: core's cache moves the existing row, file id and all, so the
	 * mark travels with the file already.)
	 *
	 * `assertMarkable()` is deliberately not called:
	 *
	 *  - **the ceilings are already satisfied, by construction.** A copy has the same bytes
	 *    as its source, and the source passed both ceilings at the moment it was marked.
	 *  - **the scope must not be consulted.** Scope decides which files get marked *first*.
	 *    Re-asking it here would mean that copying a marked file out of the tagged folder it
	 *    lives in produces an unmarked clean copy - which is precisely the escape being
	 *    closed, rebuilt out of the scope check.
	 *
	 * The one case where the source was never checked at all - a file watermarked only
	 * because it was leaving through a share - is marked on the same terms. If such a file is
	 * over a ceiling its copy will 403 on download rather than render, and that is the
	 * app's posture everywhere else too: a protected file that cannot be watermarked is not
	 * served.
	 * ---------------------------------------------------------------------------
	 *
	 * @return bool true when this call placed the mark, false when the copy was already
	 *              marked or either id could not be resolved
	 */
	public function inheritMark(File $target, File $source): bool {
		$targetId = $target->getId();
		$sourceId = $source->getId();
		if ($targetId === null || $sourceId === null) {
			return false;
		}

		// A copy of a copy still names the person the protection started with, rather than
		// the intermediate holder: whoever copies it on is no more entitled to release it
		// than the first recipient was.
		$origin = $this->markMapper->findByFileId($sourceId)?->getOriginOwner()
			?? $source->getOwner()?->getUID();

		return $this->carryMark($target, $sourceId, $origin);
	}

	/**
	 * Place an inherited mark on $target, naming $origin as the person who may release it.
	 *
	 * {@see inheritMark} for a copy, and for a **move** that takes a protected file out of a
	 * share into somebody else's storage - where the source no longer exists to be asked
	 * whose it was, so the caller says ({@see \OCA\FilesWatermark\EventListener\NodeMovedListener}).
	 *
	 * @return bool true when this call placed the mark, false when $target was already marked
	 *              or has no id
	 */
	public function carryMark(File $target, int $sourceId, ?string $origin): bool {
		$targetId = $target->getId();
		if ($targetId === null) {
			return false;
		}

		$placed = $this->markMapper->mark(
			$targetId,
			$this->userSession->getUser()?->getUID() ?? 'system',
			self::TRIGGER_INHERITED,
			$this->resolveConfig()->getId(),
			$origin,
			$sourceId,
		);

		if (!$placed) {
			return false;
		}

		$this->recordLog($target, self::TRIGGER_INHERITED, $this->resolveConfig());
		// Queued, never rendered here: a folder copy calls this once per protected file, and
		// a render each would let the copy request run long enough to be killed before the
		// walk reached the rest of the tree - leaving those copies unmarked.
		$this->queueMeasurement($target);

		return true;
	}

	/**
	 * The subset of $fileIds whose mark $uid is barred from removing.
	 *
	 * The batched form of the same rule {@see unmarkVerdict} enforces, for the status
	 * endpoint that stands in for the DAV property on a listing that arrived without it.
	 * One query, and only marked files produce a row. Outside `on_demand` that is every
	 * one of them.
	 *
	 * @param int[] $fileIds
	 * @return int[]
	 */
	public function lockedFileIds(array $fileIds, string $uid): array {
		$marks = $this->markMapper->findByFileIds($fileIds);
		if (!$this->marksAreRemovable()) {
			return array_keys($marks);
		}

		$locked = [];
		foreach ($marks as $id => $mark) {
			if ($mark->isForeignTo($uid)) {
				$locked[] = $id;
			}
		}

		return $locked;
	}

	/**
	 * Whether $uid may take the mark off $file, and if not, why not.
	 *
	 * The ordinary rule is ownership - see `ApiController::removeWatermark`, which holds the
	 * reasoning. An **inherited** mark cannot use that rule, because copying is what makes
	 * the copier the owner: propagating the mark onto the copy and then letting its new owner
	 * remove it would hand over the protection and the means to drop it in one gesture. So an
	 * inherited mark names the person it descends from, and only they can release it.
	 *
	 * An inherited mark whose origin could not be resolved when it was placed names nobody,
	 * and is removable by nobody. That is the fail-closed direction, and it is the same call
	 * the ownership check itself makes for a node whose owner will not resolve.
	 *
	 * ---------------------------------------------------------------------------
	 * A DIRECT MARK IS ANSWERED BY WHO OWNED THE FILE WHEN IT WAS MARKED, NOT BY WHO OWNS IT NOW.
	 *
	 * A move keeps the file id, so the mark follows a file anywhere - including out of a
	 * shared folder and into the recipient's own home, which a recipient with delete
	 * permission can do in one request. The recipient then *owns* the file, and the
	 * ownership test passed them straight through: move, remove, download clean. So `mark()`
	 * records the owner, and that is who is asked. A mark recorded before that (origin null)
	 * still falls back to the current owner; the migration that shipped with this backfills
	 * those it can resolve.
	 * ---------------------------------------------------------------------------
	 *
	 * Ahead of all of that sits the policy: outside `on_demand` nobody may unmark anything,
	 * owner included - {@see marksAreRemovable}.
	 *
	 * @return string one of {@see UNMARK_OK}, {@see UNMARK_NOT_OWNER}, {@see UNMARK_INHERITED},
	 *                {@see UNMARK_POLICY}
	 */
	public function unmarkVerdict(File $file, string $uid): string {
		if (!$this->marksAreRemovable()) {
			return self::UNMARK_POLICY;
		}

		$id = $file->getId();
		$mark = $id === null ? null : $this->markMapper->findByFileId($id);

		if ($mark !== null && $mark->getTrigger() === self::TRIGGER_INHERITED) {
			return $mark->isForeignTo($uid) ? self::UNMARK_INHERITED : self::UNMARK_OK;
		}

		if ($mark !== null && $mark->getOriginOwner() !== null) {
			return $mark->isForeignTo($uid) ? self::UNMARK_NOT_OWNER : self::UNMARK_OK;
		}

		return $file->getOwner()?->getUID() === $uid ? self::UNMARK_OK : self::UNMARK_NOT_OWNER;
	}

	/**
	 * Whether the policy lets anybody take a mark off at all. Only `on_demand` does.
	 *
	 * ---------------------------------------------------------------------------
	 * UNDER `on_upload` THE MARK IS THE ADMIN'S DECISION, NOT THE OWNER'S.
	 *
	 * The owner rule in {@see unmarkVerdict} assumes the owner chose the mark, which is true
	 * under `on_demand` and false under `on_upload`, where the policy marks every supported
	 * file as it is written. The owner of a file is also the person who uploaded it, so
	 * letting them unmark it handed every user an off switch for the policy: upload, remove,
	 * download clean - or share it clean. Hiding the Files action was not enough on its own,
	 * because the API endpoint did not ask.
	 *
	 * A stored trigger this version does not recognise is refused too. Nothing is marked
	 * under it ({@see effectiveTrigger}), but it is not the policy that lets users decide,
	 * and an unrecognised value is not to be read as the permissive one.
	 * ---------------------------------------------------------------------------
	 *
	 * The raw trigger rather than {@see effectiveTrigger()}, which logs a warning per call
	 * for a stale value and is asked here once per row of a listing. Both agree on which
	 * value is `on_demand`, and that is the only question this asks.
	 */
	public function marksAreRemovable(): bool {
		return $this->resolveConfig()->getTrigger() === self::TRIGGER_ON_DEMAND;
	}

	public function isMarked(int $fileId): bool {
		return $this->markMapper->isMarked($fileId);
	}

	/**
	 * @param int[] $fileIds
	 * @return int[] the subset that is marked
	 */
	public function markedFileIds(array $fileIds): array {
		return $this->markMapper->markedFileIds($fileIds);
	}

	/**
	 * The recorded origin of each marked file among $fileIds - who may release it.
	 *
	 * Only marked files have a key; a mark that recorded nobody maps to null.
	 *
	 * @param int[] $fileIds
	 * @return array<int, ?string>
	 */
	public function markOrigins(array $fileIds): array {
		$origins = [];
		foreach ($this->markMapper->findByFileIds($fileIds) as $id => $mark) {
			$origins[$id] = $mark->getOriginOwner();
		}

		return $origins;
	}

	/**
	 * Throws unless $file may be marked: supported type, inside the policy's scope, and
	 * under both ceilings.
	 *
	 * The scope checks (MIME whitelist, folder tag) live here and **only** here. They decide
	 * which files get marked; they are deliberately not consulted again at delivery, because
	 * the mark is the decision. An admin who narrows the whitelist afterwards has changed
	 * what gets marked next, not disowned the marks already placed - and a marked file that
	 * silently stopped being watermarked when someone moved it out of a tagged folder is the
	 * failure this app exists to prevent.
	 *
	 * @throws FileTooLargeException|ImageTooLargeException|\RuntimeException
	 */
	public function assertMarkable(File $file, ?WatermarkConfig $config = null): void {
		$config ??= $this->resolveConfig();

		$mime = $file->getMimeType();
		$this->assertSupported($mime, $file);
		$this->assertMimeAllowed($mime, $config);
		$this->assertFolderTagMatches($file, $config);
		$this->assertSizeAllowed($file);
		$this->assertPixelsAllowedFromHeader($file);
	}

	// -----------------------------------------------------------------------
	// Delivery
	// -----------------------------------------------------------------------

	/**
	 * Render the watermarked copy to serve for this fetch of $file, or null when the file
	 * is not marked and should be served as stored.
	 *
	 * The identity is resolved per call, which is the point of the whole design - see
	 * {@see buildPlaceholders}.
	 *
	 * @param bool $chargeRender false when the caller has already charged this request against
	 *                           {@see AnonymousRenderLimit} - an archive, which renders many
	 *                           members and is charged once
	 * @return string|null path of a temp copy the caller owns and must delete, or null when
	 *                     the file carries no mark
	 * @throws WatermarkRequiredException the file is marked and the render failed; the
	 *                                    caller must deny the fetch rather than serve the original
	 * @throws RenderRateLimitedException an anonymous address has used up its renders; the
	 *                                    caller answers 429, and the original is not served
	 */
	public function watermarkForDownload(File $file, bool $chargeRender = true): ?string {
		$id = $file->getId();
		$marked = $id !== null && $this->isMarked($id);

		// **The type is asked of the bytes, for both reasons a fetch is watermarked.** A
		// rename changes the cached MIME - the server derives it from the name - so trusting
		// it handed anyone who could rename a protected file a one-click way to fetch it
		// clean: `report.pdf` to `report.txt`, download, rename back. That was closed for
		// marked files first; a file watermarked only because it leaves through a share had
		// the same hole until {@see shareForcedMime} learned to sniff as well.
		$mime = $marked ? $this->deliveryMime($file) : $this->shareForcedMime($file);

		if ($mime === null) {
			return null;
		}

		// Charged only now, once this fetch is known to cost a render - an unprotected file
		// served by core costs no more here than it does anywhere else. Outside the try below,
		// which would otherwise turn a temporary refusal into a permanent-looking 403.
		if ($chargeRender) {
			$this->chargeAnonymousRender();
		}

		try {
			$config = $this->resolveConfig();
			// **Checked at every delivery, marked or not.** A mark survives an overwrite, so
			// the bytes being rendered are not necessarily the bytes that passed the ceiling
			// when the file was marked - and a file far past it would be pulled through a
			// render all the same. It throws, and the catch below turns that into the same
			// denial a failed render produces: the file is not served clean.
			$this->assertSizeAllowed($file);
			[$tmpPath, $resolved] = $this->renderToTemp($file, $config, null, $mime);
			$this->recordLog($file, self::TRIGGER_DELIVERED, $resolved);

			return $tmpPath;
		} catch (\Throwable $e) {
			$this->logger->error('files_watermark: failed to watermark on delivery: ' . $e->getMessage(), [
				'exception' => $e,
				'path' => $file->getPath(),
			]);

			throw new WatermarkRequiredException(
				$file->getPath(),
				$this->l->t('This file is watermarked on download, and the watermark could not be generated.'),
				$e,
			);
		}
	}

	/**
	 * Render the watermarked copy to serve for one **old version** of $source, or null when
	 * $source is not protected and the version should be served as stored.
	 *
	 * ---------------------------------------------------------------------------
	 * A VERSION IS THE SAME DOCUMENT, AND IT WAS THE OTHER WAY OUT.
	 *
	 * Nothing this app does writes to storage, so every version `files_versions` keeps of a
	 * marked file is a clean copy of it - and the Versions tab offers each one for download,
	 * to the owner and to every share recipient. The version node has a file id of its own,
	 * which no mark names, so nothing stood in the way.
	 *
	 * The decision is therefore asked of the *source* file - its mark, and whether this
	 * reader's fetch of it is a share the policy watermarks - and the bytes rendered are the
	 * version's. The watermark names the reader exactly as a download of the current file
	 * would.
	 * ---------------------------------------------------------------------------
	 *
	 * A protected file's version that cannot be rendered - a type this app does not draw on,
	 * or a failed render - is refused rather than served, the same posture as the file itself.
	 *
	 * @param File $source the versioned file, as the reader sees it
	 * @param callable():mixed $openVersion a fresh read stream of the version's bytes per call
	 * @return ?string path of a temp copy the caller owns and must delete
	 * @throws WatermarkRequiredException
	 * @throws RenderRateLimitedException see {@see watermarkForDownload}
	 */
	public function watermarkVersionForDownload(
		File $source,
		callable $openVersion,
		string $versionMime,
		int|float $versionSize,
	): ?string {
		$id = $source->getId();
		$protected = ($id !== null && $this->isMarked($id)) || $this->isForcedByShare($source);
		if (!$protected) {
			return null;
		}

		$this->chargeAnonymousRender();

		try {
			$mime = $this->isSupported($versionMime)
				? $versionMime
				: $this->sniffStream($openVersion, $source->getPath());
			if ($mime === null) {
				throw new \RuntimeException('this version is not a type the app can watermark');
			}

			$this->assertBytesAllowed((int)$versionSize);
			$config = $this->resolveConfig();
			[$tmpPath, $resolved] = $this->renderToTemp($source, $config, null, $mime, $openVersion);
			$this->recordLog($source, self::TRIGGER_DELIVERED, $resolved);

			return $tmpPath;
		} catch (\Throwable $e) {
			$this->logger->error('files_watermark: failed to watermark a version on delivery: ' . $e->getMessage(), [
				'exception' => $e,
				'path' => $source->getPath(),
			]);

			throw new WatermarkRequiredException(
				$source->getPath(),
				$this->l->t('This file is watermarked on download, and the watermark could not be generated.'),
				$e,
			);
		}
	}

	/**
	 * Count one render against an anonymous caller's allowance - see {@see AnonymousRenderLimit}.
	 *
	 * Public so an archive can charge once for the whole request and then render its members
	 * with `$chargeRender = false`. A no-op for a signed-in reader.
	 *
	 * @throws RenderRateLimitedException
	 */
	public function chargeAnonymousRender(): void {
		$this->renderLimit?->charge();
	}

	/**
	 * Whether this fetch of $node has to be watermarked.
	 *
	 * Two independent reasons, and it matters that they are independent:
	 *
	 *  - **The file carries a mark.** Nothing about *who is asking* enters into it. Owner,
	 *    share recipient and public-link visitor are all readers, and the mark says every
	 *    reader gets their own copy. The reader only decides what the watermark says.
	 *  - **The fetch is coming through a share the policy watermarks** - see
	 *    {@see isForcedByShare}. Here it is *only* about who is asking: the same file
	 *    downloaded by its owner is served exactly as it is stored.
	 */
	public function isDeliveryCandidate(FileInfo $node): bool {
		$id = $node->getId();

		if ($id !== null && $this->isMarked($id)) {
			if ($this->isSupported($node->getMimetype())) {
				return true;
			}

			// The *name* says this is nothing we can watermark, so ask the bytes - and only a
			// `File` can be asked. {@see deliveryMime}.
			return $node instanceof File && $this->deliveryMime($node) !== null;
		}

		// The share switches sniff a misleading name too, but only once a switch actually
		// claims this fetch - an unmarked file outside a share still costs one indexed lookup
		// and no read at all.
		return $this->isForcedByShare($node);
	}

	/**
	 * The type to watermark $file as, or null when it is nothing this app can render.
	 *
	 * ---------------------------------------------------------------------------
	 * THE NAME IS NOT THE TYPE.
	 *
	 * Nextcloud derives a file's MIME type from its **extension** and re-derives it on every
	 * rename, so `getMimeType()` reports what a file is called, not what it holds. For a
	 * marked file that difference is a hole rather than a detail: a mark is a durable
	 * statement about a file id, it survives the rename by design, and every other part of
	 * this app went on treating the file as marked - the Files list still showed the badge -
	 * while delivery quietly decided it was a text file and served the stored bytes. Rename
	 * `q3-report.pdf` to `q3-report.txt`, download the clean original, rename it back.
	 *
	 * So the cached type is taken only as a *hint*, and the bytes are consulted whenever the
	 * hint says nothing renderable. That ordering is what keeps it cheap: a marked PDF still
	 * called `.pdf` never opens the file here, and the read only happens on the path where
	 * the alternative is handing over an unwatermarked copy.
	 * ---------------------------------------------------------------------------
	 *
	 * The reverse case needs no special handling: a marked `.pdf` whose *content* was
	 * replaced with something unrenderable still reports `application/pdf`, is still rendered
	 * here, and the render's failure is what refuses the download - fail-closed, as before.
	 */
	public function deliveryMime(File $file): ?string {
		$mime = $file->getMimeType();
		if ($this->isSupported($mime)) {
			return $mime;
		}

		return $this->sniffMime($file);
	}

	/**
	 * How much of a file to read to recognise its format.
	 *
	 * Every signature below lives in the first dozen bytes; the rest of the window is for
	 * the PDF header, which is allowed to sit behind a little leading junk. Deliberately
	 * nothing like {@see HEADER_BYTES} - that one hunts for a JPEG's dimensions past
	 * kilobytes of EXIF, this one only has to answer "which of four formats is this".
	 */
	private const SNIFF_BYTES = 1024;

	/**
	 * The supported type $file's leading bytes actually declare, or null for anything else.
	 *
	 * Only the four types this app renders are recognised, and each by the signature its
	 * format puts at a fixed place - this is a router, not a MIME database, and a type it
	 * cannot name is one the renderers could not have drawn on anyway.
	 */
	private function sniffMime(File $file): ?string {
		return $this->sniffStream(fn () => $file->fopen('rb'), $file->getPath());
	}

	/**
	 * {@see sniffMime}, for bytes that are not the current content of a `File` - an old
	 * version, most of all.
	 *
	 * @param callable():mixed $open returns a fresh read stream
	 */
	private function sniffStream(callable $open, string $path): ?string {
		try {
			$handle = $open();
			if (!is_resource($handle)) {
				return null;
			}
			$header = fread($handle, self::SNIFF_BYTES);
			fclose($handle);
		} catch (\Exception $e) {
			// Storage that will not open is the caller's problem to report. Answering "not
			// renderable" here would turn an unreadable file into an unwatermarked download,
			// which is the one answer this method exists to prevent - but the caller cannot
			// render bytes it cannot read either, so it fails on its own a moment later.
			//
			// `\Exception` and not `\Throwable`: everything storage throws is one, and a
			// swallowed `\Error` here is a bug in this method that presents as files quietly
			// going out clean. That is precisely the failure this whole path exists to close,
			// so it is left to crash loudly instead.
			$this->logger->warning('files_watermark: could not read {path} to detect its type: {reason}', [
				'path' => $path,
				'reason' => $e->getMessage(),
				'exception' => $e,
			]);

			return null;
		}

		if ($header === false || $header === '') {
			return null;
		}

		if (str_starts_with($header, "\xFF\xD8\xFF")) {
			return 'image/jpeg';
		}

		if (str_starts_with($header, "\x89PNG\x0D\x0A\x1A\x0A")) {
			return 'image/png';
		}

		if (str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP') {
			return 'image/webp';
		}

		// Not anchored at byte 0: the header may carry a byte-order mark or a few bytes of
		// junk ahead of the signature, and every PDF reader in existence - including the
		// parser this app hands the file to - accepts that. Matching stricter here would
		// refuse to watermark a file that then downloads and opens perfectly.
		if (strpos($header, '%PDF-') !== false) {
			return 'application/pdf';
		}

		return null;
	}

	/**
	 * Whether the policy watermarks this fetch **because it is a share**, mark or no mark.
	 *
	 * ---------------------------------------------------------------------------
	 * A SWITCH ON THE FETCH, NOT A THIRD TRIGGER.
	 *
	 * The two triggers decide which files are *marked*, and a mark is a durable statement
	 * about a file: placed once, it follows the file everywhere until somebody removes it.
	 * These two switches say something narrower and entirely reversible - "a copy that leaves
	 * through a share carries a watermark" - and they are therefore evaluated here, per fetch,
	 * against no stored state at all. Ticking one starts watermarking shared files
	 * immediately; unticking it stops, with nothing left behind to unmark.
	 *
	 * That is also why an admin can run both at once: `on_demand` marking for the documents
	 * somebody deliberately protects, plus a blanket watermark on everything that goes out
	 * through a link.
	 * ---------------------------------------------------------------------------
	 *
	 * **The policy's scope applies here and the ceilings do not.** Scope (the MIME whitelist,
	 * the folder tag) is the admin saying which files this policy is about at all, so a file
	 * outside it is not watermarked by any route. The ceilings are a different question -
	 * they bound what one render may cost - and they are checked in
	 * {@see watermarkForDownload}, at the point where exceeding one has to become a refusal.
	 */
	public function isForcedByShare(FileInfo $node, bool $inspectContent = true): bool {
		return $this->shareForcedMime($node, $inspectContent) !== null;
	}

	/**
	 * The type a share-forced fetch of $node is rendered as, or null when the share switches
	 * do not claim this fetch.
	 *
	 * **A misleading name is seen through, as it is for a marked file.** With only the cached
	 * type to go on, renaming `report.pdf` to `report.txt` took a shared file out of both the
	 * switch and the scope at once and downloaded it clean. The bytes are only read once a
	 * switch has claimed the fetch *and* the name says nothing renderable, so a file outside a
	 * share - every file the owner lists - is never opened here.
	 *
	 * @param bool $inspectContent false to trust the name, for a caller that would otherwise
	 *                             open every unsupported file in a listing - the
	 *                             `is-watermarked` badge. Delivery always inspects.
	 */
	private function shareForcedMime(FileInfo $node, bool $inspectContent = true): ?string {
		$config = $this->resolveConfig();

		$forced = ($config->getWatermarkExternalShares() && $this->shareAccess->isExternalShareAccess())
			|| ($config->getWatermarkInternalShares() && $this->shareAccess->isInternalShareAccess($node));
		if (!$forced) {
			return null;
		}

		$mime = $node->getMimetype();
		if (!$this->isSupported($mime)) {
			if (!$inspectContent || !($node instanceof File)) {
				return null;
			}
			$mime = $this->sniffMime($node);
			if ($mime === null) {
				return null;
			}
		}

		return $this->isInScope($node, $config, $mime) ? $mime : null;
	}

	/**
	 * Whether $node is inside the policy's own scope.
	 *
	 * The same two exclusions {@see assertMarkable} applies before placing a mark, asked as a
	 * question rather than as an assertion because there is no mark to refuse here - the
	 * answer decides whether this fetch is watermarked, and "no" means serve the file as it
	 * is stored.
	 *
	 * **The type tested is what the file holds**, not what it is called - see
	 * {@see shareForcedMime}. A whitelist of `image/png` still excludes a PDF renamed to
	 * `.png`, and still includes a PNG renamed to `.txt`.
	 *
	 * **The folder tag is only checked for a real node.** It reads the file's parent, which
	 * `FileInfo` cannot give us; every delivery path in this app passes a `File`, so the
	 * fallback is theoretical, and it is deliberately the *inclusive* one - a scope test this
	 * cannot perform must not quietly hand out a clean copy of a shared file.
	 */
	private function isInScope(FileInfo $node, WatermarkConfig $config, string $mime): bool {
		try {
			$this->assertMimeAllowed($mime, $config);
			if ($node instanceof File) {
				$this->assertFolderTagMatches($node, $config);
			}
		} catch (\RuntimeException) {
			return false;
		}

		return true;
	}

	/**
	 * Stamp an already-rendered preview image with $file's watermark.
	 *
	 * ---------------------------------------------------------------------------
	 * WHY A PREVIEW IS STAMPED RATHER THAN RENDERED.
	 *
	 * The obvious implementation - watermark the file, then downscale the result - is the
	 * one that matches the download exactly, and it is unaffordable: a folder of 200 photos
	 * would render 200 full-size images to produce 200 thumbnails, on one worker, before the
	 * file list paints. So core renders the clean preview (which it caches, and which no
	 * client can reach except through the endpoints this app intercepts) and this stamps that
	 * image, per request, for whoever is asking.
	 *
	 * **The font is scaled to the preview**, against a 1000px reference: a watermark
	 * configured at 24pt for a full-size page is meaningless at 64px, and drawn unscaled it
	 * covers the thumbnail with two letters. Scaling keeps the mark occupying the same
	 * fraction of the image at every size. Below the floor the text stops being legible - a
	 * 64px thumbnail cannot carry a readable name - and that is accepted rather than worked
	 * around: an illegible smear over a protected file's thumbnail is the safe end of the
	 * trade, and refusing the preview outright would leave a file that looks broken in the
	 * Files list.
	 * ---------------------------------------------------------------------------
	 *
	 * @param int $shorterSide the preview's smaller dimension in pixels, which is what the
	 *                         font is scaled against
	 * @throws \Throwable whatever the renderer raises; the caller fails closed
	 */
	public function watermarkPreviewImage(File $file, string $srcPath, string $destPath, int $shorterSide): void {
		$config = $this->scaledForPreview($this->resolveConfig(), $shorterSide);
		$placeholders = $this->buildPlaceholders($file);

		$logoTmp = $this->imageStore->localPath($config->getImagePath());
		$config = $this->withImagePath($config, $logoTmp);

		try {
			$this->assertDrawable($config, $placeholders);
			$this->imageWatermarker->apply($srcPath, $destPath, $config, $placeholders);
		} finally {
			$this->discardLogo($logoTmp);
		}
	}

	/** The reference width the preview font scale is expressed against. */
	private const PREVIEW_REFERENCE_PX = 1000;

	/** Smallest font GD will draw as anything other than a blob. */
	private const PREVIEW_MIN_FONT_PX = 6;

	/**
	 * $config with its font size scaled for a preview whose smaller side is $shorterSide.
	 *
	 * Copied rather than mutated - this is the entity the mapper handed us, and it is
	 * memoised for the request, so scaling it in place would shrink the watermark on every
	 * later render in the same request.
	 */
	private function scaledForPreview(WatermarkConfig $config, int $shorterSide): WatermarkConfig {
		$scaled = clone $config;
		$scaled->setFontSize(max(
			self::PREVIEW_MIN_FONT_PX,
			(int)round($config->getFontSize() * $shorterSide / self::PREVIEW_REFERENCE_PX),
		));

		return $scaled;
	}

	/**
	 * Render the file once, now, so its download has a promised length before anyone asks.
	 *
	 * ---------------------------------------------------------------------------
	 * THE ALTERNATIVE IS THAT THE FIRST OPEN OF EVERY MARKED FILE FAILS.
	 *
	 * A reservation is what lets PROPFIND advertise the length a watermarked download will
	 * really have ({@see DeliveryReservation}). Measured lazily - on the first download - it
	 * arrives one fetch too late: discovery has already published the *stored* length, a
	 * Windows client has already sized its virtual-file placeholder from it, and the first
	 * hydration fails with `The cloud operation is invalid` before the measurement exists.
	 * It self-corrects on the next sync, which is no comfort to the person looking at the
	 * error.
	 *
	 * Marking is the right moment because it is the first moment: the file is not yet
	 * advertised as watermarked to anybody, so there is no wrong number in flight to correct.
	 * ---------------------------------------------------------------------------
	 *
	 * **Measured for the owner, and rendered as the owner.** The reservation is per reader
	 * because the watermark draws the reader's own name and the name's length moves the
	 * output's size, so a measurement is only good for the person it was rendered for. The
	 * owner is the one whose sync client holds the file; a share recipient opening it for the
	 * first time is still measured lazily, and still costs one self-correcting failure.
	 *
	 * **Best-effort in every direction.** Nothing here may turn marking a file into an error:
	 * the lazy path this pre-empts is still there and still works, so a failure costs the
	 * first-open glitch and nothing more. The file is known to be within the render ceilings
	 * already - {@see assertMarkable} enforced them a moment ago, which is what stands in for
	 * a size guard here - so the cost is bounded by the same policy that allowed the mark.
	 */
	private function measureForDelivery(File $file, WatermarkConfig $config): void {
		if ($this->reservation === null) {
			return;
		}

		$owner = $file->getOwner() ?? $this->userSession->getUser();
		$uid = $owner?->getUID();
		if ($uid === null || $uid === '') {
			return;
		}

		$tmpPath = null;
		try {
			$mime = $this->deliveryMime($file);
			if ($mime === null) {
				return;
			}

			[$tmpPath] = $this->renderToTemp($file, $config, $owner, $mime);

			$rendered = @filesize($tmpPath);
			if ($rendered === false || $rendered === 0) {
				// A render that produced nothing to count is not a measurement. Promising a
				// length from it would be worse than having none.
				return;
			}

			$this->reservation->record($file, $uid, $config, $rendered, $mime);
		} catch (\Throwable $e) {
			$this->logger->info('files_watermark: could not measure a delivery length at mark time', [
				'exception' => $e,
				'path' => $file->getPath(),
			]);
		} finally {
			// This render exists only to be counted; nothing is served from it.
			if ($tmpPath !== null) {
				$this->discardTemp($tmpPath);
			}
		}
	}

	/**
	 * Render a watermarked copy of $file to a temp path.
	 *
	 * **The working names are the app's own, never the file's.** They used to be the stored
	 * name plus `_src` / `_flat`, which put a 255-byte name - about 127 Arabic characters -
	 * four bytes past what the filesystem allows, so the copy silently failed to write and
	 * every download of such a file was refused.
	 *
	 * **The source is streamed to disk, not loaded.** `getContent()` held the whole file in
	 * PHP memory before the renderer had even started.
	 *
	 * @param ?string $mime the type to render as, when the caller has already resolved it
	 *                      from the bytes ({@see deliveryMime}); null falls back to the
	 *                      cached type, which is derived from the file's name
	 * @param ?callable():mixed $openSource where the bytes come from when they are not the
	 *                                      file's current content - an old version
	 * @return array{0: string, 1: WatermarkConfig} the temp path and the config the render
	 *                                              resolved to (callers need its id for the audit row)
	 */
	private function renderToTemp(
		File $file,
		WatermarkConfig $config,
		?IUser $actor = null,
		?string $mime = null,
		?callable $openSource = null,
	): array {
		$mime ??= $file->getMimeType();
		$this->assertSupported($mime, $file);

		$placeholders = $this->buildPlaceholders($file, $actor);
		$dir = $this->createTempDir();
		$tmpPath = $dir . '/render.' . self::EXTENSIONS[$mime];
		$srcTmp = $dir . '/source';

		$logoTmp = null;
		try {
			$this->copySource($openSource ?? fn () => $file->fopen('rb'), $srcTmp);

			// The renderers open the logo as a plain file path, but the config only holds an
			// opaque reference to an uploaded image - resolve it to a temp copy for the render
			// and hand them a config pointing at that. Anything the store does not recognise
			// (notably a legacy hand-typed absolute path) resolves to null rather than reading
			// the server's filesystem - and a policy left with nothing to draw is refused
			// below rather than rendered as a "watermarked" file carrying no watermark.
			$logoTmp = $this->imageStore->localPath($config->getImagePath());
			$config = $this->withImagePath($config, $logoTmp);
			$this->assertDrawable($config, $placeholders);

			if (in_array($mime, self::SUPPORTED_PDF, true)) {
				$this->pdfWatermarker->apply($srcTmp, $tmpPath, $config, $placeholders);
				$this->flattenInPlace($tmpPath, $config);
			} else {
				// Inside the try, so the throw goes out through the same cleanup as a
				// failed render - $srcTmp is a plaintext copy of the user's file and must
				// not be left behind by a path that exists to refuse work.
				//
				// Checked again here even though marking checked it: settled that an
				// overwrite keeps the mark, so the bytes being rendered are not necessarily
				// the bytes that were measured. This is the check that meets the file that
				// actually arrived.
				$this->assertPixelsAllowed($srcTmp);
				$this->imageWatermarker->apply($srcTmp, $tmpPath, $config, $placeholders);
			}
		} catch (\Throwable $e) {
			// $srcTmp holds a plaintext copy of the file. A render failure is routine
			// (unparseable PDFs above all), so without this it accumulates readable copies
			// of user content in the temp dir indefinitely - the caller only ever gets an
			// exception, never a path it could clean up itself.
			$this->discardTemp($tmpPath, $srcTmp);
			throw $e;
		} finally {
			$this->discardLogo($logoTmp);
		}

		@unlink($srcTmp);

		return [$tmpPath, $config];
	}

	/**
	 * Copy the bytes $open hands back to $dest, a stream at a time.
	 *
	 * @param callable():mixed $open returns a fresh read stream
	 * @throws \RuntimeException when the source will not open or the copy comes up short
	 */
	private function copySource(callable $open, string $dest): void {
		$in = $open();
		if (!is_resource($in)) {
			throw new \RuntimeException('the file could not be opened for reading');
		}

		$out = @fopen($dest, 'wb');
		if ($out === false) {
			fclose($in);
			throw new \RuntimeException('the working copy could not be created');
		}

		try {
			if (stream_copy_to_stream($in, $out) === false) {
				throw new \RuntimeException('the file could not be copied for rendering');
			}
		} finally {
			fclose($in);
			fclose($out);
		}
	}

	/**
	 * Refuse a render that would draw nothing at all.
	 *
	 * ---------------------------------------------------------------------------
	 * A WATERMARKED FILE WITH NO WATERMARK IS THE WORST OUTCOME AVAILABLE.
	 *
	 * Both renderers skip what they cannot draw - a logo that did not resolve, a template
	 * that came out blank - and say nothing. An `image` policy whose logo was deleted, or a
	 * `text` policy whose template is empty or resolves to nothing for this reader, therefore
	 * rendered successfully, was served as watermarked and audited as delivered, and carried
	 * not one changed pixel. Every other failure in this app refuses the fetch; this one
	 * handed out the clean document while reporting the opposite.
	 * ---------------------------------------------------------------------------
	 *
	 * A `combined` policy that has lost only its logo still names the reader, so it renders
	 * the text and the missing logo is logged - the watermark that matters is still there.
	 *
	 * @param array<string, string> $placeholders
	 * @throws \RuntimeException
	 */
	private function assertDrawable(WatermarkConfig $config, array $placeholders): void {
		$type = $config->getType();
		$wantsText = in_array($type, ['text', 'combined'], true);
		$wantsLogo = in_array($type, ['image', 'combined'], true);

		$drawsText = $wantsText && trim($this->resolveTemplate($config, $placeholders)) !== '';
		$drawsLogo = $wantsLogo && $config->getImagePath() !== null;

		if ($wantsLogo && !$drawsLogo) {
			$this->logger->warning('files_watermark: the watermark policy {config} has no usable logo; re-upload it in the watermark settings', [
				'app' => 'files_watermark',
				'config' => $config->getId(),
			]);
		}

		if (!$drawsText && !$drawsLogo) {
			throw new \RuntimeException('the watermark policy draws nothing for this file: no text resolved and no logo is available');
		}
	}

	/**
	 * The text the renderers will draw, by the same rule they apply.
	 *
	 * @param array<string, string> $placeholders
	 */
	private function resolveTemplate(WatermarkConfig $config, array $placeholders): string {
		$replace = [];
		foreach ($placeholders as $key => $value) {
			$replace['{' . $key . '}'] = $value;
		}

		return strtr($config->getTextTemplate() ?? self::DEFAULT_TEMPLATE, $replace);
	}

	/**
	 * Rasterise the freshly watermarked PDF at $tmpPath, replacing it in place.
	 *
	 * Applied *after* the overlay, which is the whole point - the watermark has to be in
	 * the pixels being captured. See {@see PdfFlattener} for what that buys and costs.
	 *
	 * ---------------------------------------------------------------------------
	 * **THIS FALLS BACK; IT DOES NOT FAIL CLOSED.**
	 *
	 * Every way this can go wrong - the policy asks for flattening on a host with no
	 * `pdftoppm`, the renderer fails on one page, the rebuild cannot be written - leaves
	 * the ordinary overlay-watermarked file at `$tmpPath` and lets the fetch proceed with
	 * it. Nothing is thrown from here.
	 *
	 * That is a deliberate reversal of how this behaved when the feature last existed,
	 * where a failed flatten refused the download outright on the grounds that the
	 * overlay-only PDF is the removable version the setting exists to avoid handing out.
	 * The trade is now read the other way: flattening is **hardening on top of a watermark
	 * that is already there and already names its reader**, and a rasteriser that is
	 * missing or that chokes on one document is not a reason to make a marked file
	 * undownloadable. The file still carries the watermark; it carries a strippable one.
	 *
	 * The fallback is loud rather than silent - a `warning` per failure, naming the config
	 * - because "some downloads are flattened and some are not" is otherwise invisible to
	 * an admin looking at the setting.
	 * ---------------------------------------------------------------------------
	 */
	private function flattenInPlace(string $tmpPath, WatermarkConfig $config): void {
		if (!$config->getFlattenPdf()) {
			return;
		}

		if (!$this->pdfFlattener->isAvailable()) {
			// A config can carry `flattenPdf` onto a host with no rasteriser - a restore, a
			// host migration, or someone removing the package. The admin UI hides the control
			// in that state, so it cannot even be switched off from the browser; the stored
			// column therefore keeps its value and comes back intact if the package does.
			$this->logger->warning(
				'files_watermark: policy {config} asks for flattened PDFs but ' . PdfFlattener::RENDERER
					. ' is not available; delivering the overlay watermark instead. Install poppler-utils.',
				['app' => 'files_watermark', 'config' => $config->getId()],
			);
			return;
		}

		$flattened = $tmpPath . '_flat';
		try {
			$this->pdfFlattener->flatten($tmpPath, $flattened);
			// Silenced because the failure is handled below and reported with more context
			// than the PHP warning carries; an unsilenced one would also land in the log as
			// an error on a path that recovers.
			if (!@rename($flattened, $tmpPath)) {
				throw new \RuntimeException('the rebuilt file could not replace the watermarked one');
			}
		} catch (\Throwable $e) {
			// The flattener writes nothing on failure, but a failed `rename` is the one path
			// that can leave a finished rebuild behind.
			if (file_exists($flattened)) {
				@unlink($flattened);
			}

			$this->logger->warning(
				'files_watermark: could not flatten {path}; delivering the overlay watermark instead: {reason}',
				[
					'app' => 'files_watermark',
					'path' => $tmpPath,
					'reason' => $e->getMessage(),
					'exception' => $e,
				],
			);
		}
	}

	/**
	 * Record an audit row.
	 *
	 * Delivery rows are recorded only when the policy asks for them: they are written per
	 * *fetch*, so an archive of 200 members downloaded twice a day is 400 rows a day,
	 * forever. Mark and unmark rows fall through unconditionally - there is one per policy
	 * decision, not one per read.
	 */
	private function recordLog(File $file, string $trigger, WatermarkConfig $config, ?IUser $actor = null): void {
		if ($trigger === self::TRIGGER_DELIVERED && !$config->getLogDelivery()) {
			return;
		}

		$user = $actor ?? $this->userSession->getUser();
		$this->logMapper->insertLog(
			$user?->getUID() ?? $this->readerIdentity($file)?->getUID() ?? 'system',
			$file->getId(),
			$file->getPath(),
			$trigger,
			$config->getId(),
		);
	}

	// -----------------------------------------------------------------------
	// Policy
	// -----------------------------------------------------------------------

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

	/**
	 * The trigger in force, or null when the stored one is not a trigger this app has.
	 *
	 * Null is the honest answer for a policy row left behind by an older version, which had
	 * two further triggers that no longer exist. Nothing marks under it - an unrecognised
	 * value must not be *approximately* one of the live ones, because the two candidates
	 * differ in whether every upload on the instance gets marked.
	 */
	public function effectiveTrigger(): ?string {
		$trigger = $this->resolveConfig()->getTrigger();

		if (!in_array($trigger, self::TRIGGERS, true)) {
			$this->logger->warning(
				'files_watermark: the saved policy has trigger "{trigger}", which this version does not '
					. 'have. Nothing is being marked. Re-pick a trigger in the watermark settings.',
				['trigger' => $trigger],
			);
			return null;
		}

		return $trigger;
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
		$config->setColor('#d3d3d3');
		$config->setRotation(45);
		$config->setTrigger(self::TRIGGER_ON_DEMAND);
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

	// -----------------------------------------------------------------------
	// Ceilings
	// -----------------------------------------------------------------------

	/**
	 * Throws if the file is larger than a render is allowed to be.
	 *
	 * Read from the file cache, so no content is touched: the point of a ceiling is to
	 * refuse the work, and anything that loads the file first has already spent what this
	 * exists to save.
	 *
	 * @throws FileTooLargeException
	 */
	private function assertSizeAllowed(File $file): void {
		// `getSize()` is documented as float|int - the cache widens it so a size can
		// outrun a 32-bit int. Narrowed once here rather than at each use.
		$this->assertBytesAllowed((int)$file->getSize());
	}

	/**
	 * {@see assertSizeAllowed}, for a byte count that is not a `File`'s - an old version's.
	 *
	 * @throws FileTooLargeException
	 */
	private function assertBytesAllowed(int $size): void {
		$maxBytes = $this->applyLimits->maxBytes();

		if ($size <= $maxBytes) {
			return;
		}

		throw new FileTooLargeException($this->l->t(
			'This file is too large to watermark (%1$s; the limit is %2$s).',
			[$this->humanBytes($size), $this->humanBytes($maxBytes)],
		));
	}

	/**
	 * Throws if the image's *header* declares more pixels than a decode may allocate.
	 *
	 * The same ceiling {@see assertPixelsAllowed} enforces at render time, moved to the
	 * front so a bomb is refused a mark rather than refused a download. It reads the first
	 * few KB rather than the file: dimensions live in the header of every format here, and
	 * pulling the whole image into memory to find out whether it is safe to pull the whole
	 * image into memory is not a check.
	 *
	 * A type with no pixels, or a header that cannot be parsed, passes - see
	 * {@see assertPixelsAllowed} for why an unreadable header is not treated as a bomb.
	 *
	 * @throws ImageTooLargeException
	 */
	private function assertPixelsAllowedFromHeader(File $file): void {
		if (!in_array($file->getMimeType(), self::SUPPORTED_IMAGE, true)) {
			return;
		}

		try {
			$handle = $file->fopen('rb');
			if ($handle === false) {
				return;
			}
			$header = fread($handle, self::HEADER_BYTES);
			fclose($handle);
		} catch (\Throwable) {
			// Storage that will not open is the download path's problem to report, not
			// this one's - refusing the mark here would blame the ceiling for it.
			return;
		}

		if ($header === false || $header === '') {
			return;
		}

		$info = @getimagesizefromstring($header);
		if ($info === false) {
			return;
		}

		$this->refuseIfOverPixelCeiling($info[0], $info[1], $file->getPath());
	}

	/**
	 * How much of an image to read to find its dimensions.
	 *
	 * Generous on purpose. PNG and WEBP declare the size in their first 32 bytes, but a
	 * JPEG's SOF marker sits after whatever EXIF and ICC blocks the camera wrote, which for
	 * an ordinary phone photo is tens of kilobytes. Reading short would make the check
	 * silently pass on exactly the files it is meant to measure.
	 */
	private const HEADER_BYTES = 262144;

	/**
	 * Throws if decoding $path would exceed the pixel ceiling.
	 *
	 * **Read from the header, never from a decode.** `getimagesize()` parses the few bytes
	 * that carry the dimensions and allocates nothing for the raster, which is the only
	 * order that helps: a check performed after `imagecreatefrom*()` has already made the
	 * allocation it exists to prevent, and a decompression bomb kills the worker before any
	 * code of ours runs again.
	 *
	 * **An unreadable header is allowed through**, deliberately. `getimagesize()` returns
	 * false for anything it cannot parse, which includes formats it does not know as well
	 * as corrupt files. Refusing on that would turn "this guard cannot tell" into "this
	 * file is a bomb", and would reject files the renderers handle perfectly well today.
	 * The renderer's own failure is the honest answer for a file that is actually broken.
	 *
	 * @throws ImageTooLargeException
	 */
	private function assertPixelsAllowed(string $path): void {
		$info = @getimagesize($path);
		if ($info === false) {
			return;
		}

		$this->refuseIfOverPixelCeiling($info[0], $info[1], $path);
	}

	/**
	 * The shared verdict of the two pixel checks, so the mark-time and render-time paths
	 * cannot drift into disagreeing about what is too large.
	 *
	 * Multiplied as ints and compared against the cap. A bomb's dimensions are large but
	 * nowhere near overflowing a 64-bit int - the format headers cap each side at 2^31 - so
	 * the product is exact.
	 *
	 * @throws ImageTooLargeException
	 */
	private function refuseIfOverPixelCeiling(int $width, int $height, string $path): void {
		$pixels = $width * $height;
		$max = $this->imageLimits->maxPixels();

		if ($pixels <= $max) {
			return;
		}

		$this->logger->warning('files_watermark: refusing an image of {pixels} pixels, the limit is {max}', [
			'pixels' => $pixels,
			'max' => $max,
			'path' => $path,
		]);

		throw new ImageTooLargeException($this->l->t(
			'This image is too large to watermark (%1$s megapixels; the limit is %2$s).',
			[round($pixels / 1000000, 1), round($max / 1000000, 1)],
		));
	}

	/**
	 * A byte count as something a person can act on, e.g. `210.4 MB`.
	 *
	 * The refusal exists to be actionable - it names both the file's size and the ceiling so
	 * an admin knows what to set `apply_max_bytes` to. Raw byte counts in the tens of
	 * millions do not read as anything, and this message reaches an end user, not a log.
	 *
	 * Decimal units, matching what the Files app shows for the same file: a user comparing
	 * this message against the size in the list must not find two different numbers.
	 */
	private function humanBytes(int $bytes): string {
		// Whole bytes stay whole; anything scaled gets one decimal, which is enough to
		// tell 64.0 MB from 64.9 MB without implying precision the cache does not have.
		if ($bytes < 1000) {
			return $bytes . ' B';
		}

		$value = (float)$bytes;
		$unit = 'KB';

		foreach (['KB', 'MB', 'GB', 'TB'] as $candidate) {
			$unit = $candidate;
			$value /= 1000;
			if ($value < 1000) {
				break;
			}
		}

		return round($value, 1) . ' ' . $unit;
	}

	// -----------------------------------------------------------------------
	// Watermark content
	// -----------------------------------------------------------------------

	/**
	 * Who this particular watermark names.
	 *
	 * The session user, or - when there is none - the file's **owner**. An anonymous fetch
	 * is a public link, and a public link has exactly one person accountable for it: whoever
	 * published the file. Stamping such a copy "Public link" would name the mechanism rather
	 * than a person, which is no use to anyone holding a leaked document.
	 *
	 * The same fallback covers server-side fetches with no session (preview pre-generation,
	 * background jobs), where naming the owner is both true and harmless.
	 */
	private function readerIdentity(File $file): ?IUser {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return $user;
		}

		try {
			return $file->getOwner();
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @param ?IUser $actor the user the watermark names; null resolves the reader, which is
	 *                      what every delivery does. Marking passes one explicitly because a background write
	 *                      has no session and the audit row would otherwise say "system"
	 * @return array<string, string>
	 */
	private function buildPlaceholders(File $file, ?IUser $actor = null): array {
		$user = $actor ?? $this->readerIdentity($file);
		$now = new \DateTimeImmutable('now', $this->timeZone->get());

		// A copy going out through a share by email can name the address it was sent to,
		// which is the one anonymous reader this app knows by name. Only when no actor was
		// named: an explicit actor is a caller saying who this watermark is for, and a
		// background write that passes one has no request share context to consult anyway.
		$recipient = $actor === null ? $this->shareRecipient->email($file) : null;

		return $this->scrubPlaceholders([
			// Two different identities, and the difference matters in a watermark. The
			// account name is the uid: unique, stable, and what an admin greps the audit
			// log or the user list for. The display name is what a human recognises, and
			// is neither unique nor fixed - a user can change it, and two people can share
			// one. Both are available under the name that describes them.
			//
			// The recipient of a mail share has neither: they have no account, so the two
			// name tokens keep naming whoever published the file. Only the address is
			// known, and only the address is replaced.
			'username' => $user?->getUID() ?? 'Unknown',
			'displayname' => $user?->getDisplayName() ?? 'Unknown',
			'email' => $recipient ?? $user?->getEMailAddress() ?? '',
			// In the instance's timezone rather than PHP's, which Nextcloud pins to UTC -
			// see {@see InstanceTimeZone}. Both read the same instant: a template using both
			// tokens must not be able to show a date from one day and a time from another,
			// which is what two separate clock reads either side of midnight would do.
			'date' => $now->format('Y-m-d'),
			'datetime' => $now->format('Y-m-d H:i:s'),
			'filename' => $file->getName(),
		]);
	}

	/**
	 * Placeholder values as valid UTF-8, saying so in the log when one had to be repaired.
	 *
	 * Every value above comes from somewhere this app does not control - a display name or
	 * an email out of the user backend (LDAP and AD are where latin-1 and Windows-1256
	 * remnants come from), a file name off the storage. One byte that is not UTF-8 in any
	 * of them used to cost the *whole* watermark its shaping, because the guard that
	 * decides whether to shape cannot scan a malformed string and read its failure as "no
	 * shaping needed". The Arabic then drew in isolated forms, left to right, in a
	 * perfectly valid image file - see {@see ShapedText::toValidUtf8()}.
	 *
	 * The renderers scrub as well, at the point of drawing, and that is what guarantees
	 * the output. This exists for the other half of the problem: **an admin looking at a
	 * mangled name needs to know which field to fix.** The renderers cannot say - by then
	 * the value is one substring of a resolved template - so the report happens here,
	 * where the values still have their names, and it names them rather than dumping the
	 * bytes.
	 *
	 * @param array<string, string> $placeholders
	 * @return array<string, string>
	 */
	private function scrubPlaceholders(array $placeholders): array {
		$repaired = [];
		foreach ($placeholders as $name => $value) {
			$clean = ShapedText::toValidUtf8($value);
			if ($clean !== $value) {
				$repaired[] = $name;
				$placeholders[$name] = $clean;
			}
		}

		if ($repaired !== []) {
			$this->logger->warning(
				'files_watermark: dropped invalid UTF-8 from watermark placeholder(s) {fields}; '
					. 'the watermark rendered without those bytes. Check the source of those values '
					. '(user backend, or the file name) if the result looks wrong.',
				['fields' => implode(', ', $repaired)],
			);
		}

		return $placeholders;
	}

	// -----------------------------------------------------------------------
	// Plumbing
	// -----------------------------------------------------------------------

	/**
	 * Whether a MIME type can be watermarked at all (single source of truth for routing).
	 */
	public function isSupported(string $mime): bool {
		return in_array($mime, self::SUPPORTED_ALL, true);
	}

	/**
	 * Refuses an unsupported file, saying so in the log first.
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

	/** A private 0700 directory for one render's working files. */
	private function createTempDir(): string {
		$dir = sys_get_temp_dir() . '/nc_watermark_' . bin2hex(random_bytes(8));
		if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
			throw new \RuntimeException('no temp directory is available for the render');
		}

		return $dir;
	}
}
