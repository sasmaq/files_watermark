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
- [x] **On share** — watermarked at *delivery* time, not at share-creation time
  - the SDD's original design (`ShareCreatedListener` on `ShareCreatedEvent` saving a
    `{name}_shared.{ext}` copy) was **not** built: it duplicates storage and leaves the
    original reachable through the same share. `DownloadInterceptorPlugin` streams a
    watermarked copy per fetch instead, keying off `WatermarkService::isShareAccess()`
  - [x] Internal share recipients receive a watermarked copy; the owner's own fetch is untouched
  - [x] Public links receive the same treatment — they are served by a *separate* Sabre
    server (`public.php/dav`, `BeforeSabrePubliclyLoadedEvent`) that never fires
    `SabrePluginAddEvent`, so it needs its own registration (`SabrePublicPluginAddListener`)
  - [x] Public links are served off the *owner's* storage, so the `ISharedStorage` test alone
    reports "owner access" — `isShareAccess()` also takes an explicit public-context flag and
    the anonymous-request signal
  - [x] Previews are blocked for recipients and public-link visitors (they render from the
    clean original and are cached per file, not per viewer)
  - [x] A render failure denies the fetch (403) rather than serving the clean original
  - [x] Folder / multi-file downloads are covered too — see the ZIP section below

### Folder / multi-file (ZIP) downloads — *new*

Downloading a **folder**, or a multi-file selection, streams an archive that bypasses the
watermark entirely — in every mode. `ZipFolderPlugin` (`apps/dav/.../ZipFolderPlugin.php`)
registers on `method:GET` at priority **100** and, for a `Directory` node with a zip/tar
`Accept` header or `?accept=zip`, streams each member straight from `$node->fopen('rb')`.
`DownloadInterceptorPlugin` runs earlier (priority 90) but returns immediately for
non-`DavFile` nodes, so it never sees the members.

This affects both the authenticated Files app ("Download" on a folder, "Download selected"
via the `files=` / `X-NC-Files` filter) and public links (`/s/{token}/download` on a folder
share redirects to `?accept=zip` on the public DAV endpoint).

**Implemented** by `ZipInterceptorPlugin`, which claims the archive request at priority 95
and rebuilds it with watermarked members. It mirrors core's request parsing (Accept header /
`?accept=`, the `files=` + `X-NC-Files` filter, archive naming, root-path handling) so
archives keep their familiar shape, and defers to core untouched when no delivery trigger
applies.

#### Interception strategy (decided)

- [x] Own `method:GET` handler at priority < 100 rebuilding the archive via `\OC\Streamer`
  - the alternative (a read-stream storage wrapper making `fopen('rb')` yield watermarked
    bytes) was rejected: far wider blast radius for the same outcome here
  - trade-off accepted: duplicates core's `streamNode` / request-parsing logic, so it must
    be re-checked against `ZipFolderPlugin` on Nextcloud upgrades
- [x] Suppress Sabre's own response for handled requests (`afterMethod:GET` → false), since
  the archive is written straight to the output buffer
- [x] Dispatch `BeforeZipCreatedEvent` before taking over, so other apps' download vetoes
  still apply
- [x] **Size handling:** only tar needs it — `ZipStreamer::addFileFromStream()` takes no size
  and derives it while streaming, while `TarStreamer` records it up front. The watermarked
  temp copy's `filesize()` is passed, not the original's
  - correction to the original note here, which claimed the size mattered for both

#### Per-mode behaviour

- [x] **On demand** — no work needed: the watermark is burned into the stored bytes, so a
  plain archive already carries it. The coarse gate (`deliveryApplies`) returns false and
  core handles the request untouched
- [x] **On upload** — same as on demand
- [x] **On download** — every supported member watermarked, for any downloader (verified:
  owner's own folder download is watermarked in this mode)
- [x] **On share** — members watermarked for share recipients and public-link visitors; the
  owner's own folder download is untouched
  - [x] Shares the `isShareAccess()` rule with the single-file path
  - [x] Registered on both DAV servers (`SabrePluginAddListener` / `SabrePublicPluginAddListener`)
  - [x] **Deny rather than leak:** members are rendered *before* any bytes are sent, so a
    failed render aborts with a real 403 instead of a truncated archive
- [ ] **Tar archives are broken in core** (`Accept: application/x-tar` yields a truncated
  archive) — reproduces identically on the untouched core path, so it is not caused by this
  plugin. Browsers request zip; worth an upstream report

#### Cross-cutting concerns

- [x] Non-watermarkable members (unsupported MIME, excluded by whitelist or folder tag)
  stream through untouched — an `on_share` archive containing them is **allowed**, matching
  single-file behaviour where such a file is served unmodified. Only a file the policy
  *does* cover and fails to render denies the archive
- [x] Bounded temp usage: members are rendered to temp files, capped by `MAX_MEMBERS` (200)
  and `MAX_BYTES` (256 MiB); temps are deleted in a `finally` on every exit path
  - note the deliberate departure from the original "never materialize all members" note:
    lazy streaming cannot produce a clean 403 once headers are out, so `on_share` correctness
    won over strict streaming. Cost is bounded by the caps
- [x] Over-cap behaviour: `on_share` denies (403); `on_download` degrades to core's plain
  archive, consistent with its documented best-effort contract
- [ ] Make the caps configurable rather than class constants
- [ ] Audit log granularity: currently one `watermark_log` row per watermarked member (each
  goes through `watermarkFile`). Confirm this is wanted for large archives, or batch it
- [ ] Extend `DownloadController` (`/api/v1/download`) to accept a folder path, or document
  that it stays single-file only (it currently returns "Path is not a file")

#### Tests

- [x] `WatermarkServiceTest` — a failed render cleans up its temp files (see below)
- [ ] Unit — the handler claims only `Directory` + archive-accepting GETs and defers
  everything else to core `ZipFolderPlugin`
- [ ] Unit — tar member size is the watermarked length, not the original
- [ ] Unit — over-cap `on_share` denies while over-cap `on_download` defers to core
- [x] Manual E2E against Nextcloud 31 — recipient folder zip, public-link folder zip,
  owner-untouched, `on_download` owner zip, `files=` multi-file selection, unrenderable
  member denies with 403 on both internal and public paths, no temp files left behind
- [ ] Automate the above as integration tests

#### Temp-file leak found while testing this — *fixed*

`WatermarkService::watermarkFile` writes the file's full plaintext to a `*_src` temp copy
before rendering, and only unlinked it on the success path. Every failed render therefore
left a readable copy of user content in the system temp dir forever. This predates the
archive work and affected the single-file download path too — it just surfaces constantly
here because every `on_share` deny goes through a failed render.

- [x] Clean up `*_src`, any partial output, and the temp dir when a render throws
- [x] `WatermarkServiceTest` pins it (asserts neither the source copy nor its dir survive)

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
- [x] `WatermarkForm.vue` — image upload field: validate type and size
  - the old **path** field is gone: the admin now picks a file, it uploads to
    `POST /api/v1/image`, and the config stores only the opaque reference it returns
  - client-side checks (type + 2 MB) are a convenience; `WatermarkImageStore` re-validates
    server-side from the file's **actual bytes**, which is the check that counts
  - **PNG/JPEG only — SVG was dropped deliberately.** It never worked in two of the three
    render paths (the GD fallback decodes only PNG/JPEG, and TCPDF's `Image()` cannot place
    an SVG), and storing attacker-authored markup that ImageMagick may parse with
    external-entity/remote-fetch delegates is not worth the one path where it did
  - preview thumbnail + Replace/Remove controls; uploads are admin-only
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
- [x] Interaction with **Remove watermark** — settled *without* deleting log rows, which this
  item originally assumed. Deleting them would destroy audit history; instead a `removed` row
  is appended and `findWatermarkedFileIds` resolves status from the newest in-place event per
  file, so a restored file is re-appliable and the full apply/undo history survives
  (see the restore section)

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

**Implemented** for `on_demand`. `OriginalStore` keeps the pre-watermark bytes, and
`removeWatermark` restores them.

#### Preserve the original (prerequisite)

- [x] Decided: **app-managed backup** in appdata (`OriginalStore`), keyed by file id
  - Nextcloud file versions were the alternative and were rejected: the versions app can be
    disabled and version expiry would silently delete the only route back to the original
  - appdata is outside every user's storage, so a backup is not itself browsable, shareable
    or watermarkable
- [x] `watermarkInPlace` snapshots the original **before** `putContent` — pinned by a test
  that asserts the store/write ordering, since reading after the write would preserve the
  watermarked bytes
- [x] No schema change needed for the backup reference: the appdata file *is* keyed by file id
- [x] Guard: `store()` never overwrites an existing backup, so re-watermarking cannot replace
  the true original with watermarked bytes
- [x] A failed backup is logged and does not abort the apply; the watermark just becomes
  un-removable, which the remove endpoint reports honestly (422)

#### Backend (remove endpoint)

- [x] `ApiController::removeWatermark(string $path)` — `POST /api/v1/remove`, `#[NoAdminRequired]`
  - [x] Readable + `isUpdateable` checks, mirroring `applyWatermark`
  - [x] 422 when no preserved original exists
  - [x] Restores, then discards the backup
- [x] `WatermarkService::removeWatermark(File $file)` — restore + record the removal
  - the backup is discarded only *after* the write lands, so a failed restore leaves the
    original recoverable on a later attempt
- [x] `watermark_log` gains a `removed` row rather than having rows deleted — this is an audit
  log, so the apply and the undo both stay in the history
- [x] `findWatermarkedFileIds` now decides status from the **most recent** in-place event per
  file instead of "any row exists", so apply → removed → apply resolves correctly
  - this also settles the open question from the *Skip already-watermarked* section: a removed
    file stops counting as watermarked and can be re-applied

#### Frontend (`main-files.js`)

- [x] "Remove watermark" `FileAction`, gated by `isRemoveActionEnabled` — the exact mirror of
  `isApplyActionEnabled`, reusing `isSingleSupportedFile` and differing only on the
  watermarked check, so a row never offers both
- [x] Confirmation dialog (`RemoveWatermarkModal`) warning that the watermarked version is
  discarded, with a destructive-styled confirm button
- [x] Spinner while restoring; badge cleared and both actions re-evaluated via
  `unmarkWatermarked` + a `files:node:updated` emit, without a folder reload
- [x] Localized display name + a restore/undo icon, deliberately distinct from the Apply icon
- [x] `files:node:updated` now *clears* a tracked id on an explicit `is-watermarked: 0`; a
  missing property still means "unknown" and leaves the set alone

#### Tests

- [x] `WatermarkServiceTest` — original preserved before the overwrite (ordering asserted);
  restore, the no-backup case, and backup retention when the write throws
- [x] `ApiControllerRemoveWatermarkTest` — happy path, 422 with no backup, 422 on a throwing
  restore, permission guards, unauthenticated, not-found
- [x] `WatermarkLogMapperTest` — a removal cancels an earlier apply; apply → removed → apply
  counts as watermarked again
- [x] Jest — Remove shown only for watermarked files and only in `on_demand`; mirror-of-Apply
  property; `unmarkWatermarked` clears the badge; explicit-0 vs missing property
- [x] Manual E2E against Nextcloud 31 — apply → remove restores a **byte-identical** original,
  backup discarded, status cleared, second remove 422s, re-apply works, audit trail keeps all
  three events

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
- [x] Validate & store uploaded watermark images (MIME + size) outside the web root
  - `WatermarkImageStore` writes to the app's appdata, names files itself (nothing
    client-supplied reaches the filesystem), caps at 2 MB and derives the type from the
    file's real bytes rather than its name or declared MIME
- [x] **Fixed: any account could make the renderers read an arbitrary server file.**
  `saveConfig` is `#[NoAdminRequired]` and stored `imagePath` verbatim, while the renderers
  `file_exists()`ed it as a raw server path — so a regular user could point their personal
  watermark at any image readable by the web server and have it composited into files they
  downloaded. Confirmed exploitable on the test instance before the fix
  - [x] `saveConfig` now rejects anything that is not a store-issued reference (400)
  - [x] `WatermarkImageStore::localPath()` refuses non-references at *render* time too, so
    configs already holding a path (they survive in the DB) resolve to no image and log a
    warning instead of reading the file — verified against the pre-fix row
  - [ ] Consider a migration that clears legacy `image_path` values, so the stale rows do
        not sit there looking valid; admins must re-upload either way
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
- [x] `NodeWrittenListenerTest` — trigger gating
  - no `ShareCreatedListenerTest`: on-share is delivery-time, so it is covered by
    `WatermarkServiceTest` (`deliveryTrigger` / `watermarkForDownload`, incl. the
    public-link path) and `BeforePreviewFetchedListenerTest`

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
