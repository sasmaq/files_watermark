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

class WatermarkService {

    private const SUPPORTED_PDF   = ['application/pdf'];
    private const SUPPORTED_IMAGE = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private WatermarkConfigMapper $configMapper,
        private WatermarkLogMapper    $logMapper,
        private PdfWatermarker        $pdfWatermarker,
        private ImageWatermarker      $imageWatermarker,
        private IRootFolder           $rootFolder,
        private IUserSession          $userSession,
    ) {}

    /**
     * Apply a watermark to a file and return the path of the watermarked temporary copy.
     * The caller is responsible for deleting the temp file after use.
     */
    public function watermarkFile(File $file, string $trigger, ?WatermarkConfig $config = null): string {
        $mime = $file->getMimeType();
        $this->assertSupported($mime);

        if ($config === null) {
            $config = $this->resolveConfig();
        }

        $placeholders = $this->buildPlaceholders($file);
        $tmpPath      = $this->createTempPath($file->getName());

        // Write source to temp so renderers can read from local filesystem
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
     * Apply watermark in-place: replaces the file content inside Nextcloud.
     */
    public function watermarkInPlace(File $file, string $trigger, ?WatermarkConfig $config = null): void {
        $tmpPath = $this->watermarkFile($file, $trigger, $config);
        $file->putContent(file_get_contents($tmpPath));
        unlink($tmpPath);
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

    private function assertSupported(string $mime): void {
        $all = array_merge(self::SUPPORTED_PDF, self::SUPPORTED_IMAGE);
        if (!in_array($mime, $all, true)) {
            throw new \RuntimeException("Unsupported file type: $mime");
        }
    }

    private function createTempPath(string $filename): string {
        $dir = sys_get_temp_dir() . '/nc_watermark_' . bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);
        return $dir . '/' . $filename;
    }
}
