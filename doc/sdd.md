# Software Development Document

## files_watermark — Nextcloud 31 File Watermarking App

**Version:** 1.0.0  
**Date:** 2026-06-27  
**Status:** Draft

---

## 1. Overview

`files_watermark` is a Nextcloud 31 application that enables administrators and users to apply configurable watermarks to files stored in Nextcloud. It supports **visible** watermarks (text and/or image overlays) on PDFs, images, and Office documents, and, where the format allows, **invisible metadata** watermarks. Watermarks can be applied through several triggers — on demand, on upload, on download, and on share — and the app integrates with Nextcloud's sharing, permission, and file-event systems. Administrators define global and group policies through a management panel, while users adjust their own preferences through a per-user settings panel. The app protects documents from unauthorized distribution, provides traceability via an audit log, and works on both local and S3 storage backends.

---

## 2. Goals and Scope

### Goals

- Allow users and administrators to watermark files (PDFs, images, Office documents) within Nextcloud.
- Support visible watermarks (text and/or image overlays) and, where applicable, invisible metadata watermarks.
- Integrate with Nextcloud's existing sharing, permission, and file event system.
- Provide a management UI in the Nextcloud admin panel and a per-user settings panel.
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
| `SettingsController` | Backend for the admin management panel and per-user settings panel |
| `WatermarkService` | Core watermark application logic; resolves the effective config and delegates to format-specific renderers |
| `PdfWatermarker` | Applies text/image overlays to PDF files using a PHP PDF library |
| `ImageWatermarker` | Applies watermarks to JPEG, PNG, WEBP via Imagick (GD fallback) |
| `OfficeWatermarker` | Applies watermarks to Office documents (e.g. via headless conversion / document rendering) |
| `MetadataWatermarker` | Embeds invisible metadata watermarks where the file format supports it |
| `NodeWrittenListener` | Listens to `NodeWrittenEvent` to auto-watermark on upload |
| `ShareCreatedListener` | Listens to `ShareCreatedEvent` to watermark on share |
| `WatermarkConfigMapper` | ORM mapper for persisting watermark policies/templates in the database |
| `WatermarkLogMapper` | ORM mapper for the audit log (with pagination) |
| `Vue.js frontend` | Admin management panel, per-user settings panel, and file-action menu integration |

---

## 5. Key Features

### 5.1 Watermark Types

- **Text watermark**
  - default string (`{username}` + `{datetime}`)
  - custom string (supports placeholders: `{username}`, `{email}`, `{date}`, `{datetime}`, `{filename}`)

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
- Font size, font color (hex), opacity at 80%

### 5.3 Trigger Modes

- **On demand** — user or admin triggers watermarking via the file action menu
- **On upload** — watermark applied automatically when a matching file is written (`NodeWrittenEvent`)
- **On download** — watermark applied to a temporary copy served to the downloader; original file untouched
- **On share** — watermark applied when a public link or internal share is created (`ShareCreatedEvent`)

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
| `user_id` | VARCHAR(64) | NULL = global/group config |
| `group_id` | VARCHAR(64) | NULL = user/global config |
| `type` | ENUM('text','image','combined','metadata') | Watermark type |
| `text_template` | TEXT | Text with placeholders |
| `image_path` | VARCHAR(512) | Nextcloud path to watermark image |
| `position` | VARCHAR(32) | Placement identifier |
| `opacity` | TINYINT | 0–100 |
| `font_size` | SMALLINT | pt |
| `color` | CHAR(7) | Hex color |
| `rotation` | SMALLINT | Degrees |
| `trigger` | VARCHAR(64) | Trigger mode (`on_demand`, `on_upload`, `on_download`, `on_share`) |
| `mime_types` | TEXT | Comma-separated MIME whitelist; empty = all supported types |
| `folder_tag` | VARCHAR(64) | Nextcloud system-tag ID for per-folder targeting; NULL = apply globally |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

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
| `GET` | `/apps/files_watermark/api/v1/config` | Get current user/admin config |
| `POST` | `/apps/files_watermark/api/v1/config` | Create or update config |
| `DELETE` | `/apps/files_watermark/api/v1/config/{id}` | Remove a config |
| `POST` | `/apps/files_watermark/api/v1/apply` | Apply watermark to a file on demand |
| `GET` | `/apps/files_watermark/api/v1/download` | Stream a watermarked copy of a file (original untouched) |
| `GET` | `/apps/files_watermark/api/v1/log` | Retrieve audit log (admin only) |

All endpoints require a valid Nextcloud session or app password. Admin-only endpoints enforce an admin-group check via `\OCP\IGroupManager::isAdmin()`.

---

## 8. Frontend (Vue.js)

- **Admin Settings** (`/settings/admin/watermark`) — global policy, group overrides, default template, MIME/tag scope, audit log viewer.
- **Personal Settings** (`/settings/user/watermark`) — per-user settings panel for users to manage their own watermark template and preferences within the bounds allowed by admin policy.
- **File Action** — context menu entry "Apply Watermark" on a single supported file; shows a preview/confirmation modal before committing.

Built with **Vue 3 + Composition API**, using **@nextcloud/vue** component library and **@nextcloud/axios** for API calls, consistent with Nextcloud 31 app standards.

---

## 9. Dependencies

| Dependency | Purpose |
| --- | --- |
| `setasign/fpdi` (PHP) | PDF page import and overlay rendering |
| `tecnickcom/tcpdf` (PHP) | PDF text/image watermark writing |
| PHP `Imagick` extension | Preferred image watermarking (better quality) |
| PHP `GD` extension | Image watermarking fallback |
| LibreOffice / Collabora (headless) | Office document conversion/rendering for watermarking |
| PHP `exif` / metadata libraries | Reading/writing invisible metadata watermarks |
| `@nextcloud/vue` | Nextcloud UI component library |
| `@nextcloud/axios` | Authenticated HTTP client for Vue frontend |
| `@nextcloud/files` | File-action registration in the Files app |

---

## 10. Security Considerations

- Watermark images uploaded by users are validated for MIME type and stored outside the web root.
- On-download watermarking generates a temporary file in a secure temp directory; it is deleted after the response is sent.
- All API inputs are sanitized and validated; file paths are resolved through `\OCP\Files\IRootFolder` to prevent path traversal.
- Audit log access is restricted to Nextcloud admins.

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
