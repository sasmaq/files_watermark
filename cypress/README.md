# End-to-end suite

Drives a real Nextcloud 31 with the app enabled, and judges every scenario by the
**bytes that come back** - not by a spinner stopping or a toast appearing.

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

# Core allows 20 shares per 10 minutes per user. This suite rebuilds its users, folders
# and shares on every run, so two runs inside that window exhaust the budget and the
# share specs fail in their setup with an empty HTTP 429. Turn the limiter off - on a
# throwaway test instance only:
docker compose exec -u www-data nextcloud php occ \
  config:system:set ratelimit.protection.enabled --value false --type boolean
# It stops new attempts being *recorded*; entries already counted still expire on their
# own, so if you set it after being throttled, the backlog takes up to 10 minutes to clear.

npm run test:e2e        # headless
npm run test:e2e:open   # interactive
```

If the local Cypress binary will not start - on macOS 26 it fails `cypress verify` with
`bad option: --no-sandbox` - the runner can come from the official image instead:

```sh
docker compose exec -u www-data nextcloud php occ \
  config:system:set trusted_domains 1 --value=host.docker.internal

docker run --rm -v "$PWD":/e2e -w /e2e \
  --add-host=host.docker.internal:host-gateway \
  -e NC_URL=http://host.docker.internal:8080 \
  cypress/included:15.19.0
```

The trusted domain is required: the container reaches the instance by a different host
name, and Nextcloud answers an untrusted one with `400`. Two specs still fail that way -
`06-archive-caps` and `11-prune-log` shell out to `occ` through `docker compose`, which
does not exist inside the runner container. Everything else passes.

`NC_URL`, `NC_ADMIN` and `NC_ADMIN_PASSWORD` override the target (`http://localhost:8080`,
`admin`, `admin`). `NC_OCC` overrides how `occ` is invoked - it defaults to
`docker compose exec -T -u www-data nextcloud php occ`, so a run against a real host wants
something like `NC_OCC="sudo -u www-data php /var/www/nextcloud/occ"`.

Re-running is safe: every spec recreates its own folder, users and shares, and puts
the server-wide policy back to `on_demand` when it finishes.

**After changing PHP, give opcache a moment.** The container runs with
`opcache.revalidate_freq=60`, so a source change can take up to a minute to take
effect - a suite re-run inside that window is testing the code you just replaced.
`docker compose restart nextcloud` settles it immediately. (Frontend changes need
`npm run build`; the mount is live, so no restart.)

## What the specs cover

| Spec | Covers |
| --- | --- |
| `01-on-demand` | marking, the badge, the already-marked no-op, **the stored file never changing**, the audit trail |
| `02-on-upload` | plain PUT and chunked PUT+MOVE, the overwrite that keeps its mark |
| `03-per-reader` | two readers of one file get two different documents and two different previews; public DAV, the share page's download link, the preview cache |
| `05-archives` | folder and multi-select ZIPs on both DAV servers, unsupported members, the received single-file share |
| `06-archive-caps` | an over-cap archive is denied for every reader, an unmarked one is left to core, and a cap set with a real `occ` command |
| `07-arabic` | shaping and the lam-alef ligature, read off the delivered PDF |
| `08-images` | ink on the canvas, size preserved, JPEG round trip |
| `09-admin-settings` | the settings page mounts, persists, and previews what it saves |
| `10-files-app` | the file actions, their mirroring, and the row badge |
| `11-prune-log` | the retention command, which can now reach every row |
| `12-trigger-matrix` | both triggers × all six access paths, in one table |

`03-on-download` and `04-on-share` are gone with the triggers they were named after. What
they covered is not: per-fetch rendering, the public DAV server, the share page's download
link and the preview path all moved into `03-per-reader`, where they are asserted against
two readers rather than one.

## How it is put together

**Two transports, and the split matters.** `cy.request` serialises a body as UTF-8, so
anything carrying file bytes goes through a Node task instead (`tasks/http.js`) - a
PDF uploaded through `cy.request` arrives corrupt, and the assertion that comes back
is about the corruption. The app's own `/api/v1/*` endpoints go the other way, through
the browser session: none of them is `#[NoCSRFRequired]`, so a basic-auth call to one
gets HTTP 412.

**Evidence, per file type** (`tasks/pdf.js`, `tasks/image.js`, `tasks/zip.js`):

- **PDF** - the app draws every watermark with a subsetted IBM Plex Sans Arabic, so
  `/BaseFont /XXXXXX+IBMPlexSansArabic` in the delivered bytes *is* the watermark.
  The face is written with two-byte code units, so the operand of a text-showing
  operator is the shaped string, which is what makes the Arabic assertions possible.
- **Images** - fixtures are a flat white field and `inkRatio` is the fraction of
  pixels that are no longer that colour. A clean control upload has to measure zero.
- **Archives** - unpacked in Node (ZIP64 included, since `\OC\Streamer` writes it) and
  probed member by member. The container-gate bug produced a valid archive of clean
  originals; nothing less would see it.

"The file changed" is never the assertion. A watermarked file differs from its source
in a hundred ways that have nothing to do with a watermark being drawn.

## Not covered here

- **Office documents** - no renderer exists yet.
- **S3 primary storage** - needs `docker-compose.s3.yml`; the suite is storage-agnostic
  and would run against it unchanged, but nothing wires that up in CI.
- **The bidi bug** - `07-arabic.cy.js` carries a pending test for Latin word order
  inside an RTL watermark. Asserting today's output would cement the bug; deleting
  `.skip` is the whole change once the shaper is fixed.
