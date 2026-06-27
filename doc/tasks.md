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

- [ ] `applyWatermark` checks file ownership / read permission before processing
- [ ] Resolve all file paths through `\OCP\Files\IRootFolder` (no path traversal)

---

## Goal 4 — Admin management UI and per-user settings panel

### Backend (Controllers / Settings)

- [ ] `ApiController` — `getConfig`, `saveConfig`, `deleteConfig`, `applyWatermark`, `getLog`
- [ ] `saveConfig` rejects invalid template tokens, type, trigger, and color
- [ ] `applyWatermark` returns a descriptive error for unsupported file types
- [ ] `getLog` is admin-only (403 for non-admins) via `IGroupManager::isAdmin()`
- [ ] `SettingsController` — admin page (`settings#adminIndex`)
- [ ] `SettingsController` — personal page (`settings#personalIndex`) — *new per SDD*
- [ ] `AdminSettings` / `AdminSection` registered in `info.xml`
- [ ] `PersonalSettings` / `PersonalSection` registered in `info.xml` — *new per SDD*

### Frontend (Vue 3)

- [ ] `AdminSettings.vue` — global policy, group overrides, default template; load on mount + save confirmation
- [ ] Scope config UI — MIME whitelist (`mime_types`)
- [ ] Scope config UI — per-folder system-tag targeting (`folder_tag`)
- [ ] `AuditLog.vue` — paginated table (page size selector, prev/next) wired to `GET /api/v1/log`
- [ ] `WatermarkForm.vue` — live preview of template with variable substitution
- [ ] `WatermarkForm.vue` — image upload field: validate type (PNG/SVG) and size
- [ ] `WatermarkModal.vue` — show file name + estimated processing time before on-demand apply
- [ ] `PersonalSettings.vue` — per-user panel bounded by admin policy — *new per SDD*
- [ ] `main-admin.js` — Vue 3 entry mounts in the admin content area
- [ ] `main-personal.js` — Vue 3 entry for personal settings + webpack entry — *new per SDD*
- [ ] `main-files.js` — register `FileAction` for supported MIME types only
  - [ ] Hidden for unsupported types and multi-select (`files.length === 1`)
  - [ ] Spinner/loading state on the file row during processing
  - [ ] Refresh the file list after completion
  - [ ] Use the app SVG icon

---

## Goal 5 — S3 storage backend support

- [ ] `DownloadController` serves watermarked copy on S3-backed storage
- [ ] Verify on-demand / on-upload watermarking on an S3 primary-storage instance
- [ ] Verify on external S3 storage mount

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

- [ ] `WatermarkForm.spec.js`, `AuditLog.spec.js`, `AdminSettings.spec.js`, `PersonalSettings.spec.js`

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
- [ ] Add `CHANGELOG.md` with v1.0.0 entry
- [ ] Bump `appinfo/info.xml` version to the release tag
- [ ] Package for the App Store and tag the release
