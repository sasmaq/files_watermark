<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Controller;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

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
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function getConfig(): DataResponse {
		$user = $this->userSession->getUser();
		$userId = $user?->getUID();
		$configs = $userId ? $this->configMapper->findByUser($userId) : [];

		if (empty($configs)) {
			try {
				$configs = [$this->configMapper->findGlobal()];
			} catch (DoesNotExistException) {
				$configs = [];
			}
		}

		return new DataResponse(array_map(fn (WatermarkConfig $c) => $c->jsonSerialize(), $configs));
	}

	private const VALID_TYPES = ['text', 'image', 'combined'];
	private const VALID_TRIGGERS = ['on_demand', 'on_download', 'on_upload', 'on_share'];
	private const VALID_TOKENS = ['username', 'email', 'date', 'datetime', 'filename'];

	#[NoAdminRequired]
	public function saveConfig(
		string $type,
		?string $textTemplate,
		?string $imagePath,
		string $position = 'diagonal',
		int $opacity = 80,
		int $fontSize = 24,
		string $color = '#cccccc',
		int $rotation = 45,
		string $trigger = 'on_demand',
		?string $mimeTypes = null,
		?string $folderTag = null,
		?string $userId = null,
		?string $groupId = null,
		?int $id = null,
	): DataResponse {
		if (!in_array($type, self::VALID_TYPES, true)) {
			return new DataResponse(
				['error' => "Invalid type '$type'. Allowed values: " . implode(', ', self::VALID_TYPES) . '.'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		if (!in_array($trigger, self::VALID_TRIGGERS, true)) {
			return new DataResponse(
				['error' => "Invalid trigger '$trigger'. Allowed values: " . implode(', ', self::VALID_TRIGGERS) . '.'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
			return new DataResponse(
				['error' => "Invalid color '$color'. Must be a 6-digit hex value (e.g. #cccccc)."],
				Http::STATUS_BAD_REQUEST,
			);
		}

		// The image may only be one this app stored from an upload. It used to be a
		// free-text server path that the renderers read directly, which let any account
		// composite an arbitrary server-readable image into its watermarks.
		if ($imagePath !== null && $imagePath !== '' && !WatermarkImageStore::isReference($imagePath)) {
			return new DataResponse(
				['error' => 'Invalid watermark image. Upload an image instead of specifying a path.'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		if ($textTemplate !== null) {
			preg_match_all('/\{([^}]+)\}/', $textTemplate, $matches);
			$invalid = array_diff($matches[1], self::VALID_TOKENS);
			if (!empty($invalid)) {
				$allowed = implode(', ', array_map(fn ($t) => '{' . $t . '}', self::VALID_TOKENS));
				$found = implode(', ', array_map(fn ($t) => '{' . $t . '}', $invalid));
				return new DataResponse(
					['error' => "Unknown template token(s): $found. Allowed tokens: $allowed."],
					Http::STATUS_BAD_REQUEST,
				);
			}
		}

		$currentUser = $this->userSession->getUser();
		$isAdmin = $currentUser && $this->groupManager->isAdmin($currentUser->getUID());

		// Non-admins can only set their own config
		if (!$isAdmin) {
			$userId = $currentUser?->getUID();
			$groupId = null;
		}

		if ($id !== null) {
			try {
				$config = $this->configMapper->findById($id);
			} catch (DoesNotExistException) {
				return new DataResponse(['error' => 'Config not found'], Http::STATUS_NOT_FOUND);
			}
		} else {
			$config = new WatermarkConfig();
			$config->setCreatedAt(date('Y-m-d H:i:s'));
		}

		$config->setType($type);
		$config->setTextTemplate($textTemplate);
		$config->setImagePath($imagePath);
		$config->setPosition($position);
		$config->setOpacity(max(0, min(100, $opacity)));
		$config->setFontSize(max(6, min(120, $fontSize)));
		$config->setColor($color);
		$config->setRotation(max(-180, min(180, $rotation)));
		$config->setTrigger($trigger);
		$config->setMimeTypes($mimeTypes);
		$config->setFolderTag($folderTag);
		$config->setUserId($userId);
		$config->setGroupId($groupId);
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
		$user = $this->userSession->getUser();
		if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
			return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$upload = $this->request->getUploadedFile('image');
		if (!is_array($upload) || !isset($upload['tmp_name'], $upload['error'])) {
			return new DataResponse(['error' => 'No image was uploaded.'], Http::STATUS_BAD_REQUEST);
		}

		if ($upload['error'] !== UPLOAD_ERR_OK) {
			// Covers the PHP-level ceilings too (upload_max_filesize / post_max_size),
			// which reject the request before our own size check ever sees the file.
			$message = in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
				? 'The image is too large.'
				: 'The image could not be uploaded.';
			return new DataResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
		}

		try {
			$reference = $this->imageStore->store($upload['tmp_name']);
		} catch (\RuntimeException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse(['imagePath' => $reference]);
	}

	#[NoAdminRequired]
	public function deleteConfig(int $id): DataResponse {
		$currentUser = $this->userSession->getUser();
		$isAdmin = $currentUser && $this->groupManager->isAdmin($currentUser->getUID());

		try {
			$config = $this->configMapper->findById($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'Config not found'], Http::STATUS_NOT_FOUND);
		}

		if (!$isAdmin && $config->getUserId() !== $currentUser?->getUID()) {
			return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$this->configMapper->delete($config);
		return new DataResponse(['status' => 'deleted']);
	}

	#[NoAdminRequired]
	public function applyWatermark(string $path): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Resolve the path through the user's root folder. Nextcloud normalizes
		// the path and rejects traversal (`../`) outside the user's home, so the
		// resolved node is always owned by / shared with the acting user.
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());

		try {
			$node = $userFolder->get($path);
		} catch (\OCP\Files\NotFoundException) {
			return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
		}

		if (!($node instanceof \OCP\Files\File)) {
			return new DataResponse(['error' => 'Path is not a file'], Http::STATUS_BAD_REQUEST);
		}

		// The watermark is applied in place, so the acting user must be able to
		// both read the original content and write the result back.
		if (!$node->isReadable()) {
			return new DataResponse(['error' => 'You do not have permission to read this file'], Http::STATUS_FORBIDDEN);
		}

		if (!$node->isUpdateable()) {
			return new DataResponse(['error' => 'You do not have permission to modify this file'], Http::STATUS_FORBIDDEN);
		}

		$mime = $node->getMimeType();
		if (!in_array($mime, WatermarkService::SUPPORTED_ALL, true)) {
			return new DataResponse(
				['error' => "File type '$mime' is not supported. Supported types: " . implode(', ', WatermarkService::SUPPORTED_ALL) . '.'],
				Http::STATUS_UNSUPPORTED_MEDIA_TYPE,
			);
		}

		try {
			$applied = $this->watermarkService->watermarkInPlace($node, 'on_demand');
		} catch (\RuntimeException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		// Already watermarked — a benign no-op, not an error. The UI branches on
		// this status to inform the user rather than showing a failure.
		if (!$applied) {
			return new DataResponse(['status' => 'already_watermarked', 'path' => $path]);
		}

		return new DataResponse(['status' => 'watermarked', 'path' => $path]);
	}

	/**
	 * Undo an on-demand watermark by restoring the preserved original.
	 *
	 * The watermark is burned into the file content, so this restores the copy taken
	 * before the burn rather than stripping anything. 422 when no such copy exists —
	 * a file watermarked before this feature landed, or one whose backup failed.
	 */
	#[NoAdminRequired]
	public function removeWatermark(string $path): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());

		try {
			$node = $userFolder->get($path);
		} catch (\OCP\Files\NotFoundException) {
			return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
		}

		if (!($node instanceof \OCP\Files\File)) {
			return new DataResponse(['error' => 'Path is not a file'], Http::STATUS_BAD_REQUEST);
		}

		// Restoring rewrites the file, so the same read + write permissions the apply
		// path demands are required here.
		if (!$node->isReadable()) {
			return new DataResponse(['error' => 'You do not have permission to read this file'], Http::STATUS_FORBIDDEN);
		}

		if (!$node->isUpdateable()) {
			return new DataResponse(['error' => 'You do not have permission to modify this file'], Http::STATUS_FORBIDDEN);
		}

		try {
			$removed = $this->watermarkService->removeWatermark($node);
		} catch (\RuntimeException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		if (!$removed) {
			return new DataResponse(
				['error' => 'No preserved original is available for this file, so its watermark cannot be removed.'],
				Http::STATUS_UNPROCESSABLE_ENTITY,
			);
		}

		return new DataResponse(['status' => 'removed', 'path' => $path]);
	}

	/**
	 * Report which of the given file ids have ever been watermarked.
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
			return new DataResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
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

		$watermarked = $this->logMapper->findWatermarkedFileIds($accessible);

		return new DataResponse(['watermarked' => $watermarked]);
	}

	public function getLog(int $limit = 100, int $offset = 0): DataResponse {
		$user = $this->userSession->getUser();
		$isAdmin = $user && $this->groupManager->isAdmin($user->getUID());

		if (!$isAdmin) {
			return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$entries = $this->logMapper->findAll($limit, $offset);
		return new DataResponse(array_map(fn ($e) => $e->jsonSerialize(), $entries));
	}
}
