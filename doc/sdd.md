# Software Development Document

## files_watermark - Nextcloud 31 File Watermarking App

**Version:** 1.0.0  
**Date:** 2026-06-27  
**Status:** Draft

---

## 1. Overview

`files_watermark` is a Nextcloud 31 application that enables administrators and users to apply configurable watermarks to files stored in Nextcloud. It supports **visible** watermarks (text and/or image overlays) on PDFs, images, and Office documents, and, where the format allows, **invisible metadata** watermarks. Watermarks are applied at **delivery**: a trigger decides which files carry a *mark* - on demand, or on upload - and every download and every preview of a marked file is rendered against the identity of whoever is fetching it. The stored file is never modified. The app integrates with Nextcloud's sharing, permission, and file-event systems. Administrators define a single, server-wide policy through a management panel. The app protects documents from unauthorized distribution, provides traceability via an audit log, and works on both local and S3 storage backends.

---

## 2. Goals and Scope

### Goals

- Allow users and administrators to watermark files (PDFs, images, Office documents) within Nextcloud.
- Support visible watermarks (text and/or image overlays) and, where applicable, invisible metadata watermarks.
- Integrate with Nextcloud's existing sharing, permission, and file event system.
- Provide a management UI in the Nextcloud admin panel for the single, server-wide policy.
- Support for S3 storage backends.

### Out of Scope (v1.0)

- Video file watermarking.
- Steganographic (hidden bit-level) watermarking.

---

## 3. Target Environment

| Property | Value |
| --- | --- |
| Nextcloud version | 31.x |
| PHP version | 8.2 / 8.3 |
| Database | MySQL 8+, PostgreSQL 14+, SQLite (dev only) |
| Supported OS | Linux (Ubuntu 22.04+, Debian 12+, RHEL 9+) |
| App type | Nextcloud Files App (server-side PHP + Vue.js frontend) |

---

## 4. Architecture

### 4.1 High-Level Components

```text
┌─────────────────────────────────────────────────────────┐
│                    Nextcloud Server                      │
│                                                         │
│  ┌──────────────┐   ┌──────────────┐  ┌─────────────┐  │
│  │  files_      │   │  Watermark   │  │  Storage    │  │
│  │  watermark   │──▶│  Engine      │──▶  Layer      │  │
│  │  App         │   │  (PHP)       │  │  (OC\Files) │  │
│  └──────┬───────┘   └──────────────┘  └─────────────┘  │
│         │                                               │
│  ┌──────▼───────┐   ┌──────────────┐                   │
│  │  Vue.js UI   │   │  Nextcloud   │                   │
│  │  (Settings / │   │  Event/Hook  │                   │
│  │   File menu) │   │  System      │                   │
│  └──────────────┘   └──────────────┘                   │
└─────────────────────────────────────────────────────────┘
```

### 4.2 Module Breakdown

| Module | Description |
| --- | --- |
| `ApiController` | REST API endpoints for watermark configuration, on-demand watermarking, and audit-log retrieval |
| `DownloadController` | Streams a watermarked temporary copy at download time; original file untouched |
| `SettingsController` | Backend for the admin management panel |
| `WatermarkService` | Core watermark application logic; resolves the effective config and delegates to format-specific renderers |
| `PdfWatermarker` | Applies text/image overlays to PDF files using a PHP PDF library |
| `ImageWatermarker` | Applies watermarks to JPEG, PNG, WEBP via GD (Imagick for what GD cannot decode) |
| `OfficeWatermarker` | Applies watermarks to Office documents (e.g. via headless conversion / document rendering) |
| `MetadataWatermarker` | Embeds invisible metadata watermarks where the file format supports it |
| `NodeWrittenListener` | Listens to `NodeWrittenEvent` to mark on upload |
| `BeforePreviewFetchedListener` | Notes which file a preview request is for - the one hook every preview endpoint passes through |
| `WatermarkPreviewMiddleware` | Global middleware; replaces a marked file's preview with one naming the viewer |
| `WatermarkMarkMapper` | ORM mapper for the marks - which files are watermarked on delivery |
| `WatermarkConfigMapper` | ORM mapper for persisting watermark policies/templates in the database |
| `WatermarkLogMapper` | ORM mapper for the audit log (with pagination) |
| `Vue.js frontend` | Admin management panel and file-action menu integration |

---

## 5. Key Features

### 5.1 Watermark Types

- **Text watermark**
  - default string (`{displayname}` + `{date}`)
  - custom string (supports placeholders: `{displayname}`, `{username}`, `{email}`, `{date}`, `{datetime}`, `{filename}`)

- **Image watermark**
  - upload a logo/image to overlay on files

- **Combined**
  - text + image simultaneously

- **Invisible metadata watermark** (where the format supports it)
  - embeds traceability information (e.g. acting user, timestamp) into document/image metadata
  - applied independently of, or alongside, a visible watermark

**Supported file types:** PDFs, images (JPEG, PNG, WEBP), and Office documents. Unsupported types are skipped with an audit-log entry.

### 5.2 Placement and Style (Text)

- Position: repeated diagonal (tiled)
- Rotation angle at 45 degree
- Font size, font color (hex, `#808080` by default), opacity at 40%

### 5.3 Trigger Modes

The trigger decides **which files are marked**, never when the watermark is drawn.

- **On demand** - a user marks a file through the file action menu
- **On upload** - every supported file is marked as it is written (`NodeWrittenEvent`)

A marked file is rendered watermarked on **every fetch** - download, archive member and
preview alike - onto a temporary copy, against the identity of the reader: the session
user, or the file's owner for an anonymous public-link visitor. There is no exemption for
the owner. A marked file whose render fails is refused (403) rather than served clean.

`on_download` and `on_share` were separate triggers in earlier drafts. They answered "when
is the watermark produced", which delivery now answers for every marked file, so they have
nothing left to select.

### 5.4 Scope Configuration (Admin)

- Apply globally to all users
- Apply per-folder (tag-based targeting using Nextcloud's system tags)
- Whitelist file MIME types to watermark

### 5.5 Audit Log

- Every watermark action is recorded: timestamp, user, file path, trigger mode.
- Viewable in the Nextcloud admin panel under **Logging → Watermark Activity**.

---

## 6. Data Model

### `oc_watermark_config`

| Column | Type | Description |
| --- | --- | --- |
| `id` | INT PK | Auto-increment |
| `type` | ENUM('text','image','combined','metadata') | Watermark type |
| `text_template` | TEXT | Text with placeholders |
| `image_path` | VARCHAR(512) | Nextcloud path to watermark image |
| `opacity` | TINYINT | 0–100 |
| `font_size` | SMALLINT | pt |
| `color` | CHAR(7) | Hex color |
| `rotation` | SMALLINT | Degrees |
| `trigger` | VARCHAR(64) | Trigger mode (`on_demand`, `on_upload`) |
| `mime_types` | TEXT | Comma-separated MIME whitelist; empty = all supported types |
| `folder_tag` | VARCHAR(64) | Nextcloud system-tag ID for per-folder targeting; NULL = apply globally |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

### `oc_watermark_mark`

Which files are watermarked on delivery. Its own table rather than a column on the log,
because the log is history and this is a statement about what happens next.

| Column | Type | Description |
| --- | --- | --- |
| `id` | BIGINT PK | Auto-increment |
| `file_id` | BIGINT | `oc_filecache.fileid`, **unique** - what makes marking idempotent |
| `marked_by` | VARCHAR(64) | Who placed the mark, not who the watermark names |
| `trigger` | VARCHAR(32) | Which trigger placed it |
| `config_id` | INT FK | The policy in force at the time |
| `created_at` | DATETIME | |

### `oc_watermark_log`

| Column | Type | Description |
| --- | --- | --- |
| `id` | BIGINT PK | Auto-increment |
| `user_id` | VARCHAR(64) | Acting user |
| `file_id` | BIGINT | `oc_filecache.fileid` |
| `file_path` | TEXT | Path at time of watermarking |
| `trigger` | VARCHAR(32) | What triggered this event |
| `config_id` | INT FK | Which config was applied |
| `created_at` | DATETIME | |

---

## 7. API Endpoints

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/apps/files_watermark/api/v1/config` | Get the global config (admin only) |
| `POST` | `/apps/files_watermark/api/v1/config` | Create or update the global config (admin only) |
| `DELETE` | `/apps/files_watermark/api/v1/config/{id}` | Remove the global config (admin only) |
| `POST` | `/apps/files_watermark/api/v1/apply` | Mark a file on demand |
| `POST` | `/apps/files_watermark/api/v1/remove` | Unmark a file (**owner only**) |
| `GET` | `/apps/files_watermark/api/v1/download` | Stream a file, watermarked if it is marked |
| `GET` | `/apps/files_watermark/api/v1/log` | Retrieve audit log (admin only) |

All endpoints require a valid Nextcloud session or app password. Admin-only endpoints enforce an admin-group check via `\OCP\IGroupManager::isAdmin()`.

---

## 8. Frontend (Vue.js)

- **Admin Settings** (`/settings/admin/watermark`) - global policy, default template, MIME/tag scope, audit log viewer.

- **File Action** - context menu entry "Apply Watermark" on a single supported file; shows a preview/confirmation modal before committing.

Built with **Vue 3 + Composition API**, using **@nextcloud/vue** component library and **@nextcloud/axios** for API calls, consistent with Nextcloud 31 app standards.

---

## 9. Dependencies

| Dependency | Purpose |
| --- | --- |
| `tecnickcom/tc-lib-pdf` (PHP) | PDF page import, overlay rendering and writing |
| `tecnickcom/tc-lib-pdf-parser` (PHP) | PDF parsing, including PDF 1.5+ compressed cross-reference streams |
| `ext-bcmath` (PHP) | Required by `tc-lib-pdf`; the app will not enable without it |
| `ext-gd` (PHP) | The image renderer (JPEG/PNG/WEBP); the app will not enable without it |

No external binaries. The app spawns no processes - no `exec()` and no shelling out to
`qpdf`, `pdftoppm` or Ghostscript - so a host needs nothing beyond PHP and the extensions
above. The PDF flattening feature, which rasterised each page through an external
renderer, was removed for this reason.
| PHP `GD` extension | Image watermarking - the default engine |
| PHP `Imagick` extension | Optional; used for formats GD cannot decode (WebP without libwebp) |
| LibreOffice / Collabora (headless) | Office document conversion/rendering for watermarking |
| PHP `exif` / metadata libraries | Reading/writing invisible metadata watermarks |
| `@nextcloud/vue` | Nextcloud UI component library |
| `@nextcloud/axios` | Authenticated HTTP client for Vue frontend |
| `@nextcloud/files` | File-action registration in the Files app |

---

## 10. Security Considerations

- Watermark images uploaded by users are validated for MIME type and stored outside the web root.
- Every watermark is generated into a temporary file in a secure temp directory and deleted after the response is sent. Nothing is written back to the user's storage.
- Watermarked previews are never written to Nextcloud's preview cache, which is keyed by file and size and not by viewer; they are rendered per response and marked `no-store`.
- All API inputs are sanitized and validated; file paths are resolved through `\OCP\Files\IRootFolder` to prevent path traversal.
- Audit log access is restricted to Nextcloud admins.
- **Removing a watermark is restricted to the file's owner.** Applying one requires write permission, which a share recipient may have; removing one requires ownership, because a recipient who can take the watermark off the document they were given defeats the reason it is there. The asymmetry is deliberate and one-directional - a recipient may still apply.

---

## 11. Testing Strategy

| Layer | Tool | Coverage Target |
| --- | --- | --- |
| Unit (PHP) | PHPUnit | WatermarkService, renderers, mappers |
| Integration (PHP) | PHPUnit + in-memory DB | API controllers, event listeners |
| Frontend | Jest + Vue Test Utils | Settings components, file action modal |
| End-to-end | Cypress (Nextcloud test infra) | Upload → watermark → download flow |

---

## 12. Deployment and Distribution

- App packaged following the [Nextcloud App Store guidelines](https://nextcloudappstore.readthedocs.io).
- Minimum Nextcloud version declared in `appinfo/info.xml`: `31`.
- Installation: **Admin → Apps → search "files_watermark" → Enable**, or via `occ app:install files_watermark`.
- No external service dependencies; all processing is local to the Nextcloud server.

---
