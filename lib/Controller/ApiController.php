<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Controller;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Service\FileTooLargeException;
use OCA\FilesWatermark\Service\ImageTooLargeException;
use OCA\FilesWatermark\Service\InstanceTimeZone;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagNotFoundException;

class ApiController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private WatermarkConfigMapper $configMapper,
		private WatermarkLogMapper $logMapper,
		private WatermarkService $watermarkService,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private WatermarkImageStore $imageStore,
		private ISystemTagManager $tagManager,
		private IL10N $l,
		private InstanceTimeZone $timeZone,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The global policy, or an empty list on an install where none has been saved yet.
	 *
	 * Admin-only, like the settings page it feeds. The Files app does not call this - the
	 * one thing it needs, the effective trigger, arrives as initial state from
	 * {@see \OCA\FilesWatermark\EventListener\LoadAdditionalScriptsListener}.
	 *
	 * Still a list rather than a bare object: the response shape predates there being
	 * exactly one config, and `AdminSettings.vue` reads `configs[0]`.
	 */
	public function getConfig(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['error' => $this->l->t('Forbidden')], Http::STATUS_FORBIDDEN);
		}

		try {
			$configs = [$this->configMapper->findGlobal()];
		} catch (DoesNotExistException) {
			$configs = [];
		}

		return new DataResponse([
			'configs' => array_map(fn (WatermarkConfig $c) => $c->jsonSerialize(), $configs),
		]);
	}

	private function isAdmin(): bool {
		$user = $this->userSession->getUser();

		return $user !== null && $this->groupManager->isAdmin($user->getUID());
	}

	private const VALID_TYPES = ['text', 'image', 'combined'];

	/**
	 * The two triggers, read from the service rather than repeated here.
	 *
	 * There used to be four: `on_download` and `on_share` decided *when* a watermark was
	 * produced, back when the other two burned it into the file instead. Delivery is now the
	 * only way one is ever produced, so those two have nothing left to select and a policy
	 * still set to either is rejected here rather than quietly mapped onto a live one.
	 */
	private const VALID_TRIGGERS = WatermarkService::TRIGGERS;
	private const VALID_TOKENS = ['username', 'displayname', 'email', 'date', 'datetime', 'filename'];

	/**
	 * Save the global policy. Admin-only: there is exactly one, and it is server-wide.
	 */
	public function saveConfig(
		string $type,
		?string $textTemplate,
		?string $imagePath,
		int $opacity = 40,
		int $fontSize = 24,
		string $color = '#d3d3d3',
		int $rotation = 45,
		string $trigger = 'on_demand',
		?string $mimeTypes = null,
		?string $folderTag = null,
		// On, matching the entity default: a save that omits the field gets the behaviour a
		// fresh install has, rather than silently turning the audit trail off.
		bool $logDelivery = true,
		bool $watermarkInternalShares = false,
		bool $watermarkExternalShares = false,
		?int $id = null,
	): DataResponse {

		// Checked before any validation, so a non-admin learns nothing about the policy
		// from which complaint comes back.
		if (!$this->isAdmin()) {
			return new DataResponse(['error' => $this->l->t('Forbidden')], Http::STATUS_FORBIDDEN);
		}

		if (!in_array($type, self::VALID_TYPES, true)) {
			return new DataResponse(
				['error' => $this->l->t('Invalid type "%1$s". Allowed values: %2$s.', [$type, implode(', ', self::VALID_TYPES)])],
				Http::STATUS_BAD_REQUEST,
			);
		}

		if (!in_array($trigger, self::VALID_TRIGGERS, true)) {
			return new DataResponse(
				['error' => $this->l->t('Invalid trigger "%1$s". Allowed values: %2$s.', [$trigger, implode(', ', self::VALID_TRIGGERS)])],
				Http::STATUS_BAD_REQUEST,
			);
		}

		if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
			return new DataResponse(
				['error' => $this->l->t('Invalid color "%s". Must be a 6-digit hex value (e.g. #cccccc).', [$color])],
				Http::STATUS_BAD_REQUEST,
			);
		}

		// The image may only be one this app stored from an upload. It used to be a
		// free-text server path that the renderers read directly, which let any account
		// composite an arbitrary server-readable image into its watermarks.
		if ($imagePath !== null && $imagePath !== '' && !WatermarkImageStore::isReference($imagePath)) {
			return new DataResponse(
				['error' => $this->l->t('Invalid watermark image. Upload an image instead of specifying a path.')],
				Http::STATUS_BAD_REQUEST,
			);
		}

		// Blank means "no restriction". Normalise the form's empty strings to null so
		// one thing means one thing everywhere downstream.
		$mimeTypes = ($mimeTypes === null || trim($mimeTypes) === '') ? null : trim($mimeTypes);
		$folderTag = ($folderTag === null || trim($folderTag) === '') ? null : trim($folderTag);

		// An unsupported MIME type here is not a narrower policy, it is a policy that
		// can never match: the render would refuse every file the filter admits.
		// Silently storing it turns the whole config into a no-op an admin cannot see.
		if ($mimeTypes !== null) {
			$requested = array_filter(array_map('trim', explode(',', $mimeTypes)));
			$unsupported = array_diff($requested, WatermarkService::SUPPORTED_ALL);
			if ($requested === [] || $unsupported !== []) {
				return new DataResponse(
					['error' => $this->l->t('Unsupported file type(s): %1$s. Supported types: %2$s.', [
						implode(', ', $unsupported ?: [$this->l->t('(none given)')]),
						implode(', ', WatermarkService::SUPPORTED_ALL),
					])],
					Http::STATUS_BAD_REQUEST,
				);
			}
			$mimeTypes = implode(',', $requested);
		}

		// The tag has to be an id of a tag that exists. A tag *name* is the obvious
		// thing to type and used to be accepted, after which every watermark attempt
		// died on `InvalidArgumentException: Tag id must be integer` - an HTTP 500 per
		// request, with nothing in the settings page to hint at the cause.
		if ($folderTag !== null) {
			if (!ctype_digit($folderTag)) {
				return new DataResponse(
					// One literal, deliberately long: a concatenation inside t() is a string
					// the extractor sees only the first half of, so the translation would
					// never match what is looked up at runtime.
					['error' => $this->l->t('"%s" is not a system tag ID. Pick the tag from the list, or leave the field blank to apply everywhere.', [$folderTag])],
					Http::STATUS_BAD_REQUEST,
				);
			}

			try {
				$this->tagManager->getTagsByIds([$folderTag]);
			} catch (TagNotFoundException|\InvalidArgumentException) {
				return new DataResponse(
					['error' => $this->l->t('System tag ID "%s" does not exist on this server.', [$folderTag])],
					Http::STATUS_BAD_REQUEST,
				);
			}
		}

		if ($textTemplate !== null) {
			preg_match_all('/\{([^}]+)\}/', $textTemplate, $matches);
			$invalid = array_diff($matches[1], self::VALID_TOKENS);
			if (!empty($invalid)) {
				$allowed = implode(', ', array_map(fn ($t) => '{' . $t . '}', self::VALID_TOKENS));
				$found = implode(', ', array_map(fn ($t) => '{' . $t . '}', $invalid));
				return new DataResponse(
					['error' => $this->l->t('Unknown template token(s): %1$s. Allowed tokens: %2$s.', [$found, $allowed])],
					Http::STATUS_BAD_REQUEST,
				);
			}
		}

		if ($id !== null) {
			try {
				$config = $this->configMapper->findById($id);
			} catch (DoesNotExistException) {
				return new DataResponse(['error' => $this->l->t('Config not found')], Http::STATUS_NOT_FOUND);
			}
		} else {
			$config = new WatermarkConfig();
			$config->setCreatedAt(date('Y-m-d H:i:s'));
		}

		$config->setType($type);
		$config->setTextTemplate($textTemplate);
		$config->setImagePath($imagePath);
		$config->setOpacity(max(0, min(100, $opacity)));
		$config->setFontSize(max(6, min(120, $fontSize)));
		$config->setColor($color);
		$config->setRotation(max(-180, min(180, $rotation)));
		$config->setTrigger($trigger);
		$config->setMimeTypes($mimeTypes);
		$config->setFolderTag($folderTag);
		// Delivery triggers render per fetch, so recording them is opt-in - see the
		// column's own note in Version1007Date20260801120000. Nothing to validate: it is
		// a boolean, and the in-place rows it does not govern are written regardless.
		$config->setLogDelivery($logDelivery);
		// Nothing to validate on either: two booleans read at delivery, against the fetch
		// that is happening. They mark nothing, so - unlike the trigger - there is no
		// combination of them that can be inconsistent with the rest of the policy, and no
		// stored state left behind when one is switched off again.
		$config->setWatermarkInternalShares($watermarkInternalShares);
		$config->setWatermarkExternalShares($watermarkExternalShares);
		$config->setUpdatedAt(date('Y-m-d H:i:s'));

		if ($id !== null) {
			$config = $this->configMapper->update($config);
		} else {
			$config = $this->configMapper->insert($config);
		}

		return new DataResponse($config->jsonSerialize());
	}

	/**
	 * Store an uploaded watermark logo and return the reference to save on a config.
	 *
	 * Admin-only: the image is a server-wide asset written into the app's appdata, and the
	 * settings page that uses this is itself an admin section. Validation (real image type
	 * from content, size ceiling) lives in {@see WatermarkImageStore}.
	 */
	public function uploadImage(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['error' => $this->l->t('Forbidden')], Http::STATUS_FORBIDDEN);
		}

		$upload = $this->request->getUploadedFile('image');
		if (!is_array($upload) || !isset($upload['tmp_name'], $upload['error'])) {
			return new DataResponse(['error' => $this->l->t('No image was uploaded.')], Http::STATUS_BAD_REQUEST);
		}

		if ($upload['error'] !== UPLOAD_ERR_OK) {
			// Covers the PHP-level ceilings too (upload_max_filesize / post_max_size),
			// which reject the request before our own size check ever sees the file.
			$message = in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
				? $this->l->t('The image is too large.')
				: $this->l->t('The image could not be uploaded.');
			return new DataResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
		}

		try {
			$reference = $this->imageStore->store($upload['tmp_name']);
		} catch (\RuntimeException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse(['imagePath' => $reference]);
	}

	/** Discard the global policy, reverting the server to the built-in default. */
	public function deleteConfig(int $id): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['error' => $this->l->t('Forbidden')], Http::STATUS_FORBIDDEN);
		}

		try {
			$config = $this->configMapper->findById($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => $this->l->t('Config not found')], Http::STATUS_NOT_FOUND);
		}

		$this->configMapper->delete($config);
		return new DataResponse(['status' => 'deleted']);
	}

	/**
	 * Mark a file, on the user's own request, so every fetch of it is watermarked.
	 *
	 * **Nothing is rendered here and nothing is written to the file.** This used to be the
	 * one expensive thing an ordinary user could trigger - a full render plus two full reads
	 * of the content, synchronously, on one PHP worker - and it is now a row. The ceilings
	 * still apply, but they moved with the cost: {@see WatermarkService::mark()} checks them
	 * because a file this app will not render is a file it must not promise a watermark for,
	 * not because this request would struggle.
	 *
	 * The rate limit is sized for what is left. 120 a minute is well above what the file
	 * action can produce by hand - each mark needs its own modal confirmation - and still
	 * bounds a script, which matters because this is the only route to marking from the UI.
	 * Core's rate-limiting middleware enforces it and answers 429 before anything here runs.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function applyWatermark(string $path): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => $this->l->t('Unauthenticated')], Http::STATUS_UNAUTHORIZED);
		}

		// Resolve the path through the user's root folder. Nextcloud normalizes
		// the path and rejects traversal (`../`) outside the user's home, so the
		// resolved node is always owned by / shared with the acting user.
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());

		try {
			$node = $userFolder->get($path);
		} catch (\OCP\Files\NotFoundException) {
			return new DataResponse(['error' => $this->l->t('File not found')], Http::STATUS_NOT_FOUND);
		}

		if (!($node instanceof \OCP\Files\File)) {
			return new DataResponse(['error' => $this->l->t('Path is not a file')], Http::STATUS_BAD_REQUEST);
		}

		// Marking changes the file's *policy*, not its content, so it is the permission to
		// change the file that is required - the same one that governs renaming it. Read
		// permission is checked as well: a user who cannot read the file has no business
		// deciding how it is handed to other people.
		if (!$node->isReadable()) {
			return new DataResponse(['error' => $this->l->t('You do not have permission to read this file')], Http::STATUS_FORBIDDEN);
		}

		if (!$node->isUpdateable()) {
			return new DataResponse(['error' => $this->l->t('You do not have permission to modify this file')], Http::STATUS_FORBIDDEN);
		}

		$mime = $node->getMimeType();
		if (!in_array($mime, WatermarkService::SUPPORTED_ALL, true)) {
			return new DataResponse(
				['error' => $this->l->t('File type "%1$s" is not supported. Supported types: %2$s.', [$mime, implode(', ', WatermarkService::SUPPORTED_ALL)])],
				Http::STATUS_UNSUPPORTED_MEDIA_TYPE,
			);
		}

		try {
			$applied = $this->watermarkService->mark($node, WatermarkService::TRIGGER_ON_DEMAND, $user);
		} catch (FileTooLargeException|ImageTooLargeException $e) {
			// Both are the same refusal measured differently - bytes on disk and pixels once
			// decoded - and both must arrive as 413. Caught ahead of RuntimeException, which
			// they extend, or the size refusal would answer 422 and read as a broken file.
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		} catch (\RuntimeException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		// Already marked - a benign no-op, not an error. The UI branches on this status to
		// inform the user rather than showing a failure.
		if (!$applied) {
			return new DataResponse(['status' => 'already_watermarked', 'path' => $path]);
		}

		return new DataResponse(['status' => 'watermarked', 'path' => $path]);
	}

	/**
	 * Take the mark off a file, so it is served as it is stored again.
	 *
	 * Instant and complete: nothing was overwritten, so there is nothing to restore and no
	 * way for this to half-succeed. It used to rewrite the file with a preserved copy, and
	 * could fail for want of one - the 422 that said so has nothing left to describe.
	 *
	 * ---------------------------------------------------------------------------
	 * **ONLY THE OWNER MAY UNMARK, AND THAT IS NOT THE SAME RULE AS MARKING.**
	 *
	 * Marking asks for write permission, because it is a change to the file's policy and
	 * the people who can change the file are the people who can change that. Unmarking
	 * cannot use the same rule: a share recipient with edit permission would then be able
	 * to take the watermark off the document they were given, which is the entire threat
	 * the watermark exists to answer. Whoever the shared copy would have named is exactly
	 * whoever has an interest in it naming nobody.
	 *
	 * So this asks who *owns* the file, not who may write it. For a file that is not shared
	 * the two are the same person and nothing changes; the rule only ever bites on a share,
	 * which is the case it was written for.
	 *
	 * Note that the reverse is deliberately not restricted: a recipient may still *mark* a
	 * file they can write. That direction only ever adds protection, and it cannot lock the
	 * owner out - the owner can unmark anything they own.
	 * ---------------------------------------------------------------------------
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function removeWatermark(string $path): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => $this->l->t('Unauthenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());

		try {
			$node = $userFolder->get($path);
		} catch (\OCP\Files\NotFoundException) {
			return new DataResponse(['error' => $this->l->t('File not found')], Http::STATUS_NOT_FOUND);
		}

		if (!($node instanceof \OCP\Files\File)) {
			return new DataResponse(['error' => $this->l->t('Path is not a file')], Http::STATUS_BAD_REQUEST);
		}

		if (!$node->isReadable()) {
			return new DataResponse(['error' => $this->l->t('You do not have permission to read this file')], Http::STATUS_FORBIDDEN);
		}

		// The ownership check, per the note above. `getOwner()` is nullable and answers null
		// for a node whose owner cannot be resolved - a broken mount, most of all - and that
		// is treated as "not the owner": a check that cannot establish who owns the file has
		// not established that this user does.
		if ($node->getOwner()?->getUID() !== $user->getUID()) {
			return new DataResponse(
				['error' => $this->l->t('Only the owner of this file can remove its watermark.')],
				Http::STATUS_FORBIDDEN,
			);
		}

		if (!$this->watermarkService->unmark($node)) {
			// Not marked in the first place. A no-op rather than a failure: the caller asked
			// for this file not to be watermarked, and it is not.
			return new DataResponse(['status' => 'not_watermarked', 'path' => $path]);
		}

		return new DataResponse(['status' => 'removed', 'path' => $path]);
	}

	/**
	 * Report which of the given file ids are marked.
	 *
	 * The query is scoped to ids the acting user can actually access, so the
	 * response never reveals whether another user's files are watermarked.
	 *
	 * @param string $ids Comma-separated list of file ids, e.g. "1,2,3".
	 */
	#[NoAdminRequired]
	public function getWatermarkedStatus(string $ids = ''): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => $this->l->t('Unauthenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$requested = array_filter(array_map('intval', explode(',', $ids)), fn (int $id) => $id > 0);
		$requested = array_values(array_unique($requested));

		if (empty($requested)) {
			return new DataResponse(['watermarked' => []]);
		}

		// Restrict to ids the acting user can access. getById returns an empty
		// array for ids the user cannot reach, so anything outside their scope
		// is dropped before it ever hits the log table.
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$accessible = array_values(array_filter(
			$requested,
			fn (int $id) => $userFolder->getById($id) !== [],
		));

		if (empty($accessible)) {
			return new DataResponse(['watermarked' => []]);
		}

		$watermarked = $this->watermarkService->markedFileIds($accessible);

		return new DataResponse(['watermarked' => $watermarked]);
	}

	public function getLog(int $limit = 100, int $offset = 0): DataResponse {
		$user = $this->userSession->getUser();
		$isAdmin = $user && $this->groupManager->isAdmin($user->getUID());

		if (!$isAdmin) {
			return new DataResponse(['error' => $this->l->t('Forbidden')], Http::STATUS_FORBIDDEN);
		}

		$entries = $this->logMapper->findAll($limit, $offset);
		return new DataResponse(array_map(fn ($e) => $this->inInstanceTimeZone($e->jsonSerialize()), $entries));
	}

	/**
	 * One log row with `createdAt` moved into the instance's timezone.
	 *
	 * ---------------------------------------------------------------------------
	 * WHY THIS CONVERTS ON THE WAY OUT RATHER THAN STORING LOCAL TIME.
	 *
	 * `created_at` is written with `date('Y-m-d H:i:s')`, which is PHP's process default -
	 * UTC, because that is what Nextcloud pins it to during boot. That is the right thing to
	 * *store*: `prune-log` does date arithmetic against it, and a column whose meaning depends
	 * on an admin's `config.php` line is a retention command that deletes the wrong rows the
	 * day somebody edits it.
	 *
	 * So the column stays a fixed instant and the **display** moves, which also means an
	 * admin who changes `default_timezone` sees the whole history re-read in the new zone
	 * rather than a log with a seam in it.
	 *
	 * The string is parsed with no explicit zone, so PHP reads it in its own default - the
	 * same clock that wrote it. That equivalence is the load-bearing part: it holds whatever
	 * PHP's default is, so this is correct even on an install where something has moved it
	 * off UTC, and it needs no migration for rows already written.
	 * ---------------------------------------------------------------------------
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function inInstanceTimeZone(array $row): array {
		$createdAt = $row['createdAt'] ?? '';
		if (!is_string($createdAt) || $createdAt === '') {
			return $row;
		}

		try {
			$row['createdAt'] = (new \DateTimeImmutable($createdAt))
				->setTimezone($this->timeZone->get())
				->format('Y-m-d H:i:s');
		} catch (\Exception) {
			// An unparseable timestamp is a row written by something other than this app.
			// Shown as stored rather than dropped or blanked: the log is evidence, and a
			// row nobody can explain is still a row that happened.
		}

		return $row;
	}
}
