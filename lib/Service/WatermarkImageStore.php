<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;

/**
 * Stores uploaded watermark logo images and hands renderers a local path to one.
 *
 * Replaces the old "type an image path" field, which stored a raw **server filesystem**
 * path that {@see PdfWatermarker} / {@see ImageWatermarker} then `file_exists()`ed and
 * read. Because saving a config is not admin-only, any account could point its personal
 * watermark at an arbitrary server-readable image and have the contents composited into
 * files it downloaded. Images now only ever come from here: uploaded through the settings
 * UI, validated, and referenced by an opaque generated name that cannot escape this folder.
 *
 * A reference is just a file name ({@see isReference}) resolved inside the app's appdata —
 * never a caller-supplied path — so a config carrying a legacy absolute path resolves to
 * nothing instead of reading it.
 */
class WatermarkImageStore {

    private const FOLDER = 'watermark-images';

    /**
     * Accepted upload types, mapped to the extension the stored file gets.
     *
     * PNG and JPEG only, deliberately: they are the two types every render path handles
     * (the GD fallback in ImageWatermarker decodes exactly these, and TCPDF's `Image()`
     * cannot place an SVG at all), and accepting SVG would mean storing attacker-authored
     * markup that ImageMagick may parse with external-entity/remote-fetch delegates.
     */
    private const ALLOWED = [
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
    ];

    /** Logos are page decorations; a few hundred KB is generous. */
    public const MAX_BYTES = 2097152; // 2 MiB

    public function __construct(
        private IAppDataFactory $appDataFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whether $value is a reference produced by {@see store} rather than something a user
     * typed. The config column is shared with legacy absolute paths, so every read goes
     * through this — an unrecognised value is ignored, not resolved.
     */
    public static function isReference(?string $value): bool {
        return $value !== null && preg_match('/^[0-9a-f]{32}\.(png|jpg)$/', $value) === 1;
    }

    /**
     * Validate and store an uploaded image, returning its reference.
     *
     * The type is taken from the file's actual content ({@see getimagesize}), never from
     * the client-supplied name or MIME header, so a `.png` that is really something else
     * is rejected.
     *
     * @param string $tmpPath path of the uploaded temp file
     * @throws \RuntimeException when the upload is too large, unreadable or not a PNG/JPEG
     */
    public function store(string $tmpPath): string {
        $size = @filesize($tmpPath);
        if ($size === false || $size === 0) {
            throw new \RuntimeException('The uploaded image could not be read.');
        }
        if ($size > self::MAX_BYTES) {
            $max = (int) (self::MAX_BYTES / 1024 / 1024);
            throw new \RuntimeException("The image is too large. Maximum size is {$max} MB.");
        }

        $info = @getimagesize($tmpPath);
        if ($info === false || !isset(self::ALLOWED[$info[2]])) {
            throw new \RuntimeException('The image must be a PNG or JPEG file.');
        }

        $content = @file_get_contents($tmpPath);
        if ($content === false) {
            throw new \RuntimeException('The uploaded image could not be read.');
        }

        $folder = $this->folder(true);
        if ($folder === null) {
            throw new \RuntimeException('The watermark image could not be stored.');
        }

        // Opaque name: nothing the client sent reaches the filesystem.
        $name = bin2hex(random_bytes(16)) . '.' . self::ALLOWED[$info[2]];

        try {
            $folder->newFile($name, $content);
        } catch (\Throwable $e) {
            $this->logger->error('files_watermark: could not store watermark image', ['exception' => $e]);
            throw new \RuntimeException('The watermark image could not be stored.');
        }

        return $name;
    }

    /**
     * Materialise the referenced image as a local file the renderers can open, or null when
     * the reference is absent, unrecognised or no longer stored.
     *
     * Appdata is not necessarily on a local disk (object storage is a supported primary
     * storage), so the content is copied to a temp file rather than assuming a path exists.
     * The caller owns that file and must delete it — see {@see WatermarkService::watermarkFile}.
     */
    public function localPath(?string $reference): ?string {
        if (!self::isReference($reference)) {
            if ($reference !== null && $reference !== '') {
                // A legacy absolute path, or junk. Refusing to read it is the point.
                $this->logger->warning('files_watermark: ignoring non-uploaded watermark image reference', [
                    'reference' => $reference,
                ]);
            }
            return null;
        }

        $folder = $this->folder(false);
        if ($folder === null) {
            return null;
        }

        try {
            $content = $folder->getFile($reference)->getContent();
        } catch (NotFoundException) {
            return null;
        } catch (\Throwable $e) {
            $this->logger->error('files_watermark: could not read watermark image', [
                'reference' => $reference,
                'exception' => $e,
            ]);
            return null;
        }

        $tmpPath = sys_get_temp_dir() . '/nc_watermark_logo_' . bin2hex(random_bytes(8)) . '_' . $reference;
        if (@file_put_contents($tmpPath, $content) === false) {
            return null;
        }

        return $tmpPath;
    }

    /**
     * Whether the referenced image is still stored.
     */
    public function exists(?string $reference): bool {
        if (!self::isReference($reference)) {
            return false;
        }
        $folder = $this->folder(false);
        return $folder !== null && $folder->fileExists($reference);
    }

    /**
     * Delete a stored image (used when it is replaced or cleared).
     */
    public function delete(?string $reference): void {
        if (!self::isReference($reference)) {
            return;
        }

        $folder = $this->folder(false);
        if ($folder === null) {
            return;
        }

        try {
            $folder->getFile($reference)->delete();
        } catch (NotFoundException) {
            // Already gone.
        } catch (\Throwable $e) {
            $this->logger->warning('files_watermark: could not delete watermark image', [
                'reference' => $reference,
                'exception' => $e,
            ]);
        }
    }

    private function folder(bool $create): ?ISimpleFolder {
        try {
            $appData = $this->appDataFactory->get('files_watermark');
            try {
                return $appData->getFolder(self::FOLDER);
            } catch (NotFoundException) {
                return $create ? $appData->newFolder(self::FOLDER) : null;
            }
        } catch (\Throwable $e) {
            $this->logger->error('files_watermark: appdata unavailable for watermark images', [
                'exception' => $e,
            ]);
            return null;
        }
    }
}
