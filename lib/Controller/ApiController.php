<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Controller;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends OCSController {

    public function __construct(
        string                        $appName,
        IRequest                      $request,
        private WatermarkConfigMapper $configMapper,
        private WatermarkLogMapper    $logMapper,
        private WatermarkService      $watermarkService,
        private IRootFolder           $rootFolder,
        private IUserSession          $userSession,
        private IGroupManager         $groupManager,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function getConfig(): DataResponse {
        $user    = $this->userSession->getUser();
        $userId  = $user?->getUID();
        $configs = $userId ? $this->configMapper->findByUser($userId) : [];

        if (empty($configs)) {
            try {
                $configs = [$this->configMapper->findGlobal()];
            } catch (DoesNotExistException) {
                $configs = [];
            }
        }

        return new DataResponse(array_map(fn(WatermarkConfig $c) => $c->jsonSerialize(), $configs));
    }

    #[NoAdminRequired]
    public function saveConfig(
        string  $type,
        ?string $textTemplate,
        ?string $imagePath,
        string  $position = 'diagonal',
        int     $opacity = 80,
        int     $fontSize = 24,
        string  $color = '#cccccc',
        int     $rotation = 45,
        string  $trigger = 'on_demand',
        ?string $userId = null,
        ?string $groupId = null,
        ?int    $id = null,
    ): DataResponse {
        $currentUser = $this->userSession->getUser();
        $isAdmin     = $currentUser && $this->groupManager->isAdmin($currentUser->getUID());

        // Non-admins can only set their own config
        if (!$isAdmin) {
            $userId  = $currentUser?->getUID();
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

    #[NoAdminRequired]
    public function deleteConfig(int $id): DataResponse {
        $currentUser = $this->userSession->getUser();
        $isAdmin     = $currentUser && $this->groupManager->isAdmin($currentUser->getUID());

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

        $userFolder = $this->rootFolder->getUserFolder($user->getUID());

        try {
            $node = $userFolder->get($path);
        } catch (\OCP\Files\NotFoundException) {
            return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
        }

        if (!($node instanceof \OCP\Files\File)) {
            return new DataResponse(['error' => 'Path is not a file'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->watermarkService->watermarkInPlace($node, 'on_demand');
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        return new DataResponse(['status' => 'watermarked', 'path' => $path]);
    }

    public function getLog(int $limit = 100, int $offset = 0): DataResponse {
        $user    = $this->userSession->getUser();
        $isAdmin = $user && $this->groupManager->isAdmin($user->getUID());

        if (!$isAdmin) {
            return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        $entries = $this->logMapper->findAll($limit, $offset);
        return new DataResponse(array_map(fn($e) => $e->jsonSerialize(), $entries));
    }
}
