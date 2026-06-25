# files_watermark

A Nextcloud 31 app that applies configurable watermarks to PDF and image files. Watermarks embed user identity information (username, date, email) to deter unauthorized distribution and provide traceability.

## Features

- **Text watermarks** with customizable templates — `{username}`, `{email}`, `{date}`, `{filename}`
- **Image watermarks** — overlay a logo or image on files
- **Combined** text + image watermarks
- Diagonal tiled placement at 45° rotation and 80% opacity by default
- Three trigger modes: **on download**, **on demand** (file action menu), **on share**
- Global policy configurable by admins under **Settings → Additional → Watermark Settings**
- Full audit log of every watermark event
- Supports PDF, JPEG, PNG, and WEBP files
- PDF rendering via FPDI + TCPDF; image rendering via Imagick (preferred) or GD fallback

## Requirements

| Dependency | Version |
| --- | --- |
| Nextcloud | 31.x |
| PHP | 8.2 or 8.3 |
| PHP extension | `imagick` (preferred) or `gd` |
| Composer | 2.x |
| Node.js | >= 20 |
| npm | >= 10 |

## Project Structure

```text
files_watermark/
├── appinfo/          # App metadata and route definitions
├── lib/
│   ├── AppInfo/      # Bootstrap and event listener registration
│   ├── Controller/   # REST API controller
│   ├── Db/           # Entities and QBMapper classes
│   ├── Listener/     # ShareCreatedEvent listener
│   ├── Service/      # WatermarkService, PdfWatermarker, ImageWatermarker
│   └── Settings/     # Admin settings panel registration
├── migration/        # Database schema migration
├── src/              # Vue 3 frontend source
│   ├── components/   # AdminSettings.vue, WatermarkModal.vue
│   ├── adminSettings.js
│   └── fileAction.js
├── js/               # Compiled frontend assets (generated)
├── templates/        # PHP templates
└── doc/
    └── sdd.md        # Software Development Document
```

## Installation

### 1. PHP dependencies

```bash
composer install
```

### 2. Frontend dependencies

> **Note:** Some Nextcloud packages declare `vue@^2` as a peer dependency while working correctly with Vue 3. Use `--legacy-peer-deps` to bypass the conflict:

```bash
npm install --legacy-peer-deps
```

### 3. Build frontend assets

```bash
npm run build
```

### 4. Enable the app in Nextcloud

```bash
occ app:enable files_watermark
```

Or via the web UI: **Admin → Apps → search "files_watermark" → Enable**.

## Development

Watch mode (rebuilds on file changes):

```bash
npm run watch
```

Development build (with source maps):

```bash
npm run dev
```

Lint:

```bash
npm run lint
```

## API Endpoints

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/apps/files_watermark/api/v1/config` | Get watermark config(s) |
| `POST` | `/apps/files_watermark/api/v1/config` | Create or update a config |
| `DELETE` | `/apps/files_watermark/api/v1/config/{id}` | Delete a config |
| `POST` | `/apps/files_watermark/api/v1/apply` | Apply watermark to a file on demand |
| `GET` | `/apps/files_watermark/api/v1/log` | Retrieve audit log (admin only) |
| `GET` | `/apps/files_watermark/download/{fileId}` | Download a watermarked copy |

## Usage

- **On demand:** right-click any supported file in the Files app → **Apply Watermark** — overwrites the original
- **On download:** use the `/apps/files_watermark/download/{fileId}` endpoint to serve a watermarked copy without touching the original
- **On share:** when a share is created, a watermarked copy (`{name}_shared.{ext}`) is saved in the same folder
- **Admin settings:** configure the global policy under **Settings → Additional → Watermark Settings**

## Docker

```bash
docker run -d -p 8080:80 nextcloud:31.0.14-apache

docker cp ./ 7c31d03efd61:/var/www/html/apps/files_watermark
```

## License

AGPL-3.0-or-later
