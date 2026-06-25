# Software Development Document

## files_watermark — Nextcloud 31 File Watermarking App

**Version:** 1.0.0  
**Date:** 2026-06-25  
**Status:** Draft

---

## 1. Overview

`files_watermark` is a Nextcloud 31 application that enables administrators and users to apply configurable watermarks to files stored in Nextcloud. Watermarks can be embedded on-demand dynamically at download/preview time, protecting documents from unauthorized distribution and providing traceability.

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
| `AppController` | REST API endpoints for watermark configuration and on-demand watermarking |
| `WatermarkService` | Core watermark application logic; delegates to format-specific renderers |
| `PdfWatermarker` | Applies text/image overlays to PDF files using a PHP PDF library |
| `ImageWatermarker` | Applies watermarks to JPEG, PNG, WEBP via GD/Imagick |
| `EventListener` | Hooks into `NodeWrittenEvent` / `BeforeNodeReadEvent` to auto-watermark |
| `SettingsController` | Admin settings panel backend |
| `WatermarkConfigMapper` | ORM mapper for persisting watermark templates in the database |
| `Vue.js frontend` | Admin panel + file-action menu integration |

---

## 5. Key Features

### 5.1 Watermark Types

- **Text watermark**
  - default string (`{username}` + `{datetime}`)
  - custom string (supports placeholders: `{username}`, `{email}`, `{date}`, `{filename}`)

- **Image watermark**
  - upload a logo/image to overlay on files

- **Combined**
  - text + image simultaneously

### 5.2 Placement and Style (Text)

- Position: repeated diagonal (tiled)
- Rotation angle at 45 degree
- Font size, font color (hex), opacity at 80%

### 5.3 Trigger Modes

- **On download** — watermark applied to a temporary copy served to the downloader; original file untouched

- **On demand** — user or admin triggers watermarking via the file action menu
- **On share** — watermark applied when a public link or internal share is created

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
| `type` | ENUM('text','image','combined') | Watermark type |
| `text_template` | TEXT | Text with placeholders |
| `image_path` | VARCHAR(512) | Nextcloud path to watermark image |
| `position` | VARCHAR(32) | Placement identifier |
| `opacity` | TINYINT | 0–100 |
| `font_size` | SMALLINT | pt |
| `color` | CHAR(7) | Hex color |
| `rotation` | SMALLINT | Degrees |
| `trigger` | SET(...) | Comma-separated trigger modes |
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
| `GET` | `/apps/files_watermark/api/v1/log` | Retrieve audit log (admin only) |

All endpoints require a valid Nextcloud session or app password. Admin-only endpoints enforce the `OC_Group::isInGroup($user, 'admin')` check.

---

## 8. Frontend (Vue.js)

- **Admin Settings** (`/settings/admin/watermark`) — global policy, group overrides, default template, audit log viewer.
- **File Action** — context menu entry "Apply Watermark" on individual files or a selection; shows a preview modal before committing.

Built with **Vue 3 + Composition API**, using **@nextcloud/vue** component library and **@nextcloud/axios** for API calls, consistent with Nextcloud 31 app standards.

---

## 9. Dependencies

| Dependency | Purpose |
| --- | --- |
| `setasign/fpdi` (PHP) | PDF page import and overlay rendering |
| `tecnickcom/tcpdf` (PHP) | PDF text/image watermark writing |
| PHP `GD` extension | Image watermarking fallback |
| PHP `Imagick` extension | Preferred image watermarking (better quality) |
| `@nextcloud/vue` | Nextcloud UI component library |
| `@nextcloud/axios` | Authenticated HTTP client for Vue frontend |

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
