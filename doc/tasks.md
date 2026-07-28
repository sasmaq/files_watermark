# files_watermark — Task List

Derived from [sdd.md](sdd.md), and reorganized around **what is left** rather than around the
SDD's chapter order. Each area states its position in one line, lists its open items, then
records what was delivered and why it was built that way.

How to read it:

- `- [ ]` is outstanding, `- [x]` is done and verified.
- Indented notes under an item are the reasoning, the trade-off, or the bug that forced the
  design. They are the part worth reading twice; the checkboxes are just an index.
- A claim marked done means it was *observed* working — by a test, or by driving a real
  instance. Where the evidence is manual, it says so.

Verified against **Nextcloud 31.0.14.1**, app version **1.1.0**, PHP 8.2 + 8.3.
Suites re-run 2026-07-28, both green: **226 PHPUnit** tests (562 assertions) and **77 Jest**
tests. Local run was PHP 8.2; 8.3 is covered by CI only.

The PHPUnit skips are all binary-dependent and depend on the host: 15 on a bare macOS box
(9 `PdfFlattenerTest` rasterise cases with no `pdftoppm`, 6 `qpdf` cases), 10 in the CI image
(which has `qpdf` but no `pdftoppm`). Every one of them runs somewhere — the `qpdf` cases were
driven green against qpdf 12.2.0 in `ci/php.Dockerfile`.

---

## Where this stands

| Area | Position | Open |
| --- | --- | --- |
| [1. Renderers](#1-renderers-goal-1) | PDF and images complete, including tamper-resistant flattening and PDF 1.5+ via a `qpdf` pre-pass. Office not started | Office pipeline, password-protected PDFs |
| [2. Watermark content](#2-watermark-content-goal-2) | Visible watermarks complete | Invisible metadata watermark |
| [3. Delivery and triggers](#3-delivery-and-triggers-goal-3) | All four triggers work, single-file and archive, on every access path | Config-driven caps, tar (core bug) |
| [4. Admin UI and file actions](#4-admin-ui-and-file-actions-goal-4) | Settings, audit log, apply/remove actions and the watermarked badge all done | Group overrides |
| [5. Storage backends](#5-storage-backends-goal-5) | S3 verified end to end; no S3-specific code needed | — |
| [PDF stack migration](#pdf-stack-migration-to-tc-lib-pdf) | Decided, not started. FPDI + TCPDF out, tc-lib-pdf in | The whole of it |
| [Data model](#data-model) | Schema carries every implemented feature | `metadata` type, cross-DB run, two dead columns |
| [Environment](#environment-and-dependencies) | PHP, Imagick/GD, poppler and qpdf all wired | LibreOffice, `exif` |
| [Security](#security) | Two real vulnerabilities found and fixed | Rate limiting, legacy `image_path` cleanup, FPDI licence |
| [Testing](#testing) | 226 PHPUnit + 77 Jest; the DAV layer is no longer a blind spot | Cypress E2E, the full trigger matrix, static analysis |
| [Docs and release](#docs-and-release) | README covers install, Docker, S3 and flattening | API reference, changelog, packaging |

The three things standing between this and a 1.0 release are **Office support**, the
**Cypress E2E suite**, and **release packaging**. Everything else open is a refinement,
except the [PDF stack migration](#pdf-stack-migration-to-tc-lib-pdf), which is a decided
rewrite of working code and should be scheduled against those three rather than squeezed
alongside them.

---

## What is left

Ordered by what would hurt most to ship without. Each links to the detail below.

### Feature gaps — whole SDD goals not yet built

- [ ] [Office documents](#office-documents-officewatermarker) — no renderer, no conversion
  pipeline, no MIME registration. The largest single piece of missing scope
- [ ] [Invisible metadata watermark](#open-2) — no
  service, and `metadata` is not an accepted config type
- [ ] [Group overrides](#open-4) — `group_id` is stored and validated but **never read**, so a
  group policy silently does nothing. Either implement resolution or drop the column

### Rewrites

- [ ] [Migrate the PDF stack to tc-lib-pdf](#pdf-stack-migration-to-tc-lib-pdf) — drop
  `setasign/fpdi` + `tecnickcom/tcpdf` for `tecnickcom/tc-lib-pdf` +
  `tecnickcom/tc-lib-pdf-parser`, which read compressed-xref PDFs in pure PHP. Eight steps,
  and it touches both renderers, the whole PDF test suite and the platform requirements

### Correctness and robustness

- [x] [PDF 1.5+ with compressed xref](#open-1) — **fixed** by the `qpdf` normalizer pre-pass,
  not just documented. Skipped only on hosts without the binary. The
  [tc-lib-pdf migration](#pdf-stack-migration-to-tc-lib-pdf) would close the same gap without
  the external binary
- [ ] [Flattening memory ceiling](#open-1) is unmeasured — the streaming claim rests on reading
  the code
- [ ] [Archive caps](#open-3) are class constants, not configuration
- [ ] [Legacy `image_path` rows](#open-security) still sit in the database looking valid
- [ ] [Rate limiting](#open-security) for on-demand applies on large files
- [ ] [Public file-drop uploads](#open-3) are watermarked by neither the inline path nor the job

### Test and CI gaps

- [ ] [Cypress E2E](#integration--e2e-cypress) — nothing automated end to end
- [ ] [The trigger × access matrix](#manual-verification-matrix) is only partly driven; every
  cell of it has historically caught a real bug
- [ ] [`ZipInterceptorPlugin::streamNode` drift](#open-testing) against core, re-checked by hand
  on every Nextcloud upgrade
- [ ] [Psalm / PHPStan](#open-testing) — nothing type-checks the DAV stubs against core

### Documentation and release

- [ ] [API reference, developer guide, changelog, packaging](#docs-and-release)

---

## 1. Renderers (Goal 1)

**Position:** PDFs and images are complete, including optional rasterised flattening for
tamper resistance. Office formats are not started.

### Open {#open-1}

- [x] **PDF 1.5+ with compressed xref — fixed, not merely documented.** `PdfNormalizer`
  rewrites the refused document with `qpdf --object-streams=disable --decrypt` and the existing
  FPDI/TCPDF pipeline watermarks the rewrite. Two of the three Nextcloud skeleton PDFs needed
  this; on a host with `qpdf` they now work
  - **a pre-pass, not a replacement.** The direct FPDI read is tried first and the rewrite only
    happens once FPDI has actually thrown, so files that already worked are never rewritten and
    pay nothing. Replacing the renderer with `qpdf --overlay` was the alternative and was not
    taken: it would have discarded the tile-geometry work below for a gain only unreadable
    files see
  - **the text layer survives**, which is the whole reason this beats routing these files
    through the flattener. The overlay is still a real content stream
  - **degrades rather than breaks.** No binary means `isAvailable()` is false, the original
    parse error is rethrown untouched, and the trigger's own skip-plus-audit policy takes over
    exactly as before — the behaviour the README documented, now the fallback rather than the
    rule
  - the fixture is **hand-built byte by byte**, because TCPDF cannot produce one: it writes a
    classic xref table whatever `setPDFVersion('1.5')` and `SetCompression(true)` are set to,
    and FPDI reads its output happily. Simplifying the helper into `createSourcePdf()` gives a
    fixture that no longer reproduces the bug and a test that passes for the wrong reason. It
    now lives in the `CompressedXrefFixture` trait, shared by both suites that assert on it —
    they pin opposite halves of one story, and a fixture that drifted between them would let
    both pass while the feature was broken
  - `testCompressedXrefPdfFailsCleanlyWithoutQpdf` asserts FPDI's own
    `CrossReferenceException::COMPRESSED_XREF` code, not just the `RuntimeException`. That is
    what separates it from the corrupt-PDF test: FPDI can only reach that code after parsing
    the trailer and finding a valid `/Type /XRef` stream, so it proves the fixture is a
    well-formed PDF 1.5 whose *compression* is unsupported, rather than bytes that fail to
    parse at all. It also proves the original cause survives the pre-pass rather than being
    replaced by a complaint about the missing binary
  - it **mocks the normalizer unavailable** rather than trusting the host, or the assertion
    would invert on a machine that has `qpdf` installed
  - mutation-tested: removing the try/catch in `PdfWatermarker::apply()` makes it fail.
    `CrossReferenceException` is **not** a `RuntimeException` subclass, so the wrapping is
    load-bearing rather than cosmetic
  - measured against the real skeleton files in `nextcloud:31.0.14-apache`: `Nextcloud
    Manual.pdf` (1.5) and `Reasons to use Nextcloud.pdf` (1.6) both failed, `Documents/Nextcloud
    flyer.pdf` (1.4) worked. The earlier note here said *every* skeleton PDF was affected,
    which was wrong — two of three
- [ ] **Password-protected PDFs are still refused**, and that is now the whole of the gap.
  `--decrypt` picks up empty-password permission flags for free, but a real user password is
  outside what any free parser reaches — see the licence item under [Security](#open-security)
- [ ] The skip is honest but **silent to the end user**: an on-demand apply reports the error,
  yet an `on_upload` or `on_share` file that cannot be watermarked is only visible in the audit
  log. Narrower now that `qpdf` handles the common case, but still worth surfacing in the UI
- [ ] Flattening's **memory ceiling is unmeasured**. The page-at-a-time loop and the 200-page /
  256 MiB caps exist and the caps are tested, but nothing asserts peak memory, so "streams
  page by page" is a claim about the code rather than an observation
- [ ] Confirm the RHEL 9 `poppler-utils` and `qpdf` package names and their `/usr/bin` paths on
  the real target build, and pin minimum versions if the DPI / page-range flags differ. `qpdf`
  was driven against **12.2.0** (Debian); the flags used are old enough to predate RHEL 9's
  version, but that is reasoning rather than an observation until someone checks
- [ ] If a second rasteriser is ever wanted, `pdftocairo` (same package) is the cheap one — not
  Imagick, for the reasons under [Environment](#environment-and-dependencies)

### PDF (`PdfWatermarker`)

Everything below is delivered against FPDI + TCPDF and is scheduled to be rewritten on
tc-lib-pdf — see [PDF stack migration](#pdf-stack-migration-to-tc-lib-pdf). Read the notes
here as the specification the rewrite has to keep satisfying, particularly the tile
geometry.

- [x] Text overlay tiled across every page of a multi-page document
- [x] Image / logo overlay
- [x] Encrypted and password-protected PDFs fail gracefully (throw + skip + log)
- [x] **Tile geometry rebuilt after the watermark turned out to be illegible.** Two separate
  faults, and the visible one was not the one the code blamed:
  - TCPDF reads a **negative** `SetX`/`SetY` as an offset from the *opposite* page edge —
    `SetXY(-361, -93.6)` on A4 lands at `(234, 748)`. Every tile deliberately placed off the
    top or left edge to cover the margins was therefore teleported into the middle of the page
    and stacked onto the tiles already there. That is the smear, and the bare top and left
    margins. Placement now goes through `Translate`, which has no such special case
  - spacing was derived from the text's *unrotated* width and height, so the pattern's density
    depended on the rotation angle. `tilePositions()` now builds the lattice in the text's own
    rotated frame — `textWidth + gap` along the reading direction, `lineHeight + gap` across it
  - the five existing tests all passed throughout, because every one of them asserted only
    that a valid *n*-page PDF came out. Rendering a page to an image and **looking at it** is
    now the minimum bar for believing anything about output geometry

### Flattened (rasterised) PDFs — `PdfFlattener`

Optional, off by default: after watermarking, every page is rendered to an image and the PDF
is rebuilt from those images, so the watermark is fused into the pixels.

**Why.** The ordinary overlay is a separate content stream sitting on top of the original page
objects — `qpdf` or `mutool` can drop it, and some editors let a user select and delete it.
Rasterising removes the seam. It makes removal *impractical, not impossible*: cropping,
inpainting and OCR-and-retypeset all still work. It raises cost; it is not a cryptographic
guarantee, and the form's help text says so rather than implying tamper-proofing.

Decisions taken when it was built:

| Question | Decision |
| --- | --- |
| Which triggers | **All of them**, flattening per fetch, no cache |
| Page image format | **PNG** — lossless glyph edges, at the cost of a larger file |
| Stranded setting with no renderer | **Server forces it off** and logs why |
| Invisible OCR text layer to soften the a11y loss | **Scoped out** — it would put the watermark text back within reach |

- [x] **Accessibility cost is disclosed, not hidden.** Rasterising deletes the text layer: no
  selection, copy, search, or screen-reader access. That is a possible WCAG / EN 301 549
  problem for a document-management product, so the setting is off by default and the form
  states the cost at the moment an admin switches it on
- [x] Rebuild leg is **TCPDF, not Ghostscript** — already a dependency, keeps the write path
  in-process, and reuses the page-geometry handling the app already had
- [x] Page → bitmap via **`pdftoppm` from poppler-utils**: in RHEL 9 AppStream, so no EPEL and
  no Ghostscript, and its `-r <dpi> -f N -l N -singlefile` invocation gives the
  page-at-a-time streaming the memory cap needs
- [x] Availability is a **runtime probe of the binary on PATH**, never a distro assumption —
  production is RHEL 9 while the dev containers are Debian. The probe only stats PATH, so it
  never shells out, and it is memoised per request
- [x] Missing binary ⇒ the setting is **absent from the form entirely** — not disabled, not a
  placeholder — and `saveConfig` rejects it however it arrives, because hiding a control is
  not an access check. The unavailability is logged, since a hidden control gives the admin
  no on-screen reason
- [x] Source page geometry carried through in points, so mixed-size and landscape documents
  survive the round-trip and nothing assumes A4
  - the reader must use the **same unit as the output document**, or every page is rebuilt at
    1/2.835 of its size. Caught by a test, not by inspection
- [x] Margins, header, footer and auto-page-break all zeroed before the image is placed, or
  TCPDF insets it and spills each source page onto two
- [x] Streams page by page: one bitmap in memory and on disk at a time, deleted before the
  next is rendered
- [x] Capped at 200 pages / 256 MiB, mirroring `ZipInterceptorPlugin`'s ceilings
- [x] **Fails closed.** A failed flatten throws rather than falling back to the unflattened
  file, because that file is precisely the removable-overlay version the setting exists to
  avoid handing out
- [x] Applied *after* the overlay, in `WatermarkService::renderToTemp` — the one choke point
  every trigger already funnels through, so the delivery paths got flattening without a code
  path of their own
- [x] **Remove Watermark still works.** Verified end to end: 7497 B uploaded → 289 037 B
  flattened in place → restored **byte-identical** to the upload, text layer and all

### Images (`ImageWatermarker`)

- [x] Text and image watermarks on JPEG, PNG, WEBP via Imagick
- [x] GD fallback produces equivalent output when Imagick is absent
- [x] Opacity and rotation match the configured values

### Office documents (`OfficeWatermarker`)

Not started. The largest piece of missing SDD scope.

- [ ] Implement `OfficeWatermarker` for docx, xlsx, pptx, odt, ods, odp
- [ ] Stand up a headless LibreOffice / Collabora conversion-rendering pipeline
- [ ] Add the Office MIME types to `WatermarkService::SUPPORTED_*`
- [ ] Handle conversion failure gracefully (skip + audit-log entry)
- [ ] Register the file action for Office MIME types in the Files app

### Service routing

- [x] `WatermarkService` delegates to the correct renderer per MIME type
- [x] Unsupported types are skipped with an audit-log entry

---

## 2. Watermark content (Goal 2)

**Position:** visible watermarks are complete. The invisible metadata watermark is not built.

### Open {#open-2}

- [ ] Implement `MetadataWatermarker`
- [ ] Embed traceability metadata (acting user, timestamp) into PDF / image / Office metadata
- [ ] Add `metadata` to `ApiController::VALID_TYPES` **and** to the `type` column's accepted
  values via a migration
- [ ] Support invisible metadata alongside *or* independently of a visible watermark
- [ ] Verify the embedded metadata survives the download path
  - **ordering matters:** flattening destroys embedded metadata along with everything else
    that is not pixels, so metadata must be re-embedded *after* any rasterise pass

### Delivered

- [x] Text watermark with `{username}`, `{email}`, `{date}`, `{datetime}`, `{filename}`
- [x] Image watermark (logo overlay), and combined text + image
- [x] Tiled diagonal placement, 45° default, configurable font size / colour / opacity /
  rotation

---

## 3. Delivery and triggers (Goal 3)

**Position:** all four triggers work, for single files and archives, across owner, internal
share, and public-link access. This is where every delivery-time bug has been found.

### Open {#open-3}

- [ ] **Tar archives are broken in core.** `Accept: application/x-tar` yields a truncated
  archive, and it reproduces identically on the untouched core path, so it is not caused by
  this app. Browsers request zip. Worth an upstream report
- [ ] Make `MAX_MEMBERS` / `MAX_BYTES` configurable rather than class constants
- [ ] Decide the audit granularity for archives: currently one `watermark_log` row per
  watermarked member. Confirm that is wanted for large archives, or batch it
- [ ] Extend `DownloadController` (`/api/v1/download`) to accept a folder path, or document
  that it stays single-file only (it currently answers "Path is not a file")
- [ ] **Public file-drop uploads** have no session to attribute a watermark to, so neither the
  inline path nor the job covers them. Not a confidentiality leak — the dropper is
  watermarking their own upload — but on-upload does not cover them. The open decision is
  whether to attribute the burn to the **share owner** (the only identity available at drop
  time, and the one `{username}` would then render), or to leave file-drop out of scope and
  say so in the README. Doing neither is what makes it a gap
- [ ] Unit tests still missing for three archive behaviours: that the handler claims only
  `Directory` + archive-accepting GETs, that tar member size is the watermarked length, and
  that over-cap `on_share` denies while over-cap `on_download` defers to core
- [ ] Automate the archive E2E scenarios that are currently manual

### Triggers

- [x] **On demand** — file-action menu, applied in place
- [x] **On upload** — `NodeWrittenListener` queues `WatermarkOnUploadJob`
  - the burn **cannot** run inline in the listener: `NodeWrittenEvent` fires while the
    triggering write still holds a lock on the node, so `putContent()` from there throws
    `LockedException`. Not DAV-specific — a plain Files-API `newFile()` fails identically.
    The listener therefore only enqueues
  - [x] Only fires when the effective config's trigger is `on_upload`
  - [x] Guarded against the watermarked write re-triggering the listener
    (`NodeWrittenListener::suppressFor()`), used by both the inline and job paths
  - [x] **Prompt for DAV uploads** (`UploadWatermarkPlugin`). The job alone is only as prompt
    as cron, and on a default AJAX-cron instance an upload sits clean for minutes — which
    reads as "on-upload is broken" in the Files UI. `afterMethod:PUT` runs after Sabre's
    handler returns, by which point the write's lock is released, so the burn happens
    in-request and the file is watermarked before the upload response is sent
    - `afterMethod:MOVE` is hooked too: chunked uploads (large files from the web UI and the
      desktop client) assemble into place with a MOVE and never PUT the final path
    - the job remains the fallback for non-DAV writes (Files API, `occ`, other apps) and for a
      failed inline burn — which is why the inline path leaves the queued job alone on error
      and removes it only on success
  - the job has no session, so it passes the uploading user to `watermarkInPlace()`
    explicitly; otherwise `{username}` renders "Unknown" and the audit row says "system"
- [x] **On download** — `DownloadController` streams a watermarked temp copy, original
  untouched, temp deleted after the response is sent
- [x] **On share** — watermarked at *delivery* time, not at share-creation time
  - the SDD's original design (a `ShareCreatedListener` saving a `{name}_shared.{ext}` copy)
    was **not** built: it duplicates storage and leaves the original reachable through the
    same share. `DownloadInterceptorPlugin` streams a watermarked copy per fetch instead,
    keyed off `WatermarkService::isShareAccess()`
  - [x] Internal recipients get a watermarked copy; the owner's own fetch is untouched
  - [x] Public links get the same treatment — they are served by a *separate* Sabre server
    (`public.php/dav`, `BeforeSabrePubliclyLoadedEvent`) that never fires
    `SabrePluginAddEvent`, so it needs its own registration
  - [x] Public links are served off the *owner's* storage, so the `ISharedStorage` test alone
    reports "owner access". `isShareAccess()` also takes an explicit public-context flag and
    the anonymous-request signal
  - [x] Previews are blocked for recipients and public-link visitors — they render from the
    clean original and are cached per file, not per viewer
  - [x] A render failure denies the fetch (403) rather than serving the clean original

### Folder and multi-file (ZIP) downloads

Downloading a **folder**, or a multi-file selection, streamed an archive that bypassed the
watermark entirely, in every mode. Core's `ZipFolderPlugin` registers on `method:GET` at
priority 100 and streams each member straight from `$node->fopen('rb')`;
`DownloadInterceptorPlugin` runs earlier but returns immediately for non-`DavFile` nodes, so
it never saw the members. This affected the authenticated Files app and public links alike.

`ZipInterceptorPlugin` claims the archive request at priority 95 and rebuilds it with
watermarked members, mirroring core's request parsing so archives keep their familiar shape.

- [x] Own `method:GET` handler below core's priority, rebuilding via `\OC\Streamer`
  - the alternative — a read-stream storage wrapper making `fopen('rb')` yield watermarked
    bytes — was rejected: far wider blast radius for the same outcome here
  - trade-off accepted: it duplicates core's `streamNode` and request parsing, so it must be
    re-checked against `ZipFolderPlugin` on Nextcloud upgrades
- [x] Sabre's own response suppressed for handled requests (`afterMethod:GET` → false), since
  the archive is written straight to the output buffer
- [x] `BeforeZipCreatedEvent` dispatched before taking over, so other apps' download vetoes
  still apply
- [x] **Size handling:** only tar needs it. `ZipStreamer::addFileFromStream()` derives size
  while streaming; `TarStreamer` records it up front. The *watermarked* temp copy's
  `filesize()` is passed, not the original's
- [x] **On demand / on upload** need no work: the watermark is in the stored bytes, so a plain
  archive already carries it. The coarse gate returns false and core handles it untouched
- [x] **On download** — every supported member watermarked, for any downloader, owner included
- [x] **On share** — members watermarked for recipients and public-link visitors, owner's own
  folder download untouched, registered on both DAV servers
- [x] **The gate is per member, never per container.** This was a real leak. The coarse gate
  used to be `deliveryApplies($folder)`, but a received *single-file* share is mounted inside
  the recipient's own home, so the containing folder is not an `ISharedStorage` and reported
  "owner access" while the member itself was a share. Effect: the single file downloaded
  watermarked, but **"download selected" on that same file shipped the clean original**.
  Folder shares hid it, since there the container *is* the shared mount. Now gated on
  `hasDeliveryTriggerConfigured()` (one indexed, owner-agnostic query) with each member judged
  by `deliveryTriggerFor()`. `deliveryApplies()` was deleted rather than left available
- [x] **Deny rather than leak:** members are rendered *before* any bytes are sent, so a failed
  render aborts with a real 403 instead of a truncated archive
- [x] Non-watermarkable members (unsupported MIME, excluded by filter or folder tag) stream
  through untouched. An `on_share` archive containing them is **allowed**, matching
  single-file behaviour. Only a file the policy *does* cover and fails to render denies it
- [x] Bounded temp usage: members rendered to temp files capped at 200 members / 256 MiB, all
  deleted in a `finally` on every exit path
  - a deliberate departure from the original "never materialize all members" intent: lazy
    streaming cannot produce a clean 403 once headers are out, so `on_share` correctness won
    over strict streaming. Cost is bounded by the caps
- [x] Over-cap behaviour: `on_share` denies (403); `on_download` degrades to core's plain
  archive, consistent with its documented best-effort contract
- [x] Verified by hand on the S3 stack across recipient single-file share (zip + direct),
  recipient folder share, public-link zip, owner's own zip (correctly untouched), `files=`
  multi-file selection, an unrenderable member denying with 403 on both internal and public
  paths, and no temp files left behind

### Temp-file leak found while testing archives — fixed

`WatermarkService::watermarkFile` writes the file's full plaintext to a `*_src` temp copy
before rendering, and only unlinked it on the success path. Every failed render therefore left
a readable copy of user content in the system temp dir forever. This predated the archive work
and affected the single-file download path too — it merely surfaces constantly here, because
every `on_share` deny goes through a failed render.

- [x] `*_src`, any partial output, and the temp dir are cleaned up when a render throws
- [x] `WatermarkServiceTest` pins it — neither the source copy nor its directory survives

### Permissions

- [x] `applyWatermark` checks readability and updateability before processing
- [x] All file paths resolved through `\OCP\Files\IRootFolder`, so no traversal outside the
  acting user's home

---

## 4. Admin UI and file actions (Goal 4)

**Position:** the settings page, audit log, apply/remove file actions and the watermarked
badge are all built. One SDD feature — group overrides — is stored but not honoured.

### Open {#open-4}

- [ ] **Group overrides.** `group_id` is accepted by `saveConfig` and stored, but
  `WatermarkService` resolves user → global → default and **never reads it**, and the mapper
  has no `findByGroup`. A group policy therefore does nothing at all. Either implement
  resolution (mapper finder + a place in the precedence chain + UI) or drop the column and the
  parameter — the current state is the worst of both, because it looks supported
- [ ] `AdminSettings.vue` group-overrides UI, dependent on the above

### Backend

- [x] `ApiController` — `getConfig`, `saveConfig`, `deleteConfig`, `applyWatermark`,
  `removeWatermark`, `uploadImage`, `getLog`, `getWatermarkedStatus`
- [x] `saveConfig` validates type, trigger, colour, template tokens, image reference,
  MIME filter, folder tag, and the flattening settings — see [Security](#security) and the
  scope-field notes below for the two that were added after they caused real failures
- [x] `applyWatermark` returns a descriptive error for unsupported file types
- [x] `getLog` is admin-only (403 otherwise) via `IGroupManager::isAdmin()`
- [x] `SettingsController` admin page, `AdminSettings` / `AdminSection` registered in
  `info.xml`

### Settings form

- [x] Global policy, default template, load-on-mount, save confirmation
- [x] `WatermarkForm.vue` — live SVG preview with placeholder substitution
- [x] Image upload field, replacing the old free-text path field: the admin picks a file, it
  uploads to `POST /api/v1/image`, and the config stores only the opaque reference returned
  - client-side type + 2 MB checks are a convenience; `WatermarkImageStore` re-validates
    server-side from the file's **actual bytes**, which is the check that counts
  - **PNG/JPEG only — SVG dropped deliberately.** It never worked in two of the three render
    paths (the GD fallback decodes only PNG/JPEG; TCPDF's `Image()` cannot place an SVG), and
    storing attacker-authored markup that ImageMagick may parse with external-entity or
    remote-fetch delegates is not worth the one path where it did
- [x] Flattening block — a real `NcCheckboxRadioSwitch` toggle plus a DPI slider, rendered
  only when the server reports `flattenAvailable` **and** the config can touch a PDF
- [x] **"Where to apply" rebuilt after both of its fields turned out to be traps.** Each was
  stored verbatim, and each had a plausible wrong value that disabled watermarking with
  nothing on screen to explain it:
  - a **tag name** typed into the free-text "system-tag ID" box — the obvious mistake — was
    accepted with a 200, after which every watermark attempt died on
    `InvalidArgumentException: Tag id must be integer`. That class is not a `RuntimeException`,
    so it sailed past the controller's handler and surfaced as a bare **HTTP 500** per request
  - a **mistyped MIME type** (`aplication/pdf`) was accepted, after which the filter matched
    nothing — and the error an admin eventually saw named the type they had typed *correctly*:
    "MIME type 'application/pdf' is not in the configured whitelist"
  - the help text also contradicted the code, claiming files carry the tag when the server
    checks the *containing folder*
  - now: the MIME filter is a checkbox list of exactly the supported types, the tag is picked
    with `NcSelectTags` so the stored value is always an id that exists, `saveConfig` validates
    both (unknown type, non-numeric tag, non-existent tag → 400 naming the problem), blank
    normalises to `null`, and `assertFolderTagMatches` converts a legacy bad tag into the
    app's ordinary skip path instead of a 500
- [x] `AuditLog.vue` — paginated table (page-size selector, prev/next) wired to
  `GET /api/v1/log`
- [x] `WatermarkModal.vue` — file name and estimated processing time before an on-demand apply
- [x] `main-admin.js` mounts the Vue 3 app in the admin content area

### File actions (`main-files.js`)

- [x] "Apply Watermark" `FileAction` in the file and context menus
  - shown for supported MIME types only, hidden for unsupported types and multi-select
  - `exec` opens `WatermarkModal` and awaits the result; spinner on the row; list refreshed
  - app SVG icon plus a localized display name
  - [x] Shown **only when the effective trigger is `on_demand`**.
    `LoadAdditionalScriptsListener` resolves the effective trigger (user → global → default)
    and hands it to the client as initial state; the shared single-file + supported-MIME +
    `on_demand` conditions are factored into `isSingleSupportedFile()` so Remove can reuse the
    same rule
- [x] "Remove watermark" `FileAction`, gated by the exact mirror of the Apply rule, so a row
  never offers both
  - confirmation dialog warning that the watermarked version is discarded, destructive-styled
  - spinner while restoring; badge cleared and both actions re-evaluated via a
    `files:node:updated` emit, without a folder reload
  - a distinct restore icon, deliberately not the Apply icon

### Watermarked-file indicator

- [x] `PropFindPlugin` exposes `nc:is-watermarked` per node, primed with one batched
  `findWatermarkedFileIds` query per folder listing — **this is the indicator's primary status
  source**; `GET /api/v1/watermarked` remains as a REST endpoint but the UI no longer calls it
- [x] `WatermarkLogMapper::findWatermarkedFileIds()` — one batched `IN (...)` query
- [x] Status semantics decided and documented: resolved from the **most recent in-place event**
  per file, so apply → removed → apply resolves correctly
- [x] Badge rendered on watermarked rows with a localized tooltip; `decorateRows()` plus a
  debounced `MutationObserver` handles row mounting and recycling
- [x] Only supported MIME types are decorated, and the property is scoped server-side too
- [x] Absent property is treated as "not watermarked" and never blocks the file list

### Skip already-watermarked files

- [x] `watermarkInPlace()` returns `false` when `isAlreadyWatermarked()` matches, and
  `applyWatermark` branches on that single source of truth rather than doing a second lookup
- [x] A distinct non-error response the UI can branch on:
  `['status' => 'already_watermarked']`, HTTP 200, surfaced as an informational note
- [x] `enabled(files)` reads the DAV property directly, so the action is hidden synchronously
  the moment a watermarked row mounts — no client-side id cache
- **scope note:** `on_share` / `on_download` render from the clean original and never burn in
  place, so they cannot cumulatively re-stamp and are intentionally *not* guarded. Guarding
  them would serve un-watermarked content

### Remove watermark (restore original)

Because `watermarkInPlace` **burns** the watermark into the content, "remove" means restoring
a preserved copy of the pre-watermark original — not algorithmically stripping pixels.

- [x] **App-managed backup** in appdata (`OriginalStore`), keyed by file id
  - Nextcloud file versions were the alternative and were rejected: the versions app can be
    disabled, and version expiry would silently delete the only route back
  - appdata sits outside every user's storage, so a backup is not itself browsable, shareable
    or watermarkable
- [x] The snapshot is taken **before** `putContent`, pinned by a test that asserts the
  ordering — reading after the write would preserve the watermarked bytes
- [x] `store()` never overwrites an existing backup, so re-watermarking cannot replace the
  true original
- [x] A failed backup is logged and does not abort the apply; the watermark just becomes
  un-removable, which the remove endpoint reports honestly (422)
- [x] `POST /api/v1/remove` — readability and updateability checks mirroring `applyWatermark`,
  422 when no original exists, restore then discard the backup
  - the backup is discarded only *after* the write lands, so a failed restore leaves the
    original recoverable on a later attempt
- [x] `watermark_log` gains a `removed` row rather than having rows deleted — this is an audit
  log, so both the apply and the undo stay in the history
- [x] Verified by hand: apply → remove restores a **byte-identical** original, backup
  discarded, status cleared, a second remove 422s, re-apply works, and the audit trail keeps
  all three events

---

## 5. Storage backends (Goal 5)

**Position:** done, and no S3-specific code was needed.

Storage-agnostic by design: all file I/O goes through the Files API (`getContent`,
`putContent`, `newFile`); only short-lived temp copies touch the local filesystem.
`docker-compose.s3.yml` (Nextcloud + RustFS) exists to verify it.

- [x] `DownloadController` serves a watermarked copy on S3-backed storage — content is staged
  to a local temp via `getContent()` and that temp is streamed; the S3 object is untouched
  (asserted in `DownloadControllerTest`)
- [x] On-demand, on-upload and on-download verified by hand on an S3 primary-storage instance
  (NC 31.0.14.1 + RustFS), with the S3 object byte-identical before and after a download
- [x] **The S3 run surfaced three real bugs, none of them S3-specific** — all three reproduce
  on local storage, and all three are fixed and regression-tested:
  1. on-upload threw `LockedException` and never watermarked anything (see the on-upload notes
     under Goal 3)
  2. the audit row was written inside `watermarkFile()` *before* `putContent()`, so a failed
     write left a row asserting a watermark that was not in the file. Because
     `isAlreadyWatermarked()` reads that same log, the phantom row then made every retry skip
     the file permanently. Logging moved to after the write lands
  3. a failed in-place write leaked the plaintext watermarked temp copy — `discardTemp()` now
     runs in a `finally`

---

## PDF stack migration to tc-lib-pdf

**Position:** decided, nothing started. The whole PDF path moves off `setasign/fpdi` +
`tecnickcom/tcpdf` onto `tecnickcom/tc-lib-pdf` + `tecnickcom/tc-lib-pdf-parser` — Nicola
Asuni's rewrite of TCPDF, so this is a successor rather than a third-party swap.

**Why:** tc-lib-pdf reads what FPDI refuses, in pure PHP. Measured on 8.67.2 against
fixtures built with `qpdf`:

| Fixture | FPDI | tc-lib-pdf |
| --- | --- | --- |
| plain PDF 1.7 | reads | imports |
| PDF 1.6, object streams + compressed xref | `CrossReferenceException` code 267 | **imports** |
| empty user password (permission flags only) | refuses | refuses (`ImportUnsupportedFeatureException`) |
| real user password | refuses | refuses |

A full round-trip — `setImportSourceFile` → `importPage` → `page->add()` →
`useImportedPage` → `getOutPDFString` — placed the imported page at 210×297 and `pdftotext`
still returned its text, so the import is a Form XObject and the text layer survives.

**What this does not buy, and must not be lost in the move:** tc-lib-pdf refuses *all*
encrypted documents, including the empty-password permission-flag case that
`qpdf --decrypt` recovers today. The normalizer therefore **stays**, narrowed to
decryption — dropping it would close the compressed-xref gap and reopen a smaller one.
See step 5 under [Sequencing](#migration-plan).

### Sequencing {#migration-plan}

Ordered so the suite is green at every step and no commit leaves the app less capable
than the one before it.

- [ ] **1. Dependencies and platform.** Add `tecnickcom/tc-lib-pdf` and
  `tecnickcom/tc-lib-pdf-parser` alongside the existing FPDI/TCPDF rather than replacing
  them, so the two stacks can be diffed against each other during the port
  - `tc-lib-pdf` requires **`ext-bcmath`**, which this app has never needed. Add it to
    `composer.json` `require`, to `<dependencies>` in `appinfo/info.xml` (which currently
    declares only `php` and `nextcloud`), to `ci/php.Dockerfile` via `install-php-extensions
    bcmath`, and to the compose entrypoint. Composer resolution **fails outright** without
    it — verified
  - confirm `php-bcmath` is in RHEL 9 AppStream on the real target, same open question as
    `qpdf` and `poppler-utils` under [Renderers](#open-1)
  - it pulls **13 transitive `tc-lib-*` packages** (unicode, font, graph, image, page,
    filter, encrypt, color, file, barcode, sign, unicode-data). Check each against the app
    store's bundled-dependency rules before packaging
  - licence is unchanged in substance: LGPL-3.0, as TCPDF already is, inside an AGPL app

- [ ] **2. `PdfWatermarker` — the hard one.** The import half is close to a rename; the
  drawing half is a rewrite against a different model
  - import maps almost one-to-one: `setSourceFile` → `setImportSourceFile` (returns a
    source *id*, not a page count), `setSourceFile`'s return → `getSourcePageCount($id)`,
    `importPage($n)` → `importPage($id, $n)`, `getTemplateSize` → `PageTemplateInterface`'s
    `getWidth()` / `getHeight()`, `useTemplate` → `useImportedPage($tpl, $x, $y)`
  - **the drawing model is the real work.** TCPDF is stateful and imperative
    (`StartTransform`, `Translate`, `Rotate`, `Cell`, `SetAlpha`, `StopTransform` mutate the
    document). tc-lib-pdf's primitives *return content-stream strings* —
    `getStartTransform()`, `getRotation()`, `getStopTransform()`, `getExtGState()`,
    `getTextCell()`, `getTextLine()` — which the caller concatenates and hands to
    `Page::addContent()`. Every tile in `applyTextOverlay()` becomes string assembly
  - **`tilePositions()` is the one piece to leave alone.** It is pure geometry with no
    TCPDF dependency, it is the regression test for the illegible-watermark bug, and its
    22 assertions should keep passing untouched. If they do not, the port of the *caller*
    is wrong — treat that as the signal, not as a reason to edit the lattice

- [ ] **3. Re-derive the rotation convention, and do not assume it carries over.** This is
  the single most likely place to reintroduce the smear bug
  - TCPDF's `Rotate()` is counter-clockwise-positive on a y-**down**wards page, and
    `PdfWatermarker` compensates by passing `+rotation` to match the SVG preview's
    clockwise-positive `rotate(-rotation)`. That comment is load-bearing and its reasoning
    does not transfer
  - tc-lib-pdf's `Transform::getRotation(float $angle, float $posx, float $posy)` builds a
    **raw CTM in PDF's y-upwards space** — it flips y itself (`$posy = ($this->pageh -
    $posy) * $this->kunit`) and its matrix is `[cos, sin, -sin, cos]`. Different origin,
    different handedness, different sign. Re-derive from the rendered output against the
    settings live preview; do not port the `+rotation` by analogy
  - the negative-`SetXY` trap that caused the original bug is TCPDF-specific and should
    simply cease to exist, since positions become explicit matrix operands. Confirm that
    rather than assume it — `testOffPageTilesKeepTheirNegativeOffsets` is the check

- [ ] **4. `PdfFlattener`.** Smaller: it uses FPDI only to read page geometry and TCPDF only
  to place one PNG per page. `pdftoppm` and the whole rasterise leg are untouched
  - the reader becomes `getSourcePageCount()` + `importPage()`/`getWidth()`/`getHeight()`,
    the writer `Page::add()` with an explicit size plus `getSetImage()`
  - re-check the **unit** trap recorded at [PdfFlattener](#open-1): reader and writer must
    agree, or every page rebuilds at 1/2.835 scale. tc-lib-pdf's unit handling is its own
    (`$this->kunit`), so the existing `'P', 'pt'` pairing is not a guide
  - verify PNG is still an accepted image format and that no new Ghostscript/Imagick
    delegate sneaks in through `tc-lib-pdf-image` — avoiding that dependency is the reason
    `pdftoppm` was chosen in the first place

- [ ] **5. `PdfNormalizer` — keep, narrow, retitle.**
  - `--object-streams=disable` stops being necessary the moment the renderer can read
    object streams; `--decrypt` becomes the *whole* reason the class exists
  - Invoking it on only on the encryption exception (`ImportUnsupportedFeatureException`,
    which tc-lib-pdf raises distinctly and FPDI did not). The retry-on-failure shape in
    `PdfWatermarker::openSource()` already works
  - the fallback contract does not change: no binary, no rewrite, original error rethrown,
    trigger policy takes over

- [ ] **6. Tests.** The suite is the acceptance criterion for the whole migration
  - `PdfWatermarkerTest`, `PdfFlattenerTest`, `PdfNormalizerTest` and the `Fpdi`-based
    assertions inside them all read the *output* with `Fpdi` today. Those readers move to
    tc-lib-pdf's parser — at which point they can no longer prove the output is readable by
    anything else, so keep at least one `Fpdi` assertion as an interop canary until FPDI is
    actually removed
  - `CompressedXrefFixture` stays exactly as it is and flips meaning: the file it builds
    should now **import cleanly** rather than throw. That inversion is the single clearest
    signal the migration worked
  - `testCompressedXrefPdfFailsCleanlyWithoutQpdf` becomes wrong by construction and should
    be deleted, not adapted — without qpdf the file will now succeed
  - `WatermarkServiceTest` mocks both renderers, so it should need no change. If it does,
    something leaked out of the Service layer

- [ ] **7. Remove FPDI and TCPDF**, only once every test above is green against the new
  stack, and delete the interop canary in the same commit
  - `composer.json`, the `use` statements, and the FPDI-licence item under
    [Security](#open-security) — which this migration closes for compressed xref but
    **not** for password-protected files, since tc-lib-pdf refuses those too

- [ ] **8. Docs.** README's requirements table, the `qpdf` section (now decryption-only),
  the Features line naming FPDI + TCPDF, the project-structure comment, and the
  Environment entries here

### Risks accepted {#migration-risks}

Recorded because they were raised before the decision, not to relitigate it.

- [ ] **The import subsystem is young.** `importPage` is **absent** from 8.0.6 (2021-02) and
  8.1.4, and present by 8.20.0 (2026-05-10) — so it landed somewhere in the March–May 2026
  window, against a package shipping 26 / 30 / 39 releases in April / May / June 2026.
  8.67.2 was released 2026-07-22. Pin an exact version, read the upstream changelog before
  every bump, and expect churn
- [ ] **Import fidelity is proven on one page of one generated fixture.** Before trusting it,
  drive the real skeleton PDFs (`Nextcloud Manual.pdf` 1.5, `Reasons to use Nextcloud.pdf`
  1.6) and a scanned/CJK/transparency document through the new renderer and compare rendered
  output against the FPDI result, page by page
- [ ] **The tile geometry is the crown jewel and the thing most at risk.** It was rebuilt
  once already after a bug that made watermarks illegible in production-shaped documents.
  Its tests are the regression net; a port that "passes except for the geometry tests" has
  failed
- [ ] **`ext-bcmath` is a new hard platform requirement** on every host that runs this app,
  including ones already running it

---

## Data model

**Position:** the schema carries every implemented feature. Two columns are stored but never
read, and one SDD type is still missing.

### Open {#open-data}

- [ ] Verify the migrations run cleanly on **MySQL, PostgreSQL and SQLite**. They use the
  portable schema builder and run on SQLite; a cross-DB run has not been done
- [ ] `metadata` is not an accepted `type` — needs both `VALID_TYPES` and a migration
- [ ] **Two dead columns.** `position` and `group_id` are accepted, validated and stored, and
  then never read by anything:
  - `position` — text is always tiled and images always centred, which matches the UI copy, so
    the column encodes a choice the renderers do not offer
  - `group_id` — see [group overrides](#open-4)
  - both look supported from the outside. Either wire them up or remove them
- [ ] `WatermarkConfigMapperTest` covers the **entity**; the mapper's finders and
  insert/update are still untested, including `hasDeliveryTrigger()`, which the archive fast
  path depends on and which is currently only exercised through a mock

### Delivered

- [x] `watermark_config` and `watermark_log` created by migration
  `Version1000Date20260625000000`
- [x] Scope columns `mime_types` and `folder_tag`, both now validated on save
- [x] Flattening columns `flatten_pdf` (boolean, default false) and `flatten_dpi` (smallint,
  default 150), added by `Version1001Date20260727000000`
- [x] `WatermarkConfigMapper` — `findAll`, `findByUser`, `findGlobal`, `findById`,
  `findByUserAndMimeType`
- [x] `WatermarkLogMapper` — `findAll` with pagination, plus `findWatermarkedFileIds`

---

## Environment and dependencies

### Open {#open-env}

- [ ] **`ext-bcmath`**, a hard requirement of `tc-lib-pdf` and so of the
  [PDF stack migration](#pdf-stack-migration-to-tc-lib-pdf). Not currently required anywhere
  in the app, not declared in `appinfo/info.xml`, and Composer refuses to resolve
  `tc-lib-pdf` without it. Needs `php-bcmath` on RHEL 9 and `install-php-extensions bcmath`
  in the dev and CI images
- [ ] Headless LibreOffice / Collabora in the Docker dev environment — blocked on Office
  support being designed
- [ ] PHP `exif` / metadata libraries, for the invisible metadata watermark

### Delivered

- [x] PHP: `setasign/fpdi` `^2.6`, `tecnickcom/tcpdf` `^6.7`
- [x] `Imagick` preferred with a `GD` fallback; both paths covered by `ImageWatermarkerTest`
- [x] **`qpdf` for `PdfNormalizer`** — not optional in practice: without it most real-world
  PDFs are skipped rather than watermarked. `dnf install qpdf` on RHEL 9; `apt-get install
  qpdf` on the Debian dev images, where the compose entrypoint and `ci/php.Dockerfile` both
  install it so the compressed-xref cases actually run instead of skipping
  - chosen over the alternatives on the grounds that the app **already shells out** to a
    poppler binary, so this adds a package rather than an architecture. `pdftk` would have
    dragged in a JRE for the same job; Ghostscript re-distills the whole document and can shift
    fonts and colour; the pure-PHP options are either commercial (setasign's FPDI PDF-Parser,
    SetaPDF-Core) or unmaintained (`pauln/tcpdi`), and an unmaintained PDF *parser* is a
    security surface this app would then own
  - probed on PATH via `BinaryLocator`, shared with `PdfFlattener`, for the same
    RHEL-vs-Debian reason
- [x] **`poppler-utils` for `pdftoppm`** — optional, and only for flattening. `dnf install
  poppler-utils` on RHEL 9 (AppStream, no EPEL); `apt-get install poppler-utils` on the Debian
  dev images. Installed by both compose files' entrypoints, guarded so a restart is not a
  re-download, and documented in the README
  - **Imagick is deliberately not a fallback rasteriser.** On RHEL 9 that path is doubly weak:
    ImageMagick is not in base or AppStream at all (EPEL only), and its PDF delegate *is*
    Ghostscript, disabled by `policy.xml` by default over the Ghostscript CVEs. Requiring EPEL
    to reintroduce a Ghostscript dependency this app just avoided is a bad trade
- [x] Frontend: `@nextcloud/vue` `^9.8`, `@nextcloud/axios` `^2.5`, `@nextcloud/files` `^3.9`
- [x] `sabre/dav` pinned to **4.7.0** in `require-dev`, the exact version NC 31.0.14 ships —
  see the shadowing note under [Testing](#dav-plugin-test-harness)
- [x] Build assets (`npm run build`) and enable (`occ app:enable files_watermark`)

---

## Audit log

**Position:** recording and surfacing both work. One SDD integration is missing.

- [ ] Emit `CriticalActionPerformedEvent` into the Nextcloud admin audit log
- [x] Every watermark action records timestamp, user, file path and id, trigger mode and
  config id
  - the row is written **after** the in-place write lands, not before. Writing it first left
    phantom rows that then made every retry skip the file — see Goal 5
  - a `removed` row is appended on restore rather than deleting the apply row
- [x] Surfaced in the admin panel by `AuditLog.vue`, paginated, wired to `GET /api/v1/log`,
  admin-only server-side

---

## Security

**Position:** two real vulnerabilities were found and fixed here; three refinements remain.

### Open {#open-security}

- [ ] **Legacy `image_path` rows.** Configs that predate the reference check survive in the
  database and still look valid, even though they now resolve to no image. A migration should
  clear them; admins must re-upload either way
- [ ] Rate-limit or queue on-demand applies for large files — nothing throttles them today
- [ ] Review FPDI licence compatibility for **password-protected** PDFs. The 1.5+ half of this
  is closed — `qpdf` (Apache-2.0) does it without the commercial add-on — but documents with a
  real user password would still need setasign's FPDI PDF-Parser
  - the [tc-lib-pdf migration](#pdf-stack-migration-to-tc-lib-pdf) **retires the question
    rather than answering it**: FPDI leaves the tree entirely, and tc-lib-pdf is LGPL-3.0
    like TCPDF already is. It does not make password-protected files work — it refuses them
    too — so that capability gap outlives the licence one

### Delivered

- [x] **Fixed: any account could make the renderers read an arbitrary server file.**
  `saveConfig` is `#[NoAdminRequired]` and stored `imagePath` verbatim, while the renderers
  `file_exists()`ed it as a raw server path — so a regular user could point their personal
  watermark at any image readable by the web server and have it composited into files they
  downloaded. Confirmed exploitable on the test instance before the fix
  - `saveConfig` now rejects anything that is not a store-issued reference (400)
  - `WatermarkImageStore::localPath()` refuses non-references at *render* time too, so configs
    already holding a path resolve to no image and log a warning instead of reading the file —
    verified against the pre-fix row
- [x] **Fixed: a mistyped folder tag turned every watermark request into an HTTP 500.** See
  the "Where to apply" notes under Goal 4. Validated at save time *and* made survivable at
  render time, because validation cannot reach configs that already exist
- [x] Uploaded watermark images validated and stored outside the web root —
  `WatermarkImageStore` writes to appdata, names files itself (nothing client-supplied reaches
  the filesystem), caps at 2 MB, and derives the type from the file's real bytes rather than
  its name or declared MIME
- [x] Ownership and read permission validated before processing; every path resolved through
  `IRootFolder`
- [x] Audit-log endpoint admin-only (403 otherwise)
- [x] On-download temp files written to a dedicated temp dir and deleted after the response,
  including on the failure paths
- [x] Template output cannot inject script into the settings UI: the preview interpolates the
  template into an SVG `<text>` node through Vue's escaping and there is **no `v-html`
  anywhere** in `src/`. Verified by inspection rather than by a test
- [x] The app's own vendor autoloader cannot shadow core's libraries — see the shadowing note
  under [Testing](#dav-plugin-test-harness). Left unfixed it broke every DAV request on the
  instance

---

## Testing

**Position:** 226 PHPUnit tests (10–15 skip depending on which of `pdftoppm` and `qpdf` the
host has) and 77 Jest tests. The DAV layer, which used to be the blind spot every delivery bug
hid in, now has 48 of them.

### Open {#open-testing}

- [ ] **Cypress E2E — nothing is automated end to end.** The scenarios are listed under
  [Integration / E2E](#integration--e2e-cypress) and are all currently driven by hand
- [ ] `ZipInterceptorPlugin::streamNode` duplicates core's, and the stubs cannot catch it
  drifting from `ZipFolderPlugin`. Re-diff against core on every Nextcloud upgrade
- [ ] Psalm or PHPStan: neither `php -l` nor php-cs-fixer does any type analysis, so the DAV
  stubs' fidelity to core is unchecked by any tool
- [ ] `ApiControllerTest` gaps: `deleteConfig` and `getLog` have no tests. `getConfig` and
  `saveConfig` are covered for the flattening and scope paths only
- [ ] `WatermarkOnUploadJobTest` — an unknown user and a deleted file must be skipped rather
  than fatal, and the acting user must reach `watermarkInPlace()`
- [ ] `OfficeWatermarkerTest`, `MetadataWatermarkerTest` — pending those services
- [ ] `WatermarkConfigMapperTest` — mapper finders and insert/update (see
  [Data model](#open-data))

### DAV plugin test harness

`lib/Dav/` has 48 unit tests under `tests/Unit/Dav/`. This was the priority gap, because
every delivery-time bug found so far lived in exactly that untested layer, and each was caught
only by driving a real instance by hand:

- the archive gate keyed off the *container*, leaking clean originals for single-file shares
- `NodeWrittenEvent` firing under a lock, so on-upload never applied at all
- on-upload applying, but only as promptly as cron
- PDF tiles landing in a smear with bare top and left margins, because TCPDF folds a negative
  `SetX`/`SetY` round to the opposite page edge. The five `PdfWatermarkerTest` cases all passed
  throughout, since every one of them asserted only that a valid *n*-page PDF came out. Found
  by rendering a page to an image and looking at it
- both "Where to apply" fields accepting values that silently disabled watermarking, one of
  them with an HTTP 500 per request

- [x] Sabre and `OCA\DAV` are on the test path
  - **Sabre is not stubbed.** `sabre/dav` is a real `require-dev` dependency pinned to
    **4.7.0**, the exact version NC 31.0.14 ships in `3rdparty/`, so `Server`, `ServerPlugin`,
    `Tree`, `PropFind`, the `Sabre\HTTP` request/response pair and the exception hierarchy are
    the genuine classes under test. It earned its keep immediately: real Sabre rejected three
    wrong assumptions while the tests were being written
  - **A dev dependency did shadow core's copy, and the note here used to claim it could not.**
    `vendor/` being gitignored and rebuilt at package time says nothing about *dev*, where the
    whole tree — dev dependencies included — is live-mounted into the container. Composer's
    `vendor/autoload.php` registers with prepend = true for every installed package, so the
    app's Sabre 4.7.1 sat ahead of core's 4.7.0 and `Sabre\DAV\ICopyTarget` resolved out of the
    app. 4.7.1 added `int $depth` to `copyInto()`, which made core's own
    `OCA\DAV\Connector\Sabre\Directory` violate the interface it implements and log an error on
    **every DAV request**. `Psr\Log\`, `PhpParser\` and phpunit's global assertion functions
    were leaking the same way. Two independent fixes, both kept:
    - `Application::registerVendorAutoloader()` builds a loader from Composer's generated maps,
      keeps only the runtime packages (`setasign/fpdi`, `tecnickcom/tcpdf`) and *appends* it so
      core's autoloader always wins. It reuses the `ClassLoader` core already declared —
      `require_once` keys on file path, not class name, so including the app's copy fatals with
      "name is already in use"
    - the version pin above, so the Sabre under test is the Sabre in production
  - [x] `OCA\DAV\Connector\Sabre\{Node, File, Directory}` and `OC\Streamer` stubbed in
    `tests/stubs/CoreStubs.php`, required from `bootstrap.php` and kept out of composer
    autoload. They live in the server tree and are not installable from packagist, so they are
    the one place stubs remain
    - **fidelity:** signatures transcribed verbatim from the `nextcloud:31.0.14-apache` image
      rather than written from memory. `CoreStubs.php` carries the `docker create` / `docker cp`
      recipe to re-verify them on upgrade
    - `OC\Streamer` records its calls to a static log, because `ZipInterceptorPlugin`
      constructs it directly and it cannot be injected as a mock. That log is what makes the
      archive's *shape* — member set, names, sizes, bytes — assertable
- [x] `ZipInterceptorPluginTest` (18) — **regression: gate per member, never per container**;
  archive naming and root path for whole-folder vs selection; `files=` + `X-NC-Files` parsing;
  `BeforeZipCreatedEvent` veto honoured; over-cap → 403 under `on_share` but plain archive
  under `on_download`; defer to core when nothing was substituted
  - the per-member gate was **mutation-tested**: reinstating the old container gate makes the
    regression test fail, so the guard is real rather than merely green
- [x] `UploadWatermarkPluginTest` (12) — **regression: `afterMethod:MOVE` is hooked**, since
  chunked uploads never PUT their final path and a PUT-only hook silently skips every large
  file; job removed only on success and left queued on failure; no session, wrong trigger,
  unsupported MIME and unresolvable config all no-op
- [x] `DownloadInterceptorPluginTest` (9) — `on_download` streams a copy; `on_share` denies
  (403) when a render fails rather than serving the original; owner fetch untouched;
  `$publicContext` forces share treatment; hooks `method:GET` and never `beforeMethod:GET`
- [x] `PropFindPluginTest` (9) — `is-watermarked` for file nodes only; a folder listing costs a
  constant two queries rather than one per child

### Unit (PHPUnit)

Counts below are PHPUnit *test cases*, so a data-provider-driven test contributes one per row —
which is why `PdfWatermarkerTest` reads 20 against 8 test methods.

- [x] `WatermarkServiceTest` (47) — config resolution (user / global / default), renderer
  delegation per MIME type, skip / filter / already-watermarked paths, audit row written after
  the write lands, explicit `?IUser $actor` overriding the session, `deliveryTriggerFor()` per
  node, flattening order and fail-closed behaviour, and an unusable stored folder tag degrading
  instead of crashing
  - the **group** resolution case is absent because group resolution does not exist
- [x] `PdfNormalizerTest` (7) — a compressed-xref PDF 1.5 asserted unreadable by FPDI *before*
  and readable *after*, empty-password encryption removed, a real password and garbage bytes
  both refused with no partial file left behind, `isAvailable()` agreeing with PATH in both
  directions, and the missing-binary path throwing rather than silently writing nothing
  - the rewrite cases skip without `qpdf`, in the same shape as `PdfFlattenerTest`. Mocking the
    binary away would assert nothing worth asserting: the claim under test is that qpdf's
    *actual* output parses in FPDI, which no mock can make
- [x] `PdfWatermarkerTest` (23) — text / image / combined overlays, multi-page, corrupt PDF, a
  compressed-xref PDF 1.5 both ways (watermarked with `qpdf`, refused cleanly without it, the
  original left byte-identical either way), a password-protected file still refused with the
  binary present, no scratch rewrite left in the temp dir, and
  the tile geometry: no overlap at any rotation, a lattice spanning the whole page, and
  off-page tiles keeping their negative offsets (the regression test for the smear, verified to
  fail against the old placement code)
- [x] `PdfFlattenerTest` (11) — no extractable text layer (the actual security claim), one
  output page per source page, geometry preserved for non-A4 and landscape, DPI honoured and
  clamped, corrupt and missing sources failing closed, the page ceiling, and no leftover page
  bitmaps
  - the rasterise cases skip on a host without `pdftoppm`, which is a supported configuration.
    They run for real in the container, where they caught two bugs the host run could not see:
    the wrong FPDI base class, and a points-read-as-millimetres unit mismatch
- [x] `ImageWatermarkerTest` (10) — JPEG / PNG / WEBP output, GD fallback, opacity, rotation
- [x] `ApiControllerScopeTest` (11) — unsupported and mistyped MIME types rejected, blank
  normalised to null, a tag name rejected, a non-existent tag id rejected, a real tag accepted,
  and no tag lookup when none is given
- [x] `ApiControllerFlattenTest` (9) — capability reported to the form, the setting rejected
  when the renderer is absent, off by default, DPI clamped
- [x] `ApiControllerApplyWatermarkTest` (6), `ApiControllerRemoveWatermarkTest` (7),
  `ApiControllerWatermarkedStatusTest` (5), `ApiControllerImageTest` (9)
- [x] `NodeWrittenListenerTest` (9) — queues the job rather than watermarking inline, trigger
  gating, no-session and already-watermarked skips, `suppressFor()` re-entrancy
- [x] `WatermarkLogMapperTest` (4) — batched lookup, distinct ids, a removal cancelling an
  earlier apply, apply → removed → apply counting as watermarked again
- [x] `WatermarkImageStoreTest` (8), `DownloadControllerTest` (4),
  `BeforePreviewFetchedListenerTest` (4), `WatermarkConfigMapperTest` (4, entity only)
  - no `ShareCreatedListenerTest`: on-share is delivery-time, so it is covered by
    `WatermarkServiceTest` and `BeforePreviewFetchedListenerTest`

### Frontend (Jest)

- [x] `WatermarkForm.spec.js` (30) — image upload validation and server rejection, the
  flattening block's presence, absence and DPI reveal, and the "Where to apply" controls:
  exactly the supported types offered, a stored filter reflected, the selection written back in
  canonical order, the tag picker used instead of a typed id, and the corrected help text
- [x] `main-files.spec.js` (38) — action gating, badge decoration and recycling, apply/remove
  mirroring, `unmarkWatermarked`, explicit-0 vs missing property
- [x] `AuditLog.spec.js` (5), `AdminSettings.spec.js` (4)
- Every `@nextcloud/vue` component is stubbed per-component under
  `src/tests/__mocks__/@nextcloud/vue-components/`, so a new component import needs a new stub
  or the whole suite fails to resolve

### Manual verification matrix

Scenarios driven by hand against `docker-compose.s3.yml`, to be re-run before a release and
ideally promoted to E2E — each one has caught a real bug. Cross-check results against the
*clean* original's checksum, not just file size.

- [ ] **Trigger × access matrix.** For each of `on_demand` / `on_upload` / `on_download` /
  `on_share`: owner direct fetch, owner ZIP, recipient direct fetch, recipient ZIP,
  public-link fetch, public-link ZIP. Expected: `on_share` watermarks for everyone *except*
  the owner; `on_download` for everyone including the owner; the in-place triggers watermark
  the stored bytes so every path carries it and no interceptor engages
  - **partially done** — `on_share` has been driven through all six cells; the other three
    modes only through owner direct/ZIP and recipient ZIP. The remaining cells are the work
    here, and the full grid is what belongs in E2E
- [ ] **Tar archives** (`Accept: application/x-tar`) — broken in core itself; recheck
- [ ] **Public file-drop upload** — watermarked by neither path; decide whether to cover it
- [ ] **Large-file / many-member archive** — cross the caps and confirm `on_share` denies while
  `on_download` degrades
- [ ] **Encrypted / password-protected PDF** through every trigger
- [ ] **Concurrent uploads of the same path** — `suppressFor()` is a per-process static, so it
  does not span two simultaneous PHP workers; confirm `isAlreadyWatermarked()` is what actually
  prevents a double burn
- [x] **Single-file share vs folder share** — both watermark in ZIP form; a folder share hides
  the container-gate bug that a single-file share exposes
- [x] **Upload paths** — plain PUT, chunked PUT + MOVE, and a non-DAV write: the first two
  watermark in-request, the third falls back to the job
- [x] **Audit-log truthfulness** — exactly one row per applied watermark, attributed to the
  real acting user, and *no* row when the write failed
- [x] **No temp leakage** — `/tmp/nc_watermark_*` empty after both success and failure
- [x] **Background-job queue drains** — no orphaned or duplicate `WatermarkOnUploadJob`
- [x] **Flattening** — text layer gone from the delivered copy while the source keeps its own,
  page count and A4 geometry preserved, the page content reduced to a single full-page image
  draw with zero text-show operators, restore still byte-identical, and the per-fetch delivery
  path leaving the stored file untouched

### Integration / E2E (Cypress)

None of this exists yet.

- [ ] Upload PDF / image / Office → on-upload watermark applied **without waiting for cron**
- [ ] On-demand apply via the file action, then Remove Watermark restores the original
- [ ] Share a file → the recipient's download is watermarked, the owner's is not
- [ ] The same for a public link, including the share page's inline preview
- [ ] Folder and multi-select ZIP download → every supported member watermarked
- [ ] Download via `/api/v1/download` → original untouched
- [ ] The full flow on an S3-backed instance

### Linting and CI

- [x] PHP syntax lint plus the Nextcloud coding standard, enforced in CI
  - `nextcloud/coding-standard` (v1.5) with a verbatim ruleset in `.php-cs-fixer.dist.php`;
    `composer lint` / `cs:check` / `cs:fix`
  - `.github/workflows/php.yml` is the single PHP workflow — `lint` (syntax, 8.2 + 8.3, no
    composer install needed so it is the first signal on a PR), `coding-standard` (once, on
    8.2) and `phpunit` (8.2 + 8.3), superseding the separate `phpunit.yml` / `lint-php.yml`.
    All three run in parallel under one `php-` concurrency group, so a new push cancels the
    whole PHP run rather than leaving half of it going
  - **the codebase was reformatted to the standard** — tabs, unaligned operators, 47 of 49
    files, in one whitespace-dominated commit. `git blame` across it needs `-w`, and
    `git log` / `git diff` are easier to read with `-w` for anything spanning it
  - both gates were verified to actually fail (a bad-syntax file exits 1, a space-indented file
    exits 8) rather than being decorative
  - findings beyond whitespace: unused imports in `WatermarkConfigMapper` and its test
- [x] **Frontend CI** — `.github/workflows/nodejs.yml` runs three jobs: `npm run lint`
  (ESLint, Node 20), Jest on **Node 20 and 22**, and a `npm run build` webpack job. The build
  job is the one that matters beyond the suites: `js/` is committed, so a build that fails in
  CI while the checked-in bundle still works is the drift this catches
- [x] `Jenkinsfile` mirrors the GitHub workflow for the internal CI

---

## Docs and release

- [ ] Document every API endpoint (including `/api/v1/download`) with request and response
  examples
- [ ] Developer guide: how to add a new file-type renderer
- [ ] Add `CHANGELOG.md`, covering 1.0.0 and the 1.1.0 flattening release
- [ ] Package for the App Store and tag the release
- [ ] Headless LibreOffice in the documented Docker workflow, pending Office support
- [x] `appinfo/info.xml` is at **1.1.0**, bumped so the flattening migration runs. It still
  needs to match whatever tag ships
- [x] README covers requirements, installation, the Docker dev workflow, S3 testing with
  RustFS, and the optional flattening dependency with both `dnf` and `apt-get` lines
