# files_watermark

A Nextcloud 31 app that applies configurable watermarks to PDF and image files. Watermarks embed user identity information (display name, account name, date, email) to deter unauthorized distribution and provide traceability.

## Features

- **Text watermarks** with customizable templates — `{displayname}`, `{username}`, `{email}`,
  `{date}`, `{datetime}`, `{filename}`
  - `{displayname}` is the name shown in Nextcloud (*John Doe*); `{username}` is the account
    name used to sign in (*john.doe*). Display names are neither unique nor permanent, so use
    the account name when the watermark has to identify exactly one account
- **Image watermarks** — overlay a logo or image on files
- **Combined** text + image watermarks
- Diagonal tiled placement at 45° rotation and 80% opacity by default
- Three trigger modes: **on download**, **on demand** (file action menu), **on share**
- Global policy configurable by admins under **Settings → Additional → Watermark Settings**
- Full audit log of every watermark event
- Supports PDF, JPEG, PNG, and WEBP files
- PDF rendering via tc-lib-pdf, which reads PDF 1.5+ documents natively; image rendering
  via GD by default, with Imagick used for anything GD cannot decode

## Requirements

| Dependency | Version |
| --- | --- |
| Nextcloud | 31.x |
| PHP | 8.2 or 8.3 |
| PHP extension | `gd` (used by default); `imagick` optional, see below |
| PHP extension | `bcmath` (required by the PDF renderer) |
| Composer | 2.x |
| Node.js | >= 20 |
| npm | >= 10 |

### Image rendering: GD by default, Imagick where GD cannot go

JPEG, PNG and WEBP are watermarked with **GD**, which ships with essentially every PHP
build and which Nextcloud server already requires. **Imagick is used when GD cannot decode
the file** — in practice that means WebP on a GD compiled without libwebp — and when GD is
not installed at all. Nothing to configure either way.

The preference used to run the other way round, and it was flipped for the same reason the
app spawns no external processes: two servers running the same configuration should produce
the same file. Imagick is optional on every platform and EPEL-only on RHEL 9, so preferring
it meant the output depended on how the host happened to be packaged.

One thing to be aware of if you run a minimal container: with **no TrueType font installed**,
GD falls back to its built-in bitmap font, which cannot be rotated — the watermark still
appears, but flat and coarse rather than tiled diagonally. Any of `dejavu-sans-fonts`,
`liberation-sans-fonts` (RHEL 9) or `fonts-dejavu-core` (Debian/Ubuntu) is enough.

### No external binaries

The app runs entirely inside PHP. It spawns no processes — no `exec()`, no shelling out
to `qpdf`, `pdftoppm`, Ghostscript or anything else — so there is nothing to install
beyond the PHP extensions above, and nothing that behaves differently because a host is
missing a package.

Two consequences worth knowing:

- **PDF 1.5+ documents with a compressed cross-reference table are watermarked normally**,
  with no configuration. Most modern producers emit these, including whatever wrote two of
  the three sample PDFs Nextcloud places in every new account. The renderer reads them
  natively.
- **Encrypted PDFs are skipped.** The renderer declines every encrypted document, and that
  includes files "encrypted" with an empty password purely to set permission flags, which
  are not really protected and open without prompting. Such a file is left exactly as it
  was, an entry is written to the audit log, and an on-demand apply returns an error naming
  it. Nothing is corrupted and no unwatermarked copy is served in place of a watermarked
  one — the watermark simply does not get applied.

The watermark is a real content stream, so **the text layer survives**: selection, copy,
search and screen-reader access all keep working. The user's file is never modified.

### Fonts

`resources/fonts` holds Helvetica *metrics* — no glyphs are embedded, since Helvetica is
one of the PDF standard 14 fonts that readers supply themselves. They are committed
because the renderer does not ship them via Composer, and without them any text call
fails. See the README there before moving or deleting anything in that directory; the
path reaches the renderer through the global `K_PATH_FONTS`.

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
├── resources/
│   └── fonts/        # Helvetica metrics for the PDF renderer (see the README there)
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
