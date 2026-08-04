# files_watermark

A Nextcloud 31 app that applies configurable watermarks to PDF and image files. Watermarks embed user identity information (display name, account name, date, email) to deter unauthorized distribution and provide traceability.

## Features

- **Text watermarks** with customizable templates - `{displayname}`, `{username}`, `{email}`,
  `{date}`, `{datetime}`, `{filename}`
  - `{displayname}` is the name shown in Nextcloud (*John Doe*); `{username}` is the account
    name used to sign in (*john.doe*). Display names are neither unique nor permanent, so use
    the account name when the watermark has to identify exactly one account
- **Image watermarks** - overlay a logo or image on files
- **Combined** text + image watermarks
- Diagonal tiled placement at 45° rotation, mid grey (`#808080`) and 40% opacity by default
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
the file** - in practice that means WebP on a GD compiled without libwebp - and when GD is
not installed at all. Nothing to configure either way.

The preference used to run the other way round, and it was flipped for the same reason the
app spawns no external processes: two servers running the same configuration should produce
the same file. Imagick is optional on every platform and EPEL-only on RHEL 9, so preferring
it meant the output depended on how the host happened to be packaged.

**No host fonts are needed.** Both engines draw with the font bundled in `resources/fonts`,
so a minimal container renders exactly what a full one does. This used to walk a list of
system fonts and fall back to GD's built-in bitmap font when it found none.

### No external binaries

The app runs entirely inside PHP. It spawns no processes - no `exec()`, no shelling out
to `qpdf`, `pdftoppm`, Ghostscript or anything else - so there is nothing to install
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
  one - the watermark simply does not get applied.

The watermark is a real content stream, so **the text layer survives**: selection, copy,
search and screen-reader access all keep working. The user's file is never modified.

### Fonts and Arabic text

**One font draws every watermark** - IBM Plex Sans Arabic Bold (SIL Open Font License),
committed in `resources/fonts`. Latin and Arabic render in the same face, so a watermark
looks the same whatever the text contains.

**Arabic is shaped and reordered** before it is drawn: letters take their contextual forms,
lam-alef becomes a single ligature, and the text runs right to left. That happens for PDFs
and images alike, in PHP rather than in the image backend, so output does not depend on
whether the host's ImageMagick was built with Raqm/HarfBuzz - or on which fonts the host
has installed at all.

The font is embedded **subsetted**: only the glyphs actually drawn. A watermarked PDF is
about 31 KB rather than the 125 KB a whole embedded face would cost, and `/ToUnicode` is
still written so the watermark stays searchable and selectable.

If the bundled font cannot be read, the file is **skipped with an audit-log entry** rather
than watermarked with substitute glyphs - an unreadable watermark is worse than a missing
one, because it looks like it worked.

See `resources/fonts/README.md` for why this face and not another (the constraint is Arabic
Presentation Forms-B coverage, which rules out most modern Arabic fonts including Cairo),
and before moving anything in that directory - the path reaches the renderer through the
global `K_PATH_FONTS`.

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
│   └── fonts/        # The bundled watermark font (see the README there)
├── migration/        # Database schema migration
├── src/              # Vue 3 frontend source
│   ├── components/   # AdminSettings.vue, WatermarkModal.vue
│   ├── adminSettings.js
│   └── fileAction.js
├── js/               # Compiled frontend assets (generated)
├── templates/        # PHP templates
├── tests/            # PHPUnit suites (Unit/, plus the DAV stubs)
├── cypress/          # End-to-end suite (see cypress/README.md)
│   ├── e2e/          # One spec per trigger / surface
│   ├── support/      # Login, policy, upload/download commands
│   └── tasks/        # Node side: binary-safe HTTP, PDF/image/zip probes
└── doc/
    ├── sdd.md          # Software Development Document
    ├── tasks.md        # what is left to do - the checklist
    ├── development.md  # the engineering record: why each piece is the way it is
    └── patch.md        # optional Nextcloud core patches, and what they cost
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

> **Note:** avoid `--legacy-peer-deps` - it skips auto-installing the peer
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
npm run lint            # ESLint, for the Vue frontend
composer lint           # php -l over every PHP file
composer cs:check       # Nextcloud coding standard (composer cs:fix applies it)
composer psalm          # Static analysis of lib/
```

`composer psalm` type-checks `lib/` against core's public API - the `nextcloud/ocp`
package supplies the typed OCP interfaces, and `tests/stubs/CoreStubs.php` the server
classes that are not installable from packagist (`OCA\DAV\Connector\Sabre\*`,
`OC\Streamer`, the two events). It is clean with no baseline; the configuration, and
what it deliberately does not check, is commented in [`psalm.xml`](psalm.xml).

### Tests

```bash
vendor/bin/phpunit      # PHP unit tests
npm test                # Jest, for the Vue components and the Files-app integration
npm run test:e2e        # Cypress, against a running instance (see below)
```

The end-to-end suite drives the Docker instance described under
[Docker (local test environment)](#docker-local-test-environment) - start it and enable the app
first, then `npm run test:e2e`. It judges each scenario on the delivered file's bytes rather
than on the UI: what it covers, and how it tells a watermarked file from a clean one, is in
[`cypress/README.md`](cypress/README.md).

## The activity log

Every watermark **applied to or removed from a file** is recorded, always. Those entries are
not only history: they are how the Files list knows to show the "watermarked" badge, and how
the app avoids stamping a file twice.

**Downloads are not recorded unless you ask for them.** `on_download` and `on_share` render a
watermarked copy on *every fetch*, so recording them writes one entry per file per download -
including every file inside a downloaded folder - and nothing expires on its own. Turn it on
under **Settings → Administration → Watermark → When to apply** with *"Record every download
in the activity log"*; the option appears only for those two triggers, since it does nothing
for the others.

### Pruning old entries

```bash
occ files_watermark:prune-log                  # older than 90 days
occ files_watermark:prune-log --days 30        # older than 30 days
occ files_watermark:prune-log --all            # every download entry, any age
occ files_watermark:prune-log --all --dry-run  # what would go, and nothing else
```

It removes **download entries only** - there is no option to take the apply/remove entries,
because those are what the watermarked badge is drawn from. Retention shortens the history of
who downloaded what; it never makes the app forget that a file is already watermarked.

## Server settings (`occ`)

The watermark itself is configured in the admin UI. These are host tuning rather than
policy - they bound what one request may cost, and they change nothing about the watermark
that comes out, so they live in the app config instead of on the form:

| Key | Default | What it bounds |
| --- | --- | --- |
| `archive_max_members` | `200` | Files rendered for a single folder / multi-select download |
| `archive_max_bytes` | `268435456` (256 MiB) | Source bytes rendered for one such download |
| `apply_max_bytes` | `67108864` (64 MiB) | Size of a single file accepted for an on-demand apply |
| `image_max_pixels` | `40000000` (40 MP) | Decoded size of an image, on every trigger |

```bash
occ config:app:set files_watermark archive_max_members --value 500
occ config:app:set files_watermark archive_max_bytes   --value 1073741824
occ config:app:set files_watermark apply_max_bytes     --value 134217728
occ config:app:set files_watermark image_max_pixels    --value 80000000
occ config:app:delete files_watermark archive_max_members   # back to the default
```

An archive is rendered member by member **before** any bytes are sent - that is what lets a
share that must be watermarked fail with a clean 403 instead of a truncated download - so
these bound the temp disk and CPU one request can use. Past the cap, `on_share` denies and
`on_download` falls back to a plain unwatermarked archive.

Raise them if large folder downloads are being denied or served unwatermarked and the server
has the temp space; lower them on a small host. There is no unlimited setting: a value below
`1` is refused and the default used, with a warning in the log.

`apply_max_bytes` bounds something different, and the difference is why its default is so
much smaller. An on-demand apply renders **synchronously inside the request**, so what it
spends is a PHP worker's memory rather than temp disk - and a render holds several times
the file's own size at peak, against a `memory_limit` that is 512M on a stock Nextcloud.
Files over the cap are refused with a 413 naming both their size and the limit, before any
content is read. **Raise it and PHP's `memory_limit` together, or not at all**: on its own
it only moves the failure from a clean 413 that costs nothing to a fatal error part-way
through the render, which takes the worker down and tells the user nothing useful.

`image_max_pixels` bounds something `apply_max_bytes` cannot see. Both GD and Imagick work
on an uncompressed bitmap at roughly **4 bytes per pixel**, and the ratio between a file's
size on disk and its decoded size is unbounded - a PNG of one flat colour is kilobytes on
disk and gigabytes in memory. The default of 40 MP is about 160 MB decoded, and is set
above ordinary photography rather than below it: a 24 MP camera frame and a full 8K image
both pass, while the 50 MP-and-up end does not. Dimensions are read from the file header,
so an image over the limit is refused without ever being decoded.

Unlike the byte cap, this one applies on **every** trigger, not just the file action -
`on_download` and `on_share` decode the same image, so a guard only on the on-demand
endpoint would leave the problem reachable by downloading the file instead. On those paths
the refusal behaves like any other render failure: `on_download` serves the original
untouched and `on_share` denies.

On-demand applies and watermark removals are also rate limited to **20 per user per
minute** by Nextcloud's own middleware, which answers `429`. That is far above what the
file action can produce by hand - each apply needs its own confirmation - and far below
what a script can. It is not configurable.

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

- **On demand:** right-click any supported file in the Files app → **Apply Watermark** - overwrites the original
- **On download:** use the `/apps/files_watermark/download/{fileId}` endpoint to serve a watermarked copy without touching the original
- **On share:** when a share is created, a watermarked copy (`{name}_shared.{ext}`) is saved in the same folder
- **Admin settings:** configure the global policy under **Settings → Additional → Watermark Settings**

## Preserved originals and server-side encryption

An in-place watermark (`on_demand`, `on_upload`) burns into the stored bytes and cannot be
undone by re-rendering, so before overwriting a file the app keeps a copy of it. That copy
lives in the **owner's** storage, at `.files_watermark/originals/{fileId}`, and is written
through the Files API - which is what puts it under **server-side encryption**: with SSE
enabled it is encrypted by whichever module the admin selected, with the server's own keys.
The app neither holds a key nor knows which module is in use.

It has to be there to get that. The selected module decides what is encrypted, and the
default module encrypts only what lives under a user's `files`, `files_versions` and
`files_trashbin` - app storage (appdata) is outside its remit, which is where these copies
used to sit, in the clear, beside the ciphertext of the very same bytes.

The folder is **hidden from clients**: it is dropped from every WebDAV listing, its paths
answer 404 to every method, and sharing one of the copies is refused. So users do not see
it in the web UI, in the desktop or mobile clients, or over WebDAV, and cannot delete it by
accident. `doc/patch.md` documents how that works and what it deliberately does not cover.

What still costs something, and what to tell users:

- copies **count against the owner's quota**. A full quota means the copy is not written:
  the watermark still applies, and **Remove Watermark** then reports honestly that it
  cannot be undone
- the folder's *name* still appears in unified search results and the activity feed. Both
  need a small Nextcloud core patch, which is written out in `doc/patch.md` and left to the
  admin - they leak the name, never the contents
- `occ` and server-side tooling still see the folder, as they must: that is how a restore
  reads the copy back
- the app keeps its own triggers off these copies - they are never watermarked in place,
  and never watermarked on delivery

Copies written by earlier versions remain in appdata and are still restorable; nothing
migrates them, and new copies always go to the owner.

## Docker (local test environment)

A [`docker-compose.yml`](docker-compose.yml) is provided to run the app against a
real Nextcloud 31 instance. It bind-mounts this repo into Nextcloud's
`custom_apps/`, so **build the app on the host first** - the container runs the
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
S3 - this stack lets you verify it.

**1. S3 as primary object storage** (every file lives on S3) - use the dedicated
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
instance) - on the default stack, point an external mount at the same RustFS:

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
