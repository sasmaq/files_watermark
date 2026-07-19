<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;

class WatermarkService {

    public const SUPPORTED_PDF   = ['application/pdf'];
    public const SUPPORTED_IMAGE = ['image/jpeg', 'image/png', 'image/webp'];
    public const SUPPORTED_ALL   = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private WatermarkConfigMapper  $configMapper,
        private WatermarkLogMapper     $logMapper,
        private PdfWatermarker         $pdfWatermarker,
        private ImageWatermarker       $imageWatermarker,
        private IRootFolder            $rootFolder,
        private IUserSession           $userSession,
        private ISystemTagObjectMapper $tagObjectMapper,
        private LoggerInterface        $logger,
    ) {}

    /**
     * Apply a watermark and return the path of the watermarked temporary copy.
     * Caller is responsible for deleting the temp file after use.
     */
    public function watermarkFile(File $file, string $trigger, ?WatermarkConfig $config = null): string {
        $mime = $file->getMimeType();
        $this->assertSupported($mime, $file);

        if ($config === null) {
            $config = $this->resolveConfig($this->userSession->getUser()?->getUID());
        }

        $this->assertMimeAllowed($mime, $config);
        $this->assertFolderTagMatches($file, $config);

        $placeholders = $this->buildPlaceholders($file);
        $tmpPath      = $this->createTempPath($file->getName());

        $srcTmp = $tmpPath . '_src';
        file_put_contents($srcTmp, $file->getContent());

        if (in_array($mime, self::SUPPORTED_PDF, true)) {
            $this->pdfWatermarker->apply($srcTmp, $tmpPath, $config, $placeholders);
        } else {
            $this->imageWatermarker->apply($srcTmp, $tmpPath, $config, $placeholders);
        }

        unlink($srcTmp);

        $user = $this->userSession->getUser();
        $this->logMapper->insertLog(
            $user?->getUID() ?? 'system',
            $file->getId(),
            $file->getPath(),
            $trigger,
            $config->getId(),
        );

        return $tmpPath;
    }

    /**
     * Render a watermarked copy for an on-download request, or return null to serve
     * the clean original.
     *
     * The gate for the `on_download` trigger: null is returned for an unsupported
     * type, when the effective trigger is not `on_download`, or on any rendering
     * failure — a broken watermark must degrade to the untouched original rather
     * than break the download. On success the path of a watermarked temp copy is
     * returned; the caller owns it and must delete it after streaming.
     *
     * @return string|null temp file path to stream, or null to serve the original
     */
    public function watermarkForDownload(File $file): ?string {
        if (!$this->isSupported($file->getMimeType())) {
            return null;
        }

        try {
            $config = $this->resolveConfig($this->userSession->getUser()?->getUID());
        } catch (\Throwable) {
            return null;
        }

        if ($config->getTrigger() !== 'on_download') {
            return null;
        }

        try {
            return $this->watermarkFile($file, 'on_download', $config);
        } catch (\Throwable $e) {
            $this->logger->error('files_watermark: failed to watermark on download: ' . $e->getMessage(), [
                'exception' => $e,
                'path'      => $file->getPath(),
            ]);
            return null;
        }
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
     * Apply watermark in-place — replaces the file content inside Nextcloud.
     *
     * Skips (and returns false) when the file has already been watermarked, so an
     * in-place burn is never applied twice — this is the authoritative guard for the
     * in-place triggers (`on_demand`, `on_upload`). Copy/stream triggers
     * (`on_share`, `on_download`) go through {@see watermarkFile} against the clean
     * original and are intentionally not guarded here.
     *
     * @return bool true when the watermark was applied, false when it was skipped
     *              because the file is already watermarked
     */
    public function watermarkInPlace(File $file, string $trigger, ?WatermarkConfig $config = null): bool {
        if ($this->isAlreadyWatermarked($file->getId())) {
            $this->logger->info('files_watermark: skipping already-watermarked file {path}', [
                'path'   => $file->getPath(),
                'fileId' => $file->getId(),
            ]);
            return false;
        }

        $tmpPath = $this->watermarkFile($file, $trigger, $config);
        $file->putContent(file_get_contents($tmpPath));
        unlink($tmpPath);
        @rmdir(dirname($tmpPath));
        return true;
    }

    public function resolveConfig(?string $userId = null): WatermarkConfig {
        if ($userId !== null) {
            $userConfigs = $this->configMapper->findByUser($userId);
            if (!empty($userConfigs)) {
                return $userConfigs[0];
            }
        }

        try {
            return $this->configMapper->findGlobal();
        } catch (DoesNotExistException) {
            return $this->defaultConfig();
        }
    }

    private function defaultConfig(): WatermarkConfig {
        $config = new WatermarkConfig();
        $config->setType('text');
        $config->setTextTemplate('{username} — {date}');
        $config->setPosition('diagonal');
        $config->setOpacity(80);
        $config->setFontSize(24);
        $config->setColor('#cccccc');
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
            throw new \RuntimeException("MIME type '$mime' is not in the configured whitelist.");
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
        $taggedFileIds = $this->tagObjectMapper->getObjectIdsForTags(
            [$tagId],
            'files',
            0,
        );

        if (!in_array((string) $parent->getId(), $taggedFileIds, true)) {
            throw new \RuntimeException(
                "File's folder does not have the required system tag (id: $tagId)."
            );
        }
    }

    private function buildPlaceholders(File $file): array {
        $user = $this->userSession->getUser();
        return [
            'username' => $user?->getDisplayName() ?? 'Unknown',
            'email'    => $user?->getEMailAddress() ?? '',
            'date'     => date('Y-m-d'),
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
            throw new \RuntimeException("Unsupported file type: $mime");
        }
    }

    private function createTempPath(string $filename): string {
        $dir = sys_get_temp_dir() . '/nc_watermark_' . bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);
        return $dir . '/' . $filename;
    }
}
