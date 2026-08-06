<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Db\WatermarkMarkMapper;
use OCP\AppFramework\Db\DoesNotExistException;
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
	 * Audit-only triggers - recorded in `watermark_log`, never stored on a mark.
	 *
	 * `unmarked` is a user taking the mark off; `delivered` is one watermarked copy handed
	 * to one reader. Neither is a policy, which is why neither appears in TRIGGERS.
	 */
	public const TRIGGER_UNMARKED = 'unmarked';
	public const TRIGGER_DELIVERED = 'delivered';

	/** Per-request memo for {@see resolveConfig}. One policy, so one slot. */
	private ?WatermarkConfig $configCache = null;

	public function __construct(
		private WatermarkConfigMapper $configMapper,
		private WatermarkLogMapper $logMapper,
		private WatermarkMarkMapper $markMapper,
		private PdfWatermarker $pdfWatermarker,
		private ImageWatermarker $imageWatermarker,
		private IUserSession $userSession,
		private ISystemTagObjectMapper $tagObjectMapper,
		private LoggerInterface $logger,
		private WatermarkImageStore $imageStore,
		private ImageLimits $imageLimits,
		private ApplyLimits $applyLimits,
		private ShareAccess $shareAccess,
		private IL10N $l,
	) {
	}

	// -----------------------------------------------------------------------
	// Marking
	// -----------------------------------------------------------------------

	/**
	 * Mark $file so that every fetch of it is watermarked.
	 *
	 * Nothing is read and nothing is written except one row - which is what lets the upload
	 * listener do this inline, where the old in-place burn had to be queued behind a lock.
	 * The two size ceilings are enforced *here*, at the only moment where refusing is still
	 * a choice: past this point the file is promised a watermark on every fetch, and a
	 * ceiling discovered then would deny the download of a file nobody was ever warned
	 * about.
	 *
	 * @param string $trigger one of {@see TRIGGERS}
	 * @param ?IUser $actor who is marking; null falls back to the session user
	 * @return bool true when this call placed the mark, false when one was already there
	 *
	 * @throws FileTooLargeException|ImageTooLargeException the file is past a ceiling
	 * @throws \RuntimeException unsupported type, or excluded by the policy's own scope
	 */
	public function mark(File $file, string $trigger, ?IUser $actor = null, ?WatermarkConfig $config = null): bool {
		$config ??= $this->resolveConfig();

		$this->assertMarkable($file, $config);

		$user = $actor ?? $this->userSession->getUser();
		$placed = $this->markMapper->mark(
			$file->getId(),
			$user?->getUID() ?? 'system',
			$trigger,
			$config->getId(),
		);

		if (!$placed) {
			// Already marked. Not an error anywhere: the on-demand endpoint reports it as a
			// no-op, and on_upload re-marking a file on every write is the ordinary case.
			return false;
		}

		$this->recordLog($file, $trigger, $config, $user);

		return true;
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

		$this->logMapper->insertLog(
			$this->userSession->getUser()?->getUID() ?? 'system',
			$file->getId(),
			$file->getPath(),
			self::TRIGGER_UNMARKED,
			null,
		);

		return true;
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
	 * @return string|null path of a temp copy the caller owns and must delete, or null when
	 *                     the file carries no mark
	 * @throws WatermarkRequiredException the file is marked and the render failed; the
	 *                                    caller must deny the fetch rather than serve the original
	 */
	public function watermarkForDownload(File $file): ?string {
		if (!$this->isSupported($file->getMimeType())) {
			return null;
		}

		// The two reasons are told apart rather than folded into `isDeliveryCandidate` here,
		// because the ceiling below depends on which one applies - and asking the mark table
		// once is the difference between one query per delivery and two.
		$id = $file->getId();
		$marked = $id !== null && $this->isMarked($id);
		if (!$marked && !$this->isForcedByShare($file)) {
			return null;
		}

		try {
			$config = $this->resolveConfig();
			// A marked file passed the byte ceiling when it was marked; a file watermarked
			// only because it is going out through a share never had such a moment, so the
			// ceiling is applied here instead. It throws, and the catch below turns that into
			// the same denial a failed render produces - the file is not served clean.
			if (!$marked) {
				$this->assertSizeAllowed($file);
			}
			[$tmpPath, $resolved] = $this->renderToTemp($file, $config);
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
		if (!$this->isSupported($node->getMimetype())) {
			return false;
		}

		$id = $node->getId();
		if ($id !== null && $this->isMarked($id)) {
			return true;
		}

		return $this->isForcedByShare($node);
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
	public function isForcedByShare(FileInfo $node): bool {
		$config = $this->resolveConfig();

		$forced = ($config->getWatermarkExternalShares() && $this->shareAccess->isExternalShareAccess())
			|| ($config->getWatermarkInternalShares() && $this->shareAccess->isInternalShareAccess($node));

		return $forced && $this->isInScope($node, $config);
	}

	/**
	 * Whether $node is inside the policy's own scope.
	 *
	 * The same two exclusions {@see assertMarkable} applies before placing a mark, asked as a
	 * question rather than as an assertion because there is no mark to refuse here - the
	 * answer decides whether this fetch is watermarked, and "no" means serve the file as it
	 * is stored.
	 *
	 * **The folder tag is only checked for a real node.** It reads the file's parent, which
	 * `FileInfo` cannot give us; every delivery path in this app passes a `File`, so the
	 * fallback is theoretical, and it is deliberately the *inclusive* one - a scope test this
	 * cannot perform must not quietly hand out a clean copy of a shared file.
	 */
	private function isInScope(FileInfo $node, WatermarkConfig $config): bool {
		try {
			$this->assertMimeAllowed($node->getMimetype(), $config);
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

		$logoTmp = $this->imageStore->localPath($config->getImagePath());
		$config = $this->withImagePath($config, $logoTmp);

		try {
			$this->imageWatermarker->apply(
				$srcPath,
				$destPath,
				$config,
				$this->buildPlaceholders($file),
			);
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
	 * Render a watermarked copy of $file to a temp path.
	 *
	 * @return array{0: string, 1: WatermarkConfig} the temp path and the config the render
	 *                                              resolved to (callers need its id for the audit row)
	 */
	private function renderToTemp(File $file, WatermarkConfig $config, ?IUser $actor = null): array {
		$mime = $file->getMimeType();
		$this->assertSupported($mime, $file);

		$placeholders = $this->buildPlaceholders($file, $actor);
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
			$this->discardLogo($logoTmp);
			// $srcTmp holds a plaintext copy of the file. A render failure is routine
			// (unparseable PDFs above all), so without this it accumulates readable copies
			// of user content in the temp dir indefinitely - the caller only ever gets an
			// exception, never a path it could clean up itself.
			$this->discardTemp($tmpPath, $srcTmp);
			throw $e;
		}

		$this->discardLogo($logoTmp);
		unlink($srcTmp);

		return [$tmpPath, $config];
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
		$config->setColor('#808080');
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
		$maxBytes = $this->applyLimits->maxBytes();
		// `getSize()` is documented as float|int - the cache widens it so a size can
		// outrun a 32-bit int. Narrowed once here rather than at each use.
		$size = (int)$file->getSize();

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

		return $this->scrubPlaceholders([
			// Two different identities, and the difference matters in a watermark. The
			// account name is the uid: unique, stable, and what an admin greps the audit
			// log or the user list for. The display name is what a human recognises, and
			// is neither unique nor fixed - a user can change it, and two people can share
			// one. Both are available under the name that describes them.
			'username' => $user?->getUID() ?? 'Unknown',
			'displayname' => $user?->getDisplayName() ?? 'Unknown',
			'email' => $user?->getEMailAddress() ?? '',
			'date' => date('Y-m-d'),
			'datetime' => date('Y-m-d H:i:s'),
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

	private function createTempPath(string $filename): string {
		$dir = sys_get_temp_dir() . '/nc_watermark_' . bin2hex(random_bytes(8));
		mkdir($dir, 0700, true);
		return $dir . '/' . $filename;
	}
}
