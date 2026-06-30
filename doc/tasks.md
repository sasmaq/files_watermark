# files_watermark — Task List

Derived from [sdd.md](sdd.md). Tasks are grouped by SDD goal/section. Check items off as
they are implemented.

---

## Goal 1 — Watermark PDFs, images, and Office documents

### PDF (`PdfWatermarker`)

- [x] Apply text overlay (tiled diagonal) across all pages of multi-page PDFs
- [x] Apply image/logo overlay on PDFs
- [x] Handle encrypted / password-protected PDFs gracefully (throw or skip + log)

### Images (`ImageWatermarker`)

- [x] Apply text/image watermark to JPEG, PNG, WEBP via Imagick
- [x] GD fallback produces equivalent output when Imagick is absent
- [x] Verify opacity and rotation match the configured values

### Office documents (`OfficeWatermarker`) — *new per SDD*

- [ ] Implement `OfficeWatermarker` service for Office formats (docx, xlsx, pptx, odt, ods, odp)
- [ ] Set up headless LibreOffice / Collabora conversion-rendering pipeline
- [ ] Add Office MIME types to `WatermarkService::SUPPORTED_*`
- [ ] Handle conversion failures gracefully (skip + audit-log entry)
- [ ] Register the file action for Office MIME types in the Files app

### Service routing

- [x] `WatermarkService` delegates to the correct renderer per MIME type
- [x] Unsupported types are skipped with an audit-log entry

---

## Goal 2 — Visible and invisible metadata watermarks

### Visible watermarks

- [x] Text watermark with placeholders: `{username}`, `{email}`, `{date}`, `{datetime}`, `{filename}`
- [x] Image watermark (logo overlay)
- [x] Combined text + image
- [x] Placement: tiled diagonal, 45° rotation, configurable font size/color/opacity

### Invisible metadata watermark (`MetadataWatermarker`) — *new per SDD*

- [ ] Implement `MetadataWatermarker` service
- [ ] Embed traceability metadata (acting user, timestamp) into PDF/image/Office metadata
- [ ] Add `metadata` to `ApiController::VALID_TYPES`
- [ ] Add `metadata` to the `type` column enum (migration)
- [ ] Support applying invisible metadata alongside or independently of a visible watermark
- [ ] Verify embedded metadata survives the download path

---

## Goal 3 — Integration with sharing, permission, and file-event systems

### Triggers

- [x] **On demand** — file-action menu apply (in place)
- [x] **On upload** — `NodeWrittenListener` on `NodeWrittenEvent`
  - [x] Only trigger when config `trigger = on_upload`
  - [x] Guard against infinite loop (watermarked write re-triggering the listener)
- [x] **On download** — `DownloadController` streams a watermarked temp copy; original untouched
  - [x] Temp file cleaned up after the response is sent
- [x] **On share** — `ShareCreatedListener` on `ShareCreatedEvent`
  - [x] Save `{name}_shared.{ext}` copy in the same folder as the original

### Permissions

- [x] `applyWatermark` checks file ownership / read permission before processing
- [x] Resolve all file paths through `\OCP\Files\IRootFolder` (no path traversal)

---

## Goal 4 — Admin management UI

### Backend (Controllers / Settings)

- [x] `ApiController` — `getConfig`, `saveConfig`, `deleteConfig`, `applyWatermark`, `getLog`
- [x] `saveConfig` rejects invalid template tokens, type, trigger, and color
- [x] `applyWatermark` returns a descriptive error for unsupported file types
- [x] `getLog` is admin-only (403 for non-admins) via `IGroupManager::isAdmin()`
- [x] `SettingsController` — admin page (`settings#adminIndex`)
- [x] `AdminSettings` / `AdminSection` registered in `info.xml`

### Frontend (Vue 3)

- [ ] `AdminSettings.vue` — global policy, group overrides, default template; load on mount + save confirmation
  - global policy + default template + load-on-mount + save confirmation done; **group overrides UI still missing**
- [x] Scope config UI — MIME whitelist (`mime_types`)
- [x] Scope config UI — per-folder system-tag targeting (`folder_tag`)
- [x] `AuditLog.vue` — paginated table (page size selector, prev/next) wired to `GET /api/v1/log`
- [x] `WatermarkForm.vue` — live preview of template with variable substitution
- [ ] `WatermarkForm.vue` — image upload field: validate type (PNG/SVG) and size
  - current UI is a Nextcloud **path** field with extension-type validation; **no file upload + no size check**
- [x] `WatermarkModal.vue` — show file name + estimated processing time before on-demand apply
- [x] `main-admin.js` — Vue 3 entry mounts in the admin content area
- [x] `main-files.js` — register an "Apply Watermark" `FileAction` in the Files file/context menu
  - [x] Action shown in the file row context menu for supported MIME types only
  - [x] Hidden for unsupported types and multi-select (`files.length === 1`)
  - [x] `exec` opens `WatermarkModal` and awaits the apply result
  - [x] Spinner/loading state on the file row during processing
  - [x] Refresh the file list after completion
  - [x] Use the app SVG icon + localized display name/title

### Watermarked-file indicator (Files app) — *new*

Show a visual indicator in the Files list/details for files that have already been
watermarked. Detection sources the existing `watermark_log` table by file id.

#### Backend (lookup endpoint)

- [x] `ApiController::getWatermarkedStatus` — accept a list of file ids and return which are watermarked
  - [x] Route `GET /api/v1/watermarked` (`?ids=1,2,3`), `#[NoAdminRequired]`
  - [x] Resolve status from `watermark_log` (a file id is "watermarked" if any log row references it)
  - [x] Restrict the query to ids the acting user can access (no leaking other users' file ids)
- [x] `WatermarkLogMapper::findWatermarkedFileIds(array $fileIds): int[]` — single batched `IN (...)` query, distinct file ids
- [x] Decide invalidation semantics: a later non-watermark write replaces content in place, so a log row may be stale (documented as "has ever been watermarked" in the method docblock)

#### Frontend (`main-files.js`)

- [x] Register a Files `FileListAction` / row decorator that batches visible file ids and calls `GET /api/v1/watermarked`
  - implemented as a debounced `MutationObserver` over the file list that batches visible row ids
- [x] Render an indicator (app SVG icon badge) on watermarked rows; localized tooltip "This file is watermarked"
- [x] Only query/decorate supported MIME types (`SUPPORTED_MIME`)
  - enforced node-side via `supportedFileIds`; backend only logs supported types so passive lookups stay scoped too
- [x] Refresh the indicator after an on-demand apply completes (file just watermarked shows the badge)
- [x] Gracefully no-op when the lookup request fails (never block the file list)

#### Tests

- [x] `ApiControllerTest` — `getWatermarkedStatus` returns correct ids, empty for none, access-scoped
- [x] `WatermarkLogMapperTest` — `findWatermarkedFileIds` batched lookup + distinct
- [x] Jest — `main-files` indicator renders for watermarked ids and is absent otherwise

### Remove watermark (restore original) — *new*

Let a user undo a watermark after it has been applied. Because `watermarkInPlace` **burns**
the watermark into the PDF/image content (destructive, non-reversible), "remove" must mean
**restore a preserved copy of the pre-watermark original** — not algorithmically strip pixels.

#### Preserve the original (prerequisite)

- [ ] Decide where the pre-watermark original is preserved (pick one):
  - Nextcloud file **versions** — reuse `IVersionManager`; restore = revert to the pre-apply version (no extra storage, but version may be pruned/expire)
  - **App-managed backup** in app data keyed by file id (durable, but extra storage + cleanup lifecycle)
  - Sibling `{name}_original.{ext}` copy in the same folder (visible to user; simplest)
- [ ] In `WatermarkService::watermarkInPlace`, snapshot the original **before** `putContent` (per the chosen mechanism)
- [ ] Record the backup reference (version id / backup path) — extend `watermark_log` or a new column so removal can find it
- [ ] Guard: don't overwrite an existing original backup when re-watermarking an already-watermarked file

#### Backend (remove endpoint)

- [ ] `ApiController::removeWatermark(string $path)` — restore the original and mark the file un-watermarked
  - [ ] Route `POST /api/v1/remove`, `#[NoAdminRequired]`
  - [ ] Ownership / `isUpdateable` permission checks (mirror `applyWatermark`)
  - [ ] 422 when no preserved original exists (nothing to restore)
  - [ ] Restore content via the chosen mechanism, then clean up the backup
- [ ] `WatermarkService::removeWatermark(File $file)` — perform the restore + record the removal
- [ ] Update `watermark_log` so the file no longer counts as watermarked (insert a `removed` action, or clear rows) — keep the indicator query (`findWatermarkedFileIds`) consistent

#### Frontend (`main-files.js`)

- [ ] Register a "Remove Watermark" `FileAction`, enabled only for files that are currently watermarked (reuse the indicator lookup) and `files.length === 1`
- [ ] Confirmation dialog before restoring (destructive: discards the watermarked version)
- [ ] Spinner on the row + refresh file list and indicator after completion
- [ ] Localized display name/title + app SVG icon

#### Tests

- [ ] `WatermarkServiceTest` — original is snapshotted on apply; `removeWatermark` restores byte-identical original
- [ ] `ApiControllerTest` — `removeWatermark` happy path, 422 when no backup, permission guards
- [ ] `NodeWrittenListenerTest`/audit — removal records a log entry and clears watermarked status
- [ ] Jest — "Remove Watermark" action only shown for watermarked files

---

## Goal 5 — S3 storage backend support

Storage-agnostic by design: all file I/O goes through the Files API
(`getContent` / `putContent` / `newFile`); only short-lived temp copies touch the
local filesystem. No S3-specific code needed. `docker-compose.s3.yml` (Nextcloud +
MinIO) is provided to verify — see README "Testing with S3 storage (MinIO)".

- [x] `DownloadController` serves watermarked copy on S3-backed storage
  - stages content to a local temp via `getContent()` and streams that temp; original S3 object untouched (asserted in `DownloadControllerTest`)
- [ ] Verify on-demand / on-upload watermarking on an S3 primary-storage instance
  - harness ready (`docker-compose.s3.yml`); needs a manual run to tick off
- [ ] Verify on external S3 storage mount
  - `occ files_external:create` steps documented in README; needs a manual run to tick off

---

## Data model & database

- [ ] Migration creates `watermark_config` and `watermark_log` cleanly on MySQL, PostgreSQL, SQLite
- [ ] `watermark_config` columns include `mime_types`, `folder_tag`, and `metadata` type support
- [ ] `WatermarkConfigMapper` — `findByUser`, `findGlobal`, `findById`, `findByUserAndMimeType`
- [ ] Config resolution order: user → group → global → defaults
  - [ ] Wire **group** override (`group_id`) into `resolveConfig` (currently user → global only)
- [ ] `WatermarkLogMapper` — `findAll` with pagination (offset + limit)

---

## Dependencies & environment (SDD §9, §3)

- [ ] PHP deps: `setasign/fpdi`, `tecnickcom/tcpdf`
- [ ] Ensure `Imagick` (preferred) and `GD` (fallback) extensions available
- [ ] Configure headless LibreOffice / Collabora in the Docker dev environment — *new per SDD*
- [ ] Ensure PHP `exif` / metadata libraries available — *new per SDD*
- [ ] Frontend deps: `@nextcloud/vue`, `@nextcloud/axios`, `@nextcloud/files`
- [ ] Build assets (`npm run build`) and enable app (`occ app:enable files_watermark`)

---

## Audit log (SDD §5.5)

- [ ] Record timestamp, user, file path/id, trigger mode, config id on every watermark action
- [ ] Surface entries in the admin panel (Logging → Watermark Activity)
- [ ] Emit `CriticalActionPerformedEvent` to the Nextcloud admin audit log

---

## Security & quality (SDD §10)

- [ ] Validate ownership / read permission before processing
- [ ] Audit-log endpoint admin-only (403 otherwise)
- [ ] Sanitize watermark template output to prevent XSS in the settings UI
- [ ] Validate & store uploaded watermark images (MIME + size) outside the web root
- [ ] On-download temp file written to a secure temp dir and deleted after response
- [ ] Rate-limit / queue on-demand requests for large files
- [ ] Review FPDI licence compatibility for PDF 1.5+ / encrypted PDFs

---

## Testing (SDD §11)

### Unit (PHPUnit)

- [ ] `WatermarkServiceTest` — config resolution (user / group / global / default)
- [ ] `WatermarkServiceTest` — correct renderer delegated per MIME type
- [ ] `WatermarkConfigMapperTest` — finders + insert/update
- [x] `PdfWatermarkerTest` — text/image/combined overlays + multi-page + corrupt-PDF handling
- [x] `ImageWatermarkerTest` — JPEG/PNG/WEBP output, GD fallback, opacity + rotation
- [ ] `OfficeWatermarkerTest`, `MetadataWatermarkerTest`
- [ ] `ApiControllerTest` — auth guard, happy path, error responses per endpoint
- [x] `NodeWrittenListenerTest` / `ShareCreatedListenerTest` — trigger gating

### Frontend (Jest)

- [x] `WatermarkForm.spec.js`, `AuditLog.spec.js`, `AdminSettings.spec.js`

### Integration / E2E (Cypress)

- [ ] Upload PDF/image/Office → on-upload watermark applied
- [ ] On-demand apply via file action
- [ ] Create share → `_shared` copy watermarked
- [ ] Download via `/api/v1/download` → original untouched
- [ ] Run the full flow on an S3-backed instance

---

## Documentation & release (SDD §12)

- [ ] Document all API endpoints (incl. `/api/v1/download`) with request/response examples
- [ ] Developer guide: how to add a new file-type renderer
- [ ] Document the Docker dev workflow (incl. headless LibreOffice)
  - `docker-compose.yml` + README "Docker (local test environment)" section done; **headless LibreOffice not yet added** (pending Office support)
- [ ] Add `CHANGELOG.md` with v1.0.0 entry
- [ ] Bump `appinfo/info.xml` version to the release tag
- [ ] Package for the App Store and tag the release
