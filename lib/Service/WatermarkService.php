<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\ISharedStorage;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;

class WatermarkService {

    public const SUPPORTED_PDF   = ['application/pdf'];
    public const SUPPORTED_IMAGE = ['image/jpeg', 'image/png', 'image/webp'];
    public const SUPPORTED_ALL   = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    /** Log trigger recorded when a watermark is undone; see {@see removeWatermark}. */
    public const TRIGGER_REMOVED = 'removed';

    public function __construct(
        private WatermarkConfigMapper  $configMapper,
        private WatermarkLogMapper     $logMapper,
        private PdfWatermarker         $pdfWatermarker,
        private ImageWatermarker       $imageWatermarker,
        private IRootFolder            $rootFolder,
        private IUserSession           $userSession,
        private ISystemTagObjectMapper $tagObjectMapper,
        private LoggerInterface        $logger,
        private OriginalStore          $originalStore,
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

        $placeholders = $this->buildPlaceholders($file, $trigger);
        $tmpPath      = $this->createTempPath($file->getName());

        $srcTmp = $tmpPath . '_src';
        file_put_contents($srcTmp, $file->getContent());

        try {
            if (in_array($mime, self::SUPPORTED_PDF, true)) {
                $this->pdfWatermarker->apply($srcTmp, $tmpPath, $config, $placeholders);
            } else {
                $this->imageWatermarker->apply($srcTmp, $tmpPath, $config, $placeholders);
            }
        } catch (\Throwable $e) {
            // $srcTmp holds a plaintext copy of the file. A render failure is routine
            // (unparseable PDFs, and every on_share deny path goes through one), so
            // without this it accumulates readable copies of user content in the temp
            // dir indefinitely — the caller only ever gets an exception, never a path
            // it could clean up itself.
            $this->discardTemp($tmpPath, $srcTmp);
            throw $e;
        }

        unlink($srcTmp);

        $user = $this->userSession->getUser();
        $this->logMapper->insertLog(
            $user?->getUID() ?? $this->anonymousLabel($trigger, 'public-link', 'system'),
            $file->getId(),
            $file->getPath(),
            $trigger,
            $config->getId(),
        );

        return $tmpPath;
    }

    /**
     * Render a watermarked copy for a file being fetched over WebDAV, or return null
     * to serve the clean original. This is the single gate for both non-destructive
     * delivery triggers:
     *
     *  - `on_download` — watermark on every download, whoever fetches the file.
     *  - `on_share`    — watermark only when the file is fetched by someone other than
     *                    its owner (a share recipient, or an anonymous public-link
     *                    visitor). The owner reading their own file is left untouched.
     *
     * The applicable policy is the file *owner's* — they own the watermark rule for
     * their file — not the downloader's, who may be a recipient with an unrelated
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
                'trigger'   => $trigger,
                'path'      => $file->getPath(),
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
     * Whether $file is being accessed through a share mount — i.e. the current user is
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
     * Whether $file is being accessed by someone other than its owner — the full
     * `on_share` audience: internal share recipients *and* public-link visitors.
     *
     * {@see isReceivedShare} alone does not cover public links. A public link is served
     * from the *owner's* own storage (`public.php/dav` resolves the node through
     * `getUserFolder($shareOwner)` and only wraps it in PermissionsMask /
     * PublicOwnerWrapper), so the mount is never an ISharedStorage and the storage test
     * reports "owner access" for an anonymous visitor — which would hand them the clean
     * original. Two further signals close that hole:
     *
     *  - $publicContext — set by the caller that *knows* it is serving a public link
     *    (the interceptor instance registered on the public DAV server).
     *  - no session user — an anonymous request can only be reaching a file through a
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

        $config = $this->deliveryConfig($file, $publicContext);
        if ($config === null) {
            return null;
        }

        // A file the config would skip (excluded mime, missing folder tag) is not a
        // watermark candidate — report "not applicable" so it is served untouched.
        try {
            $this->assertMimeAllowed($mime, $config);
            $this->assertFolderTagMatches($file, $config);
        } catch (\RuntimeException) {
            return null;
        }

        return [$config->getTrigger(), $config];
    }

    /**
     * The owner's config when a delivery trigger applies to this fetch of $node, or null
     * when the node should be served unmodified.
     *
     * This is the type-agnostic half of {@see resolveDelivery}: owner policy lookup plus
     * the on_download / on_share(+non-owner) rule, with no per-file exclusions. Splitting
     * it out lets a *folder* be gated by the same rule — see {@see deliveryApplies}.
     */
    private function deliveryConfig(FileInfo $node, bool $publicContext = false): ?WatermarkConfig {
        $ownerUid = $node->getOwner()?->getUID() ?? $this->userSession->getUser()?->getUID();

        try {
            $config = $this->resolveConfig($ownerUid);
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
     * Whether a delivery trigger applies to this fetch of $node, ignoring the per-file
     * exclusions (supported type, mime whitelist, folder tag).
     *
     * Coarse gate for the archive interceptor: it must decide whether to take over a
     * *folder* download before it has looked at any member. Each member is then judged
     * individually by {@see watermarkForDownload}, so an excluded or unsupported file
     * inside a folder this returns true for is still streamed untouched.
     */
    public function deliveryApplies(FileInfo $node, bool $publicContext = false): bool {
        return $this->deliveryConfig($node, $publicContext) !== null;
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

        // Preserve the pre-watermark bytes before they are overwritten — this burn is
        // destructive and irreversible, so this copy is the only route back. Read the
        // original *now*, while the stored content is still clean. A failed backup is
        // logged and does not abort the watermark; the user simply won't be able to undo
        // it, which removeWatermark() reports rather than pretending to restore.
        $this->originalStore->store($file->getId(), $file->getContent());

        $file->putContent(file_get_contents($tmpPath));
        unlink($tmpPath);
        @rmdir(dirname($tmpPath));
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
     * The removal is logged rather than the original rows being deleted — this is an audit
     * log, so the apply and the undo both belong in the history.
     *
     * @return bool true when the original was restored, false when none is preserved
     */
    public function removeWatermark(File $file): bool {
        $fileId  = $file->getId();
        $content = $this->originalStore->read($fileId);

        if ($content === null) {
            $this->logger->info('files_watermark: no preserved original for {path}, cannot remove watermark', [
                'path'   => $file->getPath(),
                'fileId' => $fileId,
            ]);
            return false;
        }

        $file->putContent($content);

        // Only drop the backup once the restore has actually landed, so a failed
        // putContent (which throws) leaves the original recoverable on a later attempt.
        $this->originalStore->discard($fileId);

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
     * Whether a watermark on this file can be undone (a preserved original exists).
     */
    public function canRemoveWatermark(int $fileId): bool {
        return $this->originalStore->has($fileId);
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

    /**
     * The name to stamp / log when there is no session user. Under `on_share` that can
     * only be an anonymous public-link visitor, so naming them as such makes a leaked
     * copy show how it was obtained; other triggers keep the old generic fallbacks.
     */
    private function anonymousLabel(string $trigger, string $publicLabel, string $default): string {
        return $trigger === 'on_share' ? $publicLabel : $default;
    }

    private function buildPlaceholders(File $file, string $trigger): array {
        $user = $this->userSession->getUser();
        return [
            'username' => $user?->getDisplayName() ?? $this->anonymousLabel($trigger, 'Public link', 'Unknown'),
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
