# files_watermark — Task List

## Setup & Environment

- [x] Install PHP dependencies: `composer install`
- [x] Install JS dependencies: `npm install --legacy-peer-deps`
- [x] Build frontend assets: `npm run build`
- [x] Configure a local Nextcloud 31 instance for development (Docker: `docker run -d -p 8080:80 nextcloud:31.0.14-apache`)
- [x] Copy or symlink the app into Nextcloud's `apps/` directory
- [x] Enable the app: `occ app:enable files_watermark`
- [x] Verify DB migration ran (it runs automatically on `occ app:enable`); if tables are missing, run: `occ migrations:migrate files_watermark`

---

## Backend

### Core Services

- [x] **WatermarkService** — verify config resolution order (user config → global config → defaults)
- [x] **WatermarkService** — confirm audit log entry is written on every trigger path (on_upload, on_demand, on_share)
- [x] **PdfWatermarker** — test tiled diagonal watermark across multi-page PDFs
- [x] **PdfWatermarker** — handle encrypted / password-protected PDFs gracefully (throw or skip with log entry)
- [x] **ImageWatermarker** — verify Imagick path produces correct opacity and rotation
- [x] **ImageWatermarker** — verify GD fallback produces equivalent output when Imagick is absent
- [x] **ImageWatermarker** — add WEBP write support check (GD `imagecreatefromwebp` / `imagewebp`)

### Controllers

- [x] **ApiController** — validate that `saveConfig` rejects invalid template tokens
- [x] **ApiController** — ensure `applyWatermark` returns a descriptive error when the file type is unsupported
- [x] **DownloadController** — serve watermarked copy without modifying the original file
- [x] **DownloadController** — test with files on S3 (remote storage) backends
- [x] **SettingsController** — confirm admin template response mounts the correct Vue entry point

### Event Listener

- [x] **NodeWrittenListener** — verify it only triggers when `trigger = on_upload` is set in config
- [x] **NodeWrittenListener** — add guard against infinite loop (watermarked file write re-triggering the listener)
- [x] **ShareCreatedListener** — test that `{name}_shared.{ext}` copy is saved in the same folder as the original

### Database

- [x] **WatermarkConfigMapper** — add `findByUserAndMimeType` query for fine-grained per-type configs
- [x] **WatermarkLogMapper** — add pagination support to `findAll` (offset + limit)
- [x] Verify migration creates both tables (`oc_watermark_config`, `oc_watermark_log`) cleanly on MySQL, PostgreSQL, and SQLite

---

## Frontend (Vue 3)

- [x] **AdminSettings.vue** — global config form: load existing config on mount, show save confirmation
- [x] **AdminSettings.vue** — wire audit log tab to `GET /api/v1/log` with pagination controls
- [x] **AuditLog.vue** — implement paginated table (page size selector, prev/next)
- [x] **WatermarkForm.vue** — live preview of the watermark template string with variable substitution
- [x] **WatermarkForm.vue** — image watermark upload field: validate file type (PNG/SVG) and size limit
- [x] **WatermarkModal.vue** — show file name and estimated processing time before confirming on-demand apply
- [x] **main-files.js** — register `FileAction` for supported MIME types only (PDF, JPEG, PNG, WEBP)
- [x] **main-admin.js** — confirm Vue 3 entry point mounts correctly inside Nextcloud's content area

---

## Testing

### Unit Tests (PHPUnit)

- [ ] **WatermarkServiceTest** — cover config resolution with user override, global fallback, and no-config case
- [ ] **WatermarkServiceTest** — mock `PdfWatermarker` and `ImageWatermarker`; assert correct delegator is called per MIME type
- [ ] **WatermarkConfigMapperTest** — cover `findByUser`, `findGlobal`, `findById`, and insert/update
- [ ] Add `PdfWatermarkerTest` — unit test with a sample PDF fixture
- [ ] Add `ImageWatermarkerTest` — unit test with sample JPEG/PNG/WEBP fixtures (mock Imagick)
- [ ] Add `ApiControllerTest` — test each endpoint: auth guard, happy path, error responses
- [ ] Add `NodeWrittenListenerTest` — assert watermark is applied only when trigger matches

### Frontend Tests (Jest)

- [ ] Add `WatermarkForm.spec.js` — test template variable rendering and form validation
- [ ] Add `AuditLog.spec.js` — test pagination state and row rendering
- [ ] Add `AdminSettings.spec.js` — test config load on mount and save flow

### Integration / E2E

- [ ] Upload a PDF and verify the on-upload watermark is applied
- [ ] Upload an image (JPEG, PNG, WEBP) and verify watermark placement
- [ ] Apply watermark on demand via the file action menu
- [ ] Create a share and verify the `_shared` copy is watermarked
- [ ] Download via `/apps/files_watermark/download/{fileId}` and confirm original is untouched
- [ ] Test on S3-backed Nextcloud instance

---

## Security & Quality

- [ ] Validate that `applyWatermark` checks file ownership/read permission before processing
- [ ] Ensure audit log endpoint is admin-only (return 403 for non-admins)
- [ ] Sanitize watermark template output to prevent XSS in the settings UI
- [ ] Rate-limit or queue on-demand watermark requests to prevent server overload on large files
- [ ] Review FPDI license compatibility (FPDI community edition restricts PDF 1.5+ — consider FPDI PRO or alternative for encrypted PDFs)

---

## Documentation

- [ ] Document all API endpoints with request/response examples (extend README or add `doc/api.md`)
- [ ] Add a developer guide: how to add a new file-type renderer
- [ ] Document Docker development workflow end-to-end
- [ ] Add CHANGELOG.md with v1.0.0 entry

---

## Release

- [ ] Bump version in `appinfo/info.xml` to match release tag
- [ ] Run `npm run build` and commit compiled `js/` assets
- [ ] Tag release: `git tag v1.0.0`
- [ ] Package app for Nextcloud App Store: `tar -czf files_watermark.tar.gz files_watermark/`
