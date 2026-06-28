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

The app targets **Vue 3** with **`@nextcloud/vue` v9** (its Vue 3 line), so the
dependency tree resolves cleanly:

```bash
npm install
```

> **Note:** avoid `--legacy-peer-deps` — it skips auto-installing the peer
> dependencies that `@nextcloud/eslint-config` needs (e.g. `eslint-plugin-import`)
> and will break `npm run lint`.

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

## Docker (local test environment)

A [`docker-compose.yml`](docker-compose.yml) is provided to run the app against a
real Nextcloud 31 instance. It bind-mounts this repo into Nextcloud's
`custom_apps/`, so **build the app on the host first** — the container runs the
compiled output, not the sources.

```bash
# 1. Build on the host
composer install
npm install
npm run build

# 2. Start Nextcloud (SQLite, admin auto-provisioned)
docker compose up -d

# 3. Wait ~30–60s for first-run install, then enable the app
docker compose exec -u www-data nextcloud php occ app:enable files_watermark
```

Open <http://localhost:8080> and log in as **admin / admin**.

Then test:

- **Admin settings:** Settings → Administration → **Watermark**
- **On demand:** upload a PDF/JPEG/PNG/WEBP, open the file row `...` menu → **Apply Watermark**
- **Logs:** `docker compose logs -f nextcloud`

Iterating:

- **Frontend change:** re-run `npm run build` on the host and hard-refresh the browser (the mount is live; no restart needed).
- **PHP / routes / migration change:** `docker compose exec -u www-data nextcloud php occ app:disable files_watermark && docker compose exec -u www-data nextcloud php occ app:enable files_watermark`
- **Reset everything:** `docker compose down -v` (deletes the Nextcloud volume).

The compose file uses SQLite for zero-config single-container testing; a
PostgreSQL variant (closer to production, exercises the migration on a real
RDBMS) is documented inline at the bottom of the file.

### Testing with S3 storage (RustFS)

The app is storage-agnostic: it reads/writes file content through the Nextcloud
Files API (`getContent()` / `putContent()` / `newFile()`) and only touches the
local filesystem for short-lived temp copies. So watermarking works unchanged on
S3 — this stack lets you verify it.

**1. S3 as primary object storage** (every file lives on S3) — use the dedicated
[`docker-compose.s3.yml`](docker-compose.s3.yml), which runs Nextcloud + RustFS:

```bash
composer install && npm install && npm run build
docker compose -p fw_s3 -f docker-compose.s3.yml up -d
docker compose -p fw_s3 -f docker-compose.s3.yml exec -u www-data nextcloud php occ app:enable files_watermark
```

Open <http://localhost:8081> (admin / admin). Then verify:

- **On demand:** upload a PDF/image → `...` menu → **Apply Watermark**.
- **On download:** `GET /apps/files_watermark/api/v1/download?path=/<file>` returns a
  watermarked copy while the original S3 object is untouched.
- **On upload:** set the global trigger to *On upload* in admin settings, then upload
  a file and confirm it comes back watermarked.
- Cross-check in the RustFS console (<http://localhost:9001>, rustfsadmin / rustfsadmin)
  that objects are written to the `nextcloud` bucket.

Tear down: `docker compose -p fw_s3 -f docker-compose.s3.yml down -v`.

**2. External S3 storage mount** (S3 mounted as a folder on an otherwise-local
instance) — on the default stack, point an external mount at the same RustFS:

```bash
docker compose exec -u www-data nextcloud php occ app:enable files_external
docker compose exec -u www-data nextcloud php occ files_external:create \
  /s3mount amazons3 amazons3::accesskey \
  -c bucket=externalbucket -c hostname=rustfs -c port=9000 -c use_ssl=false \
  -c use_path_style=true -c region=us-east-1 \
  -c key=rustfsadmin -c secret=rustfsadmin
```

Then watermark a file inside the `/s3mount` folder via the file action and confirm it
succeeds (the same RustFS from the S3 stack can be reused, or add a RustFS service to
the default stack).

## License

AGPL-3.0-or-later
