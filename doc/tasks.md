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
  - [x] Show the action **only when the effective trigger is `on_demand`** (hidden in `on_upload` / `on_download` / `on_share` modes)
    - `LoadAdditionalScriptsListener` resolves the effective trigger (user → global → default via `WatermarkService::resolveConfig`) and hands it to `main-files.js` as initial state (`effective-trigger`); `getEffectiveTrigger()` / `isOnDemandTrigger()` read it, defaulting to `on_demand` when absent
    - the shared single-file + supported-MIME + `on_demand` conditions are factored into `isSingleSupportedFile()` so the **Remove Watermark** action can reuse the same `on_demand`-only rule (see the Remove watermark section)

### Watermarked-file indicator (Files app) — *new*

Show a visual indicator in the Files list/details for files that have already been
watermarked. Detection sources the existing `watermark_log` table by file id and is
delivered to the client through a WebDAV property (`nc:is-watermarked`).

#### Backend (status lookup + DAV property)

- [x] `ApiController::getWatermarkedStatus` — accept a list of file ids and return which are watermarked
  - [x] Route `GET /api/v1/watermarked` (`?ids=1,2,3`), `#[NoAdminRequired]`
  - [x] Resolve status from `watermark_log` (a file id is "watermarked" if any log row references it)
  - [x] Restrict the query to ids the acting user can access (no leaking other users' file ids)
- [x] `WatermarkLogMapper::findWatermarkedFileIds(array $fileIds): int[]` — single batched `IN (...)` query, distinct file ids
- [x] Decide invalidation semantics: a later non-watermark write replaces content in place, so a log row may be stale (documented as "has ever been watermarked" in the method docblock)
- [x] `PropFindPlugin` (DAV `ServerPlugin`) — expose the `nc:is-watermarked` property per node, primed with one batched `findWatermarkedFileIds` query per folder listing; registered via `SabrePluginAddListener`
  - **this is now the indicator's primary status source**; `getWatermarkedStatus` is kept as a REST endpoint but is no longer called by the Files UI

#### Frontend (`main-files.js`)

- [x] Read watermarked status from the `nc:is-watermarked` WebDAV property delivered with each listing (`registerDavProperty` + `isNodeWatermarked(node)`) — no per-listing fetch
  - supersedes the earlier `GET /api/v1/watermarked` id-batching approach
- [x] Render an indicator (app SVG icon badge) on watermarked rows; localized tooltip "This file is watermarked"
  - `decorateRows()` badges rows; a debounced `MutationObserver` re-runs it as rows mount/recycle (DOM decoration only — status still comes from the DAV property)
- [x] Only decorate supported MIME types (`SUPPORTED_MIME`)
  - the PROPFIND plugin only marks supported types, so the property stays scoped server-side
- [x] Refresh the indicator after an on-demand apply completes (file just watermarked shows the badge)
- [x] Gracefully no-op when the property is absent (treated as not watermarked; never blocks the file list)

#### Tests

- [x] `ApiControllerWatermarkedStatusTest` — `getWatermarkedStatus` returns correct ids, empty for none, access-scoped
- [x] `WatermarkLogMapperTest` — `findWatermarkedFileIds` batched lookup + distinct
- [x] Jest — `decorateRows` badges only watermarked rows and is idempotent; strips a stale badge on a recycled row

### Skip watermarking already-watermarked files — *new*

Prevent applying a watermark to a file that has already been watermarked, so a file is
never double-stamped. "Already watermarked" reuses the indicator's definition — a file id
that has any row in `watermark_log` (see `findWatermarkedFileIds`). Enforce it on the
backend (the source of truth) and surface it in the UI so the action is not offered.

#### Backend (authoritative guard)

- [x] `ApiController::applyWatermark` — short-circuit when already watermarked
  - implemented by branching on the service return value (single source of truth)
    rather than a second lookup; the node's file id is already resolved from `$path`
  - [x] Return a distinct, non-error response the UI can branch on
    (`['status' => 'already_watermarked', 'path' => $path]`, HTTP 200)
  - [x] Resolve the node's file id from `$path` within the user folder (same access
    scoping as `getWatermarkedStatus`)
- [x] `WatermarkService` — skip + report via `watermarkInPlace(): bool`
  - `isAlreadyWatermarked(int $fileId)` guards the **in-place** triggers
    (`on_demand`, `on_upload`); `watermarkInPlace` returns `false` when skipped
  - **scope note:** `on_share` / `on_download` go through `watermarkFile` against the
    clean original (never burned in place), so they can't cumulatively re-stamp and are
    intentionally *not* guarded — guarding them would serve/copy un-watermarked content
  - [x] Return a boolean / status from the service so callers can tell "applied" from
    "skipped (already watermarked)"
- [ ] Decide interaction with **Remove watermark**: once restore lands, removing must
  delete the `watermark_log` row(s) for the file id, otherwise the file stays "already
  watermarked" and can never be re-applied (cross-link the restore section)

#### Frontend (`main-files.js`)

- [x] `enabled(files)` reads the node's `nc:is-watermarked` DAV property directly
  (`isApplyActionEnabled` → `!isNodeWatermarked(file)`), so the action is hidden
  synchronously the moment a watermarked row mounts — no client-side id cache needed
  - supersedes the earlier `rememberWatermarked` `Set` and its `enabled()`-memoization
    caveat: because the property arrives with the listing, first-mount evaluation is
    already correct
- [x] After a successful on-demand apply, re-decorate so the badge appears immediately
  without a full list refresh (`decorateRows`)
- [x] Handle the backend `already_watermarked` response in `WatermarkModal` as an
  informational (`info`) note, not an error

#### Tests

- [x] `ApiControllerApplyWatermarkTest` — already-watermarked (service returns `false`)
  yields `already_watermarked`; the applied path returns `watermarked`
- [x] `WatermarkServiceTest` — `watermarkInPlace` skips (no render / no `putContent` /
  no `insertLog`, returns `false`) when the file id is already in `watermark_log`
- [x] Jest — `isApplyActionEnabled` returns `false` when the node carries the
  `nc:is-watermarked` property and `true` otherwise

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

- [ ] Register a "Remove Watermark" `FileAction`, shown only when **the effective trigger is `on_demand`**, the single file is currently watermarked (`isNodeWatermarked`), and `files.length === 1` — the mirror of the Apply action's availability rule (see Goal 4)
  - factor the shared conditions (single file, supported MIME, `on_demand` trigger) into one helper used by both `isApplyActionEnabled` and the Remove action; the two differ only on the watermarked check (Apply requires **not** watermarked, Remove requires watermarked)
- [ ] Confirmation dialog before restoring (destructive: discards the watermarked version)
- [ ] Spinner on the row + refresh file list and indicator after completion
- [ ] Localized display name/title + app SVG icon

#### Tests

- [ ] `WatermarkServiceTest` — original is snapshotted on apply; `removeWatermark` restores byte-identical original
- [ ] `ApiControllerTest` — `removeWatermark` happy path, 422 when no backup, permission guards
- [ ] `NodeWrittenListenerTest`/audit — removal records a log entry and clears watermarked status
- [ ] Jest — "Remove Watermark" action only shown for watermarked files, and only in `on_demand` trigger mode (hidden in other modes); Apply is hidden in non-`on_demand` modes too

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
  - migration exists using the portable schema builder; a cross-DB run is not yet verified
- [ ] `watermark_config` columns include `mime_types`, `folder_tag`, and `metadata` type support
  - `mime_types` and `folder_tag` columns present; **`metadata` type not yet supported** (`ApiController::VALID_TYPES` is `text` / `image` / `combined`)
- [x] `WatermarkConfigMapper` — `findByUser`, `findGlobal`, `findById`, `findByUserAndMimeType`
- [ ] Config resolution order: user → group → global → defaults
  - `resolveConfig` does user → global → default; **group override (`group_id`) still not wired in**
- [x] `WatermarkLogMapper` — `findAll` with pagination (`findAll(int $limit = 100, int $offset = 0)`)

---

## Dependencies & environment (SDD §9, §3)

- [x] PHP deps: `setasign/fpdi` (`^2.6`), `tecnickcom/tcpdf` (`^6.7`) in `composer.json`
- [x] Ensure `Imagick` (preferred) and `GD` (fallback) extensions available
  - `ImageWatermarker` prefers Imagick and falls back to GD; both paths covered by `ImageWatermarkerTest`
- [ ] Configure headless LibreOffice / Collabora in the Docker dev environment — *new per SDD*
- [ ] Ensure PHP `exif` / metadata libraries available — *new per SDD*
- [x] Frontend deps: `@nextcloud/vue` (`^9.8`), `@nextcloud/axios` (`^2.5`), `@nextcloud/files` (`^3.9`)
- [x] Build assets (`npm run build`) and enable app (`occ app:enable files_watermark`)

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

- [x] `WatermarkServiceTest` — config resolution (user / global / default)
  - **group** case not covered because group resolution is not implemented yet
- [x] `WatermarkServiceTest` — correct renderer delegated per MIME type (PDF + image), plus skip/whitelist/already-watermarked paths
- [ ] `WatermarkConfigMapperTest` — finders + insert/update
  - the existing `WatermarkConfigMapperTest` covers the **entity** (`jsonSerialize`, `getAllowedMimeTypes`); mapper finders + insert/update are **not** yet tested
- [x] `PdfWatermarkerTest` — text/image/combined overlays + multi-page + corrupt-PDF handling
- [x] `ImageWatermarkerTest` — JPEG/PNG/WEBP output, GD fallback, opacity + rotation
- [ ] `OfficeWatermarkerTest`, `MetadataWatermarkerTest`
- [ ] `ApiControllerTest` — auth guard, happy path, error responses per endpoint
  - `ApiControllerApplyWatermarkTest` (apply / already-watermarked) and `ApiControllerWatermarkedStatusTest` exist; `getConfig` / `saveConfig` / `deleteConfig` / `getLog` still untested
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
