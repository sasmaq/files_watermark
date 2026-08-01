# End-to-end suite

Drives a real Nextcloud 31 with the app enabled, and judges every scenario by the
**bytes that come back** — not by a spinner stopping or a toast appearing.

## Running it

The app runs compiled, off the bind mount in `docker-compose.yml`, so both builds
happen on the host first:

```sh
composer install
npm install
npm run build

docker compose up -d
# wait for the first-run install (~30-60s), then:
docker compose exec -u www-data nextcloud php occ app:enable files_watermark

npm run test:e2e        # headless
npm run test:e2e:open   # interactive
```

`NC_URL`, `NC_ADMIN` and `NC_ADMIN_PASSWORD` override the target (`http://localhost:8080`,
`admin`, `admin`).

Re-running is safe: every spec recreates its own folder, users and shares, and puts
the server-wide policy back to `on_demand` when it finishes.

**After changing PHP, give opcache a moment.** The container runs with
`opcache.revalidate_freq=60`, so a source change can take up to a minute to take
effect — a suite re-run inside that window is testing the code you just replaced.
`docker compose restart nextcloud` settles it immediately. (Frontend changes need
`npm run build`; the mount is live, so no restart.)

## What the specs cover

| Spec | Covers |
| --- | --- |
| `01-on-demand` | apply in place, the badge, the already-watermarked skip, byte-identical restore, the audit trail |
| `02-on-upload` | plain PUT and chunked PUT+MOVE, both asserted **before cron could have run** |
| `03-on-download` | per-fetch rendering for the owner, stored bytes untouched, `/api/v1/download` |
| `04-on-share` | recipient vs owner, public DAV, the share page's download link, preview blocking |
| `05-archives` | folder and multi-select ZIPs on both DAV servers, unsupported members, the received single-file share |
| `06-archive-caps` | over-cap `on_share` denies, over-cap `on_download` degrades |
| `07-arabic` | shaping and the lam-alef ligature, read off the delivered PDF |
| `08-images` | ink on the canvas, size preserved, JPEG round trip |
| `09-admin-settings` | the settings page mounts, persists, and previews what it saves |
| `10-files-app` | the file actions, their mirroring, and the row badge |

## How it is put together

**Two transports, and the split matters.** `cy.request` serialises a body as UTF-8, so
anything carrying file bytes goes through a Node task instead (`tasks/http.js`) — a
PDF uploaded through `cy.request` arrives corrupt, and the assertion that comes back
is about the corruption. The app's own `/api/v1/*` endpoints go the other way, through
the browser session: none of them is `#[NoCSRFRequired]`, so a basic-auth call to one
gets HTTP 412.

**Evidence, per file type** (`tasks/pdf.js`, `tasks/image.js`, `tasks/zip.js`):

- **PDF** — the app draws every watermark with a subsetted IBM Plex Sans Arabic, so
  `/BaseFont /XXXXXX+IBMPlexSansArabic` in the delivered bytes *is* the watermark.
  The face is written with two-byte code units, so the operand of a text-showing
  operator is the shaped string, which is what makes the Arabic assertions possible.
- **Images** — fixtures are a flat white field and `inkRatio` is the fraction of
  pixels that are no longer that colour. A clean control upload has to measure zero.
- **Archives** — unpacked in Node (ZIP64 included, since `\OC\Streamer` writes it) and
  probed member by member. The container-gate bug produced a valid archive of clean
  originals; nothing less would see it.

"The file changed" is never the assertion. A watermarked file differs from its source
in a hundred ways that have nothing to do with a watermark being drawn.

## Not covered here

- **Office documents** — no renderer exists yet.
- **S3 primary storage** — needs `docker-compose.s3.yml`; the suite is storage-agnostic
  and would run against it unchanged, but nothing wires that up in CI.
- **The bidi bug** — `07-arabic.cy.js` carries a pending test for Latin word order
  inside an RTL watermark. Asserting today's output would cement the bug; deleting
  `.skip` is the whole change once the shaper is fixed.
