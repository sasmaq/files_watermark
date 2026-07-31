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
Suites re-run 2026-07-31, both green: **222 PHPUnit** tests and **69 Jest** tests, with
**no skips** on the local host. Local run was PHP 8.2; 8.3 is covered by CI only.

One qualification the earlier "zero skips on every host" glossed over, and which widened when
[GD became the default image engine](#images-imagewatermarker): nothing is conditional on an
external *binary* any more, but two image tests are still conditional on the host's own build —
WebP support, and a TrueType font for the rotation case, which now cannot borrow Imagick's
renderer to get one.

That last point is new and worth stating plainly: the suite no longer varies by machine.
Every test that used to skip did so for want of an external binary, and the app no longer
has any — see [No external binaries](#no-external-binaries).

---

## Where this stands

| Area | Position | Open |
| --- | --- | --- |
| [1. Renderers](#1-renderers-goal-1) | PDF and images complete, pure PHP, PDF 1.5+ read natively. Office not started; flattening removed | Office pipeline, encrypted PDFs |
| [2. Watermark content](#2-watermark-content-goal-2) | Visible watermarks complete | Invisible metadata watermark |
| [3. Delivery and triggers](#3-delivery-and-triggers-goal-3) | All four triggers work, single-file and archive, on every access path | Config-driven caps, tar (core bug) |
| [4. Admin UI and file actions](#4-admin-ui-and-file-actions-goal-4) | Settings, audit log, apply/remove actions and the watermarked badge all done | Group overrides |
| [5. Storage backends](#5-storage-backends-goal-5) | S3 verified end to end; no S3-specific code needed | — |
| [Arabic and RTL](#arabic-and-rtl-support) | **Not started.** No `l10n/` directory at all, and both text renderers would render Arabic broken | Shaping strategy, an embedded Arabic font, `ar` translations, RTL CSS |
| [No external binaries](#no-external-binaries) | **Done.** No `exec()` anywhere; flattening removed with `pdftoppm`, decryption with `qpdf` | Release note for the dropped columns |
| [PDF stack migration](#pdf-stack-migration-to-tc-lib-pdf) | **Complete.** FPDI and TCPDF are gone from the tree | — |
| [Data model](#data-model) | Schema carries every implemented feature | `metadata` type, cross-DB run, two dead columns |
| [Environment](#environment-and-dependencies) | PHP + `bcmath` + Imagick/GD. **No external binaries** | LibreOffice, `exif` |
| [Security](#security) | Two real vulnerabilities found and fixed; their residue cleaned up | Rate limiting, font-metrics provenance |
| [Testing](#testing) | 222 PHPUnit + 69 Jest, no binary-conditional skips | Cypress E2E, the full trigger matrix, static analysis |
| [Docs and release](#docs-and-release) | README covers install, Docker and S3 | API reference, changelog, packaging |

The three things standing between this and a 1.0 release are **Office support**, the
**Cypress E2E suite**, and **release packaging**. Everything else open is a refinement — the
[PDF stack migration](#pdf-stack-migration-to-tc-lib-pdf), which was the one large piece of
scheduled rework, is complete.

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
- [ ] [Arabic in the watermark](#watermark-rendering-arabic-in-the-output) — the PDF renderer's
  only font is metrics-only Helvetica, which has **no Arabic glyphs**, and neither GD nor a
  plain ImageMagick build shapes or reorders Arabic. Arabic text renders broken today, not
  merely unstyled
- [ ] [Arabic in the Admin UI](#admin-ui-arabic-interface) — there is **no `l10n/` directory**,
  so the app ships zero translations in any language, and no RTL work has been done

### Rewrites (done)

- [x] [Remove every external binary dependency](#no-external-binaries) — no `exec()` in
  production code or tests. Cost: **PDF flattening deleted** (tamper resistance) and
  **empty-password encrypted PDFs are now skipped**. Bought: zero skips on every host, one
  platform requirement, and no host-dependent behaviour

- [x] [Migrate the PDF stack to tc-lib-pdf](#pdf-stack-migration-to-tc-lib-pdf) — **all eight
  steps done**. `setasign/fpdi` and `tecnickcom/tcpdf` removed, nothing in the tree references
  either, and PDF 1.5+ compressed-xref documents are watermarked with no external binary at
  all. The `qpdf` fallback it introduced was itself removed later, with the rest of the
  external binaries

### Correctness and robustness

- [x] [PDF 1.5+ with compressed xref](#open-1) — read natively by the renderer. No
  configuration, no external binary, no host-dependent behaviour
- [ ] [Archive caps](#open-3) are class constants, not configuration
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

**Position:** PDFs and images are complete, in pure PHP. Office formats are not started.
Rasterised flattening existed for tamper resistance and was **removed** — see
[No external binaries](#no-external-binaries).

### Open {#open-1}

- [x] **PDF 1.5+ with compressed xref — solved outright.** The renderer reads these files
  natively, so they are watermarked with **no external binary and no configuration**. Two of
  the three Nextcloud skeleton PDFs are such files, which is why this mattered so much:
  measured against `nextcloud:31.0.14-apache`, `Nextcloud Manual.pdf` (1.5) and `Reasons to
  use Nextcloud.pdf` (1.6) were both unwatermarkable, `Documents/Nextcloud flyer.pdf` (1.4)
  worked. An earlier note claimed *every* skeleton PDF was affected; it was two of three
  - it was fixed **twice**. First by a `qpdf` pre-pass that rewrote the refused document
    (`--object-streams=disable`) for FPDI to read — which worked, but only on hosts with the
    binary. Then properly, by the [tc-lib-pdf
    migration](#pdf-stack-migration-to-tc-lib-pdf), whose parser has no such limitation. The
    pre-pass was later deleted too, with the rest of the external binaries
  - **the text layer survives**, which is the whole reason this beats routing such files
    through the flattener. The overlay is a real content stream
  - the fixture is **hand-built byte by byte**, because neither TCPDF nor tc-lib-pdf will
    produce one — both write a classic xref table whatever the version and compression
    settings say. Simplifying it into `createSourcePdf()` yields a fixture that no longer
    reproduces the case and a test that passes for the wrong reason. It lives in the
    `CompressedXrefFixture` trait
  - what guards the fixture changed with FPDI's removal, and the guard had to be *replaced*
    rather than dropped: it used to be pinned by FPDI's own
    `CrossReferenceException::COMPRESSED_XREF` code, and is now pinned structurally against
    the bytes by `testTheFixtureStillUsesACompressedCrossReferenceStream`. Without it the
    fixture is free to decay into an ordinary PDF 1.4 file that passes everything and proves
    nothing

- [ ] **Encrypted PDFs are refused**, and that is now the whole of the gap — including the
  empty-password, permission-flags-only case that is not real protection. `qpdf --decrypt`
  used to rescue those; it went with the [external
  binaries](#no-external-binaries). Decrypting in pure PHP is possible in principle
  (`tc-lib-pdf-encrypt` is already a dependency) but is not wired to the import path
- [ ] The skip is honest but **silent to the end user**: an on-demand apply reports the error,
  yet an `on_upload` or `on_share` file that cannot be watermarked is only visible in the audit
  log. Much narrower now that only encrypted files can be skipped, but still worth surfacing

### PDF (`PdfWatermarker`)

Everything below was originally delivered against FPDI + TCPDF and has since been rewritten
on tc-lib-pdf — see [PDF stack migration](#pdf-stack-migration-to-tc-lib-pdf). The notes here
are the specification the rewrite had to keep satisfying, and still does; the tile geometry in
particular came through unchanged.

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

### Flattened (rasterised) PDFs — removed

Built, shipped, and then **deleted**. It rebuilt every watermarked page as a bitmap so the
overlay was fused into the pixels, which made the watermark impractical to strip — an
ordinary overlay is a separate content stream that `qpdf` or `mutool` can drop.

**Why it went.** The rasterise step needed an external renderer (`pdftoppm` from
poppler-utils), and the app is now required to spawn no processes at all. There is no
pure-PHP substitute worth having: rasterising a PDF means implementing or bundling a PDF
*interpreter*, which is a far larger surface than the watermarking this app exists to do.
Rather than keep one feature that dragged a binary dependency, a per-host availability
probe, an admin toggle that vanished when the binary did, and a schema column, the feature
was removed whole. See [No external binaries](#no-external-binaries).

**What was lost, stated honestly:** tamper resistance. The watermark is again a separate
layer that a determined user can strip with ordinary tools. Nothing else replaces it — the
app deters and traces, it does not enforce. Anyone who needs the overlay to be
unremovable needs a different mechanism (DRM, or server-side rendering that never hands
out the file), not this app.

**What was gained:** no external binaries, no host-dependent behaviour, no accessibility
trade-off. Flattening destroyed the text layer, taking selection, copy, search and
screen-reader access with it, which is a possible WCAG / EN 301 549 problem for a
document-management product; that is why it was off by default and why the form warned
about it at the point of switching it on. Every watermarked PDF now keeps its text layer.

Removed with it: `PdfFlattener`, `PdfFlattenerTest`, `ApiControllerFlattenTest`, the
`flatten_pdf` / `flatten_dpi` columns (dropped by `Version1002Date20260730000000`), the
`flattenAvailable` / `flattenDpiRange` API fields, the admin toggle and DPI slider, and
five `WatermarkServiceTest` cases. The decisions taken when it was built — PNG page
images, flatten-per-fetch with no cache, fail-closed on a failed rasterise, server forces a
stranded setting off — are recorded in git history rather than duplicated here.

### Images (`ImageWatermarker`)

- [x] Text and image watermarks on JPEG, PNG, WEBP
- [x] **GD is the default engine; Imagick handles what GD cannot decode.** The preference used
  to run the other way round, and was flipped for the reason that runs through the rest of this
  document: output should not depend on how the host was packaged. GD ships with essentially
  every PHP build and Nextcloud server already requires it, while Imagick is optional
  everywhere and EPEL-only on the RHEL 9 target — so "Imagick preferred" meant two servers with
  the same config could produce visibly different files, decided by an accident of packaging
  - **Imagick is not demoted to a missing-extension fallback.** It is selected whenever GD
    cannot read the input, which today means WebP on a GD without libwebp. That case used to
    throw `GD was compiled without WebP support. Install Imagick or recompile GD with
    libwebp.` — advice that was wrong precisely when Imagick *was* installed, since the old
    code only reached the GD path at all when it was not
  - engine choice depends on **format support alone**, deliberately. The tempting extra rule —
    hand text to Imagick when the host has no TrueType font, since GD's bitmap fallback cannot
    rotate — was rejected: it would make the engine, and so the output, depend on font
    packaging, which is the same trap one level down. That cliff is real and is a *font*
    problem, answered by bundling one (see [Arabic and RTL](#open-arabic), which needs a
    bundled font anyway)
  - `ImageWatermarker::engineForMime()` is public because it is the only part of the decision
    observable without reading pixels, and the two engines are meant to be indistinguishable in
    pixels
  - `apply()` detects the MIME type once and passes it down rather than letting the selector
    and the GD path each detect it, so the two cannot disagree
- [x] Opacity and rotation match the configured values
- [x] **Tiles no longer overlap — the image path now measures its text.** It stepped a fixed
  grid, `max(210, fontSize * 10)` across by `max(225, fontSize * 11)` down, that never looked
  at the string it was drawing. The *default* `{username} — {date}` template overran its
  neighbour by 30px at the default font size; `Mohammed Al-Amri — 2026-07-31 14:22:05` overran
  by 329px, which is more than the whole step, so it ran through the tile beyond as well. Long
  names, long templates and larger font sizes all made it worse, and the `{datetime}` token
  guaranteed it
  - this is the *same bug the PDF renderer had and fixed* — "spacing derived from the text's
    unrotated width and height" is half of what made PDF watermarks illegible. The image
    renderer never received that fix, and nothing connected the two, because each renderer
    owned its own copy of the placement code
  - so the lattice moved to `TileLattice`, and `PdfWatermarker::tilePositions()` now delegates
    to it. That entry point is kept rather than replaced at the call sites: its 22 assertions
    are the regression net for the illegible-watermark bug, and **those tests passing unchanged
    is the evidence the extraction was faithful**
  - both engines now measure with the same font that will draw — `imagettfbbox()` for GD,
    `queryFontMetrics()` for Imagick — and both anchor text at the *left end of the baseline*,
    which is neither corner of the box, so centring a rotated tile means rotating the
    anchor-to-centre offset and stepping back along it (`TileLattice::rotateOffset()`)
  - the bitmap-font fallback asks for an **unrotated** lattice, because `imagestring()` cannot
    rotate: spacing a tilted pattern for text that will be drawn flat is its own way of
    stacking tiles. Its font id is also clamped to 5 now, the highest `imagestring()` accepts —
    `intval(fontSize / 4)` handed it 8 for a 32pt watermark
  - **verified by looking at it**, at 0°, 45°, 90° and with a 40pt font, per the standing rule
    that geometry claims are only worth what the rendered pixels say
- [x] **Spacing widened, and all three surfaces now derive it from one number.** The gap
  between repetitions went from `fontSize * 2` to `fontSize * 3.5` — `TileLattice::GAP_FACTOR`,
  which is the single control on watermark density
  - it applies everywhere by construction: PDF and images share `TileLattice`, so on A4 at 18pt
    the step went 325.1 → 352.1pt along the text and **57.6 → 84.6pt across it**, where the
    crowding was most visible
  - **the settings preview was the one that could drift, and it had.** It spaced its pattern
    `font * 2.2` across and a flat `font * 2.6` down, against the renderers' `fontSize * 2` and
    `lineHeight + fontSize * 2` — so it was already showing a tighter vertical rhythm than any
    renderer produced. `WatermarkForm.vue` now mirrors `GAP_FACTOR` and `LINE_HEIGHT_FACTOR`
    with the same formula, both named and commented on both sides. The preview is what an admin
    approves; it has to be what the renderers draw
  - `PdfWatermarker` picked up `LINE_HEIGHT_FACTOR` in place of its own `1.2` literal. The
    image renderers deliberately keep measuring real glyph heights, which is closer still
- [x] **Tile count scales with canvas area, as a tiled watermark must** — and the wider gap took
  the edge off it. Measured on the delivery-path shape (per-fetch render): 85 tiles / 0.08s at
  1200×800, 684 / 0.82s at 4000×3000, 1296 / **1.75s** at 6000×4000, down from 125 / 1105 /
  2178 at the old spacing. Still uncapped; if it ever needs a limit it belongs beside the
  archive caps under [Delivery and triggers](#open-3), which are also class constants

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
  MIME filter and folder tag — see [Security](#security) and the
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
    paths (the GD fallback decodes only PNG/JPEG; the PDF renderer cannot place an SVG), and
    storing attacker-authored markup that ImageMagick may parse with external-entity or
    remote-fetch delegates is not worth the one path where it did
- [x] ~~Flattening block~~ — removed with the feature; see
  [No external binaries](#no-external-binaries)
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

## Arabic and RTL support

**Position:** not started. Nothing in the app is translated — there is no `l10n/` directory —
and both text renderers would produce broken Arabic today, for different reasons.

Two halves that share only the tokens they render, and they are worth keeping apart. The UI
half is ordinary Nextcloud translation work with a known shape. The watermark half is a
text-shaping problem that reaches into the font stack, and it is the one that can *look*
finished while being wrong: Arabic drawn as disconnected left-to-right letters is still a
valid PDF with a valid overlay, and every existing assertion would stay green.

The order matters. **Do the watermark half first**, or at least decide it first, because the
settings live preview is rendered by a browser — which shapes and reorders Arabic correctly —
so shipping the UI translation first creates a preview that promises output the renderers
cannot yet produce. That is the same trap as the rotation convention, where the preview is the
contract and the renderer had to be made to match it.

### Watermark rendering (Arabic in the output) {#watermark-rendering-arabic-in-the-output}

Three blocking facts, each read off the current tree rather than assumed:

- **The PDF renderer has exactly one font, and it has no Arabic.**
  `PdfWatermarker::FONT_FAMILY` is `'helvetica'`, and `resources/fonts` holds
  `helvetica.json` / `helveticab.json` — *metrics only*, no glyph outlines, nothing embedded
  in the output, because Helvetica is one of the PDF standard 14 that every conforming reader
  supplies itself. None of the standard 14 covers Arabic. So this is not a matter of picking a
  nicer face: Arabic needs **glyphs embedded in the file**, which is the first real change to
  the font story since it was set up. See `resources/fonts/README.md`
- **The image renderer's font choice is by name, and two of the three names have no Arabic.**
  `ImageWatermarker` hands Imagick `DejaVu-Sans-Bold`, and `findSystemFont()` walks a list of
  DejaVu, Liberation and macOS Arial paths for the GD path. DejaVu Sans and Liberation Sans
  carry no Arabic; Arial does. Arabic support on the image path is therefore *accidental and
  host-dependent* — precisely the class of problem [No external binaries](#no-external-binaries)
  was about, arrived at from a different direction
- **Neither image backend can be relied on to shape or reorder, and the one that definitely
  cannot is now the default.** `imagettftext()` draws the code points it is given in the order
  it is given, so Arabic comes out as isolated letters in reverse order **even with a font that
  has the glyphs** — and GD is [the default engine](#images-imagewatermarker) as of this
  version, so that is the path Arabic will actually take. Imagick's `annotateImage()` shapes
  only if ImageMagick was built against Raqm/HarfBuzz, which is not something to require of a
  host — and pushing Arabic to Imagick would reintroduce exactly the host-dependent output the
  default was flipped to remove. The app has to shape

### Open {#open-arabic}

- [ ] **Decide the shaping strategy first — everything below depends on it.** Either the app
  shapes once (bidi reordering, contextual initial/medial/final/isolated forms, the lam-alef
  ligature) and hands pre-ordered glyphs to every renderer, or each renderer delegates to
  whatever its own stack offers. Only the first produces identical output on every host, which
  is the standard this app already holds itself to
- [ ] **Establish what `tc-lib-pdf` already does, by rendering rather than by reading.** TCPDF
  shaped Arabic itself (`utf8Bidi()`, plus an RTL document mode) and tc-lib-pdf is the same
  author's rewrite, so the machinery may already sit in `tecnickcom/tc-lib-unicode` and
  `tc-lib-unicode-data` — both already installed as transitive dependencies, both already in
  `Application::RUNTIME_VENDOR_PACKAGES`. Put a known string through `getTextCell()` and inspect
  the emitted glyph order and forms
  - `الاختبار` is a good single probe: it exercises reordering, medial forms and a lam-alef
    ligature at once. A test that only checks "some Arabic came out" will pass on unshaped
    output
- [ ] **Bundle an Arabic-capable font and embed it.** Choose on coverage and licence — Noto
  Naskh Arabic or Amiri, both OFL — and settle three things the Helvetica setup never had to
  answer:
  - **subsetting**, because a full Arabic face embedded whole is hundreds of KB *per
    watermarked file*, and the delivery triggers render per fetch
  - generation through the library's own `Com\Tecnick\Pdf\Font\Import` with the TrueType flag,
    with the recipe recorded in `resources/fonts/README.md` beside the Helvetica one
  - `php-cs-fixer` is already told to leave `resources/fonts` alone; confirm that still holds
    for whatever the generator emits
  - a licence win, incidentally: OFL is a clean answer to the provenance question that README
    raises about the Helvetica metrics, whose upstream `core/LICENSE` is a 0-byte file
- [ ] **One face covering both scripts, chosen per config — not per string.** A watermark is
  usually mixed: `{username}` may be Arabic while `{date}` is digits. Per-run font fallback
  means splitting a string into runs and measuring each, which the tile geometry is not built
  for. A single face with Latin + Arabic coverage avoids the whole problem and is the
  recommended route
- [ ] **`measure()` must measure the *shaped* string.** It calls
  `getTextCell(..., drawcell: false)` and reads back the width, which feeds `tilePositions()`.
  Measuring unshaped code points overestimates — ligatures collapse two code points into one
  glyph — and mis-spaces the entire lattice
  - **do not touch `tilePositions()`.** It is pure geometry, script-agnostic, and its 22
    assertions are the regression net for the illegible-watermark bug. If they start failing,
    the caller is wrong; that is the signal, not a reason to edit the lattice
- [ ] **Image path: shape and reorder before drawing, and select the font by coverage.**
  `findSystemFont()`'s name list cannot express "has Arabic glyphs", so the bundled face should
  serve the image path too — which removes the host dependency for images as a side effect
- [ ] **Decide the failure mode, and rule one out.** When the configured text cannot be shaped
  or the font lacks a glyph, the options are a skip-plus-audit-row (consistent with how
  encrypted PDFs are handled) or drawing the text unshaped. Silently drawing broken Arabic is
  the outcome to exclude — it is indistinguishable from success to everything except a human
  looking at the file
- [ ] **`{date}` / `{datetime}` are locale-free today** — `date('Y-m-d')` in
  `WatermarkService::buildPlaceholders()`: ASCII digits, Gregorian, server timezone. For an
  Arabic deployment, decide whether to offer Arabic-Indic digits (`٠١٢٣`) and/or a Hijri date,
  and whether that follows the *viewer's* locale or a config field
  - this is a real trade-off rather than cosmetics: the watermark is traceability evidence, and
    a date that renders differently depending on who fetched the file is harder to reason about
    in an audit
- [ ] **Confirm Arabic round-trips the whole config path**, not just the renderer: `saveConfig`
  validation (the template-token check must not reject non-ASCII), 4-byte UTF-8 storage in
  `text_template` on MySQL, the JSON API, and back into the form
- [ ] **Tests must assert shape, not validity.** An Arabic case in `PdfWatermarkerTest` that
  checks extracted text *and* glyph order, plus a rendered-page image check — the standing
  lesson from the smear bug is that only looking at the pixels proves anything about output
  geometry, and shaping is the same kind of claim. Mirror it in `ImageWatermarkerTest` across
  **both** Imagick and GD, since the two backends fail differently here

### Admin UI (Arabic interface) {#admin-ui-arabic-interface}

**There is no `l10n/` directory,** so the app ships zero translations in any language and every
`t('files_watermark', …)` call falls through to its English source string. The calls themselves
are already in place — `main-files.js` and all five components import `t` from
`@nextcloud/l10n` — which is usually the part that is missing, so this is mostly extraction,
translation and RTL rather than instrumentation.

- [ ] **Create `l10n/ar.json` and `l10n/ar.js`** in Nextcloud's format (a `translations` map
  plus `pluralForm`). Arabic takes **six** plural forms; a wrong `pluralForm` string breaks
  every plural call. There are **no `n()` calls in the tree today**, which is worth knowing
  before someone adds the first one — the pagination copy in `AuditLog.vue` is the obvious
  candidate
- [ ] **Audit for unwrapped strings before translating anything**, since a missing `t()` cannot
  be found from the translation file. Worth reading specifically: the `PLACEHOLDERS` example
  values and `TYPE_OPTIONS` labels/descriptions in `WatermarkForm.vue`, `AuditLog.vue`'s column
  headers and page-size options, and the trigger/type option labels
- [ ] **Server-side strings are user-facing too.** `AdminSection` already takes `IL10N`, but
  `ApiController`'s validation errors are displayed verbatim by the UI — the 400 that names an
  unknown MIME type or a non-existent tag is interface text and is currently English-only
- [ ] **Translate the `info.xml` metadata** — `<name lang="ar">`, `<summary lang="ar">`,
  `<description lang="ar">`. The apps list and the App Store read these, and `l10n/` does not
  cover them
- [ ] **RTL layout: replace directional CSS with logical properties.** Nextcloud sets
  `dir="rtl"` on the document for RTL languages, so the work is making the app's own styles stop
  fighting it — `margin-inline-start`, `padding-inline`, `inset-inline-start`,
  `text-align: start`. The `.vue` scoped blocks are the source; `css/` is webpack output and
  must not be edited
  - known offender: `text-align: left` on `AuditLog.vue`'s table cells
- [ ] **The live preview needs a decision, and it is not a CSS one.** Browsers shape and reorder
  Arabic in SVG `<text>`, so the preview will look *correct* the moment an admin types Arabic —
  including before either renderer can match it. Set `direction` explicitly on the preview
  rather than letting it inherit the UI language, so the preview means one thing regardless of
  who is looking at it, and treat any preview/output disagreement as a renderer bug
- [ ] **Re-check the rotation contract for RTL text.** The preview is the pinned contract
  (`testPositiveRotationTiltsTheTextUphill`, and `patternTransform="rotate(-rotation)"` in the
  form), and it was already the single most likely place to reintroduce the smear bug. Confirm
  "uphill" still means the same thing in the output once the reading direction reverses
- [ ] **Jest:** `WatermarkForm.spec.js` and `AdminSettings.spec.js` assert English strings, so
  check whether they survive a loaded locale at all, and pin the preview's direction handling
  once decided

---

## No external binaries

**Position:** done. The app spawns **no processes** — no `exec()`, `shell_exec()`,
`proc_open()` or any equivalent, in production code or in tests. `grep -rn "exec("` over
`lib/` and `tests/` returns nothing.

**Why it matters here.** Every binary dependency this app had came with the same tail of
problems: a runtime probe to see whether the host had it, a feature that silently changed
shape when it did not, a package name that differed between the RHEL 9 target and the
Debian dev containers, an unverified claim about which distro repository ships it, and a
block of tests that skipped on whichever machine happened to lack it. The suite's skip
count used to depend on the developer's laptop.

### What was removed

| Removed | Was used for | Consequence |
| --- | --- | --- |
| `PdfFlattener` + `pdftoppm` | Rasterising pages so the watermark could not be stripped | **Tamper resistance is gone.** See [Flattened PDFs](#flattened-rasterised-pdfs--removed) |
| `PdfNormalizer` + `qpdf` | `--decrypt` on files locked with an empty password | **Empty-password encrypted PDFs are now skipped** rather than watermarked |
| `BinaryLocator` | Probing `PATH` for both of the above | Nothing left to probe |

Neither loss is invisible, and neither is being papered over:

- **Encrypted PDFs.** tc-lib-pdf declines every encrypted document, including the
  permission-flags-only case that is not real protection — a reader opens those without
  ever prompting. `qpdf --decrypt` used to recover them. Now they take the ordinary
  skip-plus-audit path. Pinned by `testEncryptedPdfIsRefusedCleanly`, which covers both a
  real password and an empty one, and asserts the refusal is *clean*: no destination
  written, source byte-identical
- **Tamper resistance.** There is no pure-PHP replacement, because rasterising a PDF means
  bundling a PDF interpreter. The honest position is that this app deters and traces; it
  does not prevent

### What it bought

- [x] **Zero binary-conditional skips.** 222 PHPUnit tests, none of them needing a binary. The
  suite result no longer depends on which machine ran it, which is worth more than it
  sounds — the flattener's rasterise cases were green on the developer's laptop and
  skipped in CI for most of their life
- [x] **No `exec()` anywhere, including fixtures.** The encrypted-PDF fixtures were built by
  shelling out to `qpdf --encrypt`; they now use tc-lib-pdf's own encryption support
  (`Com\Tecnick\Pdf\Encrypt\Encrypt`), so the test suite spawns nothing either. A test
  helper that shells out is still a process spawn in the repository
- [x] **One platform requirement left**, `ext-bcmath`, and it is declared in
  `appinfo/info.xml` so Nextcloud refuses to enable the app without it instead of failing
  at render time
- [x] **Two open questions closed by deletion** rather than answered: the RHEL 9 package
  names for `qpdf` and `poppler-utils`, and the unmeasured memory ceiling of the
  page-at-a-time rasterise loop

### Open {#open-nobinary}

- [ ] The **schema column drop is one-way.** `Version1002Date20260730000000` drops
  `flatten_pdf` and `flatten_dpi`; Nextcloud migrations have no `down()`, and re-adding the
  columns would not bring the feature back. An admin who had flattening enabled loses it
  silently on upgrade — worth a release note, since the audit log will not explain why
  newly watermarked PDFs are suddenly selectable text
  - the app version is bumped to **1.2.0**, without which Nextcloud would not run the
    migration at all
- [ ] Nothing enforces the no-`exec()` rule mechanically. A static-analysis rule or a
  one-line grep in CI would keep it true; right now it rests on review

---

## PDF stack migration to tc-lib-pdf

**Position:** steps 1–8 done; the migration is **complete**. The PDF path moved off
`setasign/fpdi` + `tecnickcom/tcpdf` onto `tecnickcom/tc-lib-pdf` +
`tecnickcom/tc-lib-pdf-parser` — Nicola Asuni's rewrite of TCPDF, so this was a successor
rather than a third-party swap. Both old packages have been removed and nothing in the tree
references them.

The sequencing and reasoning below are kept as the record of how it was done and what it
cost; the per-step notes are where the traps are written down.

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

**What this does not buy:** tc-lib-pdf refuses *all* encrypted documents, including the
empty-password permission-flag case. The normalizer was kept for exactly that, narrowed to
decryption (step 5) — and then **deleted** when external binaries were removed altogether,
so that gap is now simply open. See [No external binaries](#no-external-binaries).

### Sequencing {#migration-plan}

Ordered so the suite is green at every step and no commit leaves the app less capable
than the one before it.

- [x] **1. Dependencies and platform.** Add `tecnickcom/tc-lib-pdf` and
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

- [x] **2. `PdfWatermarker` — done.** The import half was close to a rename; the drawing
  half was a rewrite against a different model, and four prerequisites surfaced that this
  plan had not anticipated — all four are recorded under
  [What step 2 turned up](#migration-surprises), because each is a silent runtime failure
  rather than something a type error catches
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

- [x] **3. Rotation convention re-derived and pinned by a test.** The emitted matrix is
  `[cos sin -sin cos tx ty]`, and `testPositiveRotationTiltsTheTextUphill` asserts the
  text's own x-axis `(a, b)` points right and **up** — the contract the settings preview
  shows. Getting the sign wrong tilts every watermark opposite to the preview the admin
  configured, and nothing else in the suite would have caught it. This was the single most
  likely place to reintroduce the smear bug
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

- [x] **4. `PdfFlattener` — done.** As expected the smaller half: `pdftoppm` and the whole
  rasterise leg are untouched, and all 11 of its tests passed on the first run of the port
  - reader is `setImportSourceFile()` + `getSourcePageCount()` +
    `importPage()`/`getWidth()`/`getHeight()`; writer is `addPage()` with an explicit size
    plus `image->add()` and `getSetImage()`
  - the **unit** trap is handled by constructing both documents in `'pt'`, so geometry read
    off a template needs no conversion before being used as a page size
  - **reader and writer must be separate documents**, which the old FPDI/TCPDF split gave
    for free and one tc-lib-pdf instance would not: `importPage()` registers the source page
    as a Form XObject, so reusing the instance would carry the original content into the
    rebuilt file — exactly what the rebuild exists to destroy
  - PNG is still accepted and no Ghostscript or Imagick delegate appeared;
    `tc-lib-pdf-image` decodes PNG itself, so the reason `pdftoppm` was chosen still holds
  - **a small security improvement fell out of it.** The old TCPDF writer stamped `Powered
    by TCPDF (www.tcpdf.org)` into the rebuilt page, which `pdftotext` recovered from a file
    whose entire purpose is to have no text layer. A flattened page now extracts **1 byte
    and zero printable characters**

- [x] **5. `PdfNormalizer` narrowed to decryption — done**, alongside step 4, which had left
  its documentation actively wrong: it still described FPDI and compressed cross-references
  as the reason it existed
  - `--decrypt` is now the whole point. The renderer refuses every encrypted document, and
    files locked with an *empty* user password purely to set permission flags are common and
    otherwise perfectly readable
  - **the rescue is aimed at the encryption exception specifically**, not tried against every
    parse failure: `openSource()` reaches for the normalizer only on
    `ImportUnsupportedFeatureException`, which tc-lib-pdf raises distinctly and FPDI never
    did. A corrupt or truncated file now fails immediately instead of paying for a rewrite
    that could not have helped it
  - pinned by `testEmptyPasswordEncryptionIsRescuedByTheNormalizer`, and mutation-tested:
    aiming the guard at the wrong exception makes it fail while every other test stays green
  - `--object-streams=disable` is **kept** even though the narrowed trigger means it rarely
    does anything. It is harmless on a file that was going to be rejected outright; drop it
    at step 7 if it still looks like noise
  - the class name stays. "Normalizer" still describes it, and renaming would churn DI,
    tests and docs for no reader benefit
  - the fallback contract is unchanged: no binary, no rewrite, original error rethrown,
    trigger policy takes over. The missing-binary log line now names encryption instead of
    compressed cross-references, which is the only advice still true

- [x] **6. Tests — done.** The suite is green, with **1 skip** on a host carrying both
  `qpdf` and `pdftoppm` (that skip is the deliberate "binary absent" case, which can only
  run on a host without them). No production code and no test fixture is on FPDI or TCPDF
  any more; three deliberate, documented canaries are all that remain
  - the source fixtures moved too, not just the readers. They were built with `new TCPDF()`
    in three separate places, which step 7 would have broken — so the geometry-aware ones
    are now a shared `PdfFixtures` trait: `writePdf()`, `readPageCount()`,
    `readPageSizes()`. That also centralises the two things every copy would have to get
    right, points-everywhere and the `allowedPaths` allowlist
  - the mixed-geometry flattener fixture states its sizes in **points** rather than TCPDF's
    `'A5'`/`'A4'` format names, so the fixture now says out loud what the assertion is about
  - `CompressedXrefFixture` is unchanged and its meaning flipped exactly as predicted: the
    file it builds is imported cleanly instead of throwing. That inversion is the clearest
    single signal the migration worked
  - **three canaries were kept for step 7 to remove**, each marked as such at the time: one
    reading our own output with FPDI, because every other assertion goes through tc-lib-pdf
    and would keep passing on a file only tc-lib-pdf can read; and two fixture checks keeping
    the fixture honest about reproducing the original bug. Step 7 deleted two of them and
    **replaced** the third with a structural assertion rather than dropping it
  - `testCompressedXrefPdfFailsCleanlyWithoutQpdf` was deleted rather than adapted, as
    planned — without qpdf that file now succeeds
  - **`WatermarkServiceTest` needed no change at all**, which was the stated test of whether
    anything had leaked out of the Service layer. Nothing had

- [x] **7. FPDI and TCPDF removed — done.** `composer remove setasign/fpdi
  tecnickcom/tcpdf`; **nothing in the tree references either package** any more, in
  production or test code. What remains are comments that explain *why* something is
  shaped the way it is, which are worth keeping
  - `RuntimeVendorPackagesTest` earned its keep here: it failed the moment the packages
    left the lock file, naming both stale allowlist entries. That is exactly the drift it
    was written for, and it caught it without anyone remembering to look
  - **the interop canary had to be replaced, not just deleted.** It guaranteed the
    compressed-xref fixture still reproduced the original bug, and deleting it would have
    left a fixture free to decay into an ordinary PDF 1.4 file that passes every test
    while proving nothing. `testTheFixtureStillUsesACompressedCrossReferenceStream` now
    asserts the same property against the bytes — PDF 1.5 header, a `/Type /XRef` object,
    and no classic `xref` table
    - the first version of that assertion was wrong in an instructive way: matching the
      bare word `xref` hit the fixture's own page text ("Compressed xref fixture") and so
      failed for a reason that had nothing to do with the structure. It now matches
      `"\nxref\n"`, the token alone on its own line, which is how a classic table appears
    - mutation-tested: changing the fixture's header to `%PDF-1.4` makes it fail
  - `resources/fonts/helvetica*.php` deleted with TCPDF. The directory is now two `.json`
    files and its README
  - **`--object-streams=disable` dropped from the qpdf command**, the decision this step
    was told to make. Once the rescue was narrowed to encryption the flag could not affect
    any file that reaches it, so `qpdf --decrypt` is now the whole invocation and expresses
    exactly one intent
  - the trailing separator on `PdfFontPath::directory()` is gone too — it existed only
    because TCPDF concatenated the constant with the filename directly. tc-lib-pdf joins
    with `DIRECTORY_SEPARATOR` itself, verified both ways before removing it
  - the FPDI-licence item under [Security](#open-security) is **retired rather than
    answered**: FPDI has left the tree, so its licence no longer applies to anything here.
    Password-protected documents are still refused, but that is now a capability gap with
    no licence question attached

- [x] **8. Docs — done.** Most of it landed alongside the steps that caused it, so this was a
  consistency sweep rather than a rewrite
  - README: requirements table (`bcmath` added, `qpdf` demoted to optional), the PDF 1.5+
    section rewritten from "known limitation" to "works, no configuration", a new
    decryption-only `qpdf` section, the Features line, a Fonts section, and the
    project-structure tree — which this sweep also found broken, with `resources/` wedged
    inside the `lib/` subtree and orphaning `Settings/`
  - `doc/sdd.md` §9 dependency table: FPDI and TCPDF rows replaced by the two `tc-lib`
    packages and `ext-bcmath`
  - this file: test counts and skip counts corrected throughout (they had drifted three times
    as tests were added and removed), the Environment section rewritten around the new stack,
    the compressed-xref entry rewritten from "fixed by a qpdf pre-pass" to "solved outright",
    and the historical notes kept but re-tensed so they read as history rather than as the
    current design
  - `resources/fonts/README.md` records provenance, the licence gap, and why the TCPDF-format
    files that used to sit beside the JSON ones are gone

### What step 2 turned up {#migration-surprises}

Four prerequisites the plan above did not anticipate. None is exotic, and every one of
them fails at *runtime* rather than at compile or install time, so they are recorded in
full — a future reader hitting any of these will otherwise assume the library is broken.

- [x] **tc-lib-pdf ships no font data at all.** `tecnickcom/tc-lib-pdf-font` deliberately
  contains no metrics; its `make fonts` target downloads a 117 MB mirror and converts.
  Until that is solved *every* text call dies with `unable to read file: helveticab.json`,
  which reads like a packaging bug and is not one
  - resolved by committing two ~10 KB files to `resources/fonts`, generated once from the
    canonical Adobe Core-14 AFMs with the library's own `Font\Import`. Metrics only — no
    glyphs, and Helvetica is a standard-14 font that readers supply themselves
  - **licence provenance is unresolved**: the mirror's `core/LICENSE` is a 0-byte file, so
    upstream states no terms. The same metrics ship in TCPDF (LGPL-3.0, already vendored)
    and in most PDF libraries, so this is well-trodden rather than novel — but it is an
    open question for any formal licence audit, and `resources/fonts/README.md` records it

- [x] **`K_PATH_FONTS` is a global constant, and TCPDF fights over it.** It is the only
  lookup that survives a real deployment: the alternative walks up from the package
  looking for a `fonts` directory and requires it to be **writable**, which a hardened
  Nextcloud install will not be
  - merely *loading* the TCPDF class defines the constant to TCPDF's own directory, and a
    constant cannot be redefined. So it is first-come-first-served, and which stack wins
    depended on the order tests happened to run in
  - claimed in `Application::__construct()` and in `tests/bootstrap.php`, both before
    either stack can load. `resources/fonts` therefore holds **both** formats —
    `helvetica.json` for tc-lib-pdf beside `helvetica.php` for TCPDF, which still needs
    its own metrics while `PdfFlattener` was still on it. TCPDF also concatenated the
    constant with the filename without inserting a separator, so a trailing one was required.
    Both are gone with TCPDF: the directory is two JSON files, and the trailing separator was
    removed after verifying tc-lib-pdf joins paths itself
  - delete the `.php` files at step 7. `PdfFontPath::isUsingOwnFonts()` turns a hijacked
    constant into an error that names the culprit instead of a missing-file mystery

- [x] **Local file reads are allowlisted.** tc-lib-pdf refuses to read outside a set of
  trusted paths, which is a sound default for a renderer that also fetches remote assets,
  but everything this app feeds it is a temp copy — so the source PDF and the logo were
  both rejected with `Unable to read image file`
  - supplying `allowedPaths` **replaces** the defaults rather than adding to them, so the
    font directory has to be listed too or metrics that were loading a moment earlier stop
  - each directory is listed in both literal and `realpath` form: on macOS the temp dir is
    `/var/folders/...`, a symlink to `/private/var/folders/...`, and listing only the
    resolved form leaves the path the caller actually passes looking unauthorised

- [x] **The two stacks interoperate, so step 4 can stay separate.** Verified explicitly,
  because it was not obvious: FPDI reads tc-lib-pdf's output, so `PdfFlattener` — still on
  FPDI + TCPDF — flattens the new renderer's files unchanged. A watermark-then-flatten run
  produced a 139 KB rasterised PDF from an 19 KB overlay one
  - also verified the reverse of the migration's whole point: `getImageDimensionsByKey()`
    takes an `int` width, and `php-cs-fixer` had to be told to leave `resources/fonts`
    alone or it reformats vendored third-party files

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
  output against the pre-migration result, page by page — which now means checking against a
  known-good file kept for the purpose, since FPDI is no longer in the tree to compare with
- [ ] **The tile geometry is the crown jewel and the thing most at risk.** It was rebuilt
  once already after a bug that made watermarks illegible in production-shaped documents.
  Its tests are the regression net; a port that "passes except for the geometry tests" has
  failed
- [x] **`ext-bcmath` is a new hard platform requirement** on every host that runs this app,
  including ones already running it. Accepted and wired; `appinfo/info.xml` declares it so an
  upgrade onto a host without it refuses to enable rather than failing at render time

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
- [x] ~~Flattening columns `flatten_pdf` and `flatten_dpi`~~ — added, then **dropped** by
  `Version1002Date20260730000000`, and the migration that added them (1001) deleted outright
  since the pair was a no-op. **The version gap is deliberate.** A fresh install now takes the
  final schema from 1000 and the cleanup is a no-op; an instance that already applied 1001
  needs the cleanup, because 1000 will not re-run for it. `SchemaConvergenceTest` pins that
  both paths end with identical columns, and fails if someone folds 1002 away as the next
  obvious simplification. Original note, for the record: (boolean,
  default false) and (smallint,
  default 150), added by `Version1001Date20260727000000`
- [x] `WatermarkConfigMapper` — `findAll`, `findByUser`, `findGlobal`, `findById`,
  `findByUserAndMimeType`
- [x] `WatermarkLogMapper` — `findAll` with pagination, plus `findWatermarkedFileIds`

---

## Environment and dependencies

### Open {#open-env}

- [ ] Confirm **`php-bcmath`** is in RHEL 9 AppStream on the real target build, alongside the
  the last such question standing — `qpdf` and `poppler-utils` are no longer used. The
  extension is wired everywhere it needs to be; only the RHEL package availability is
  unverified from here
- [ ] Headless LibreOffice / Collabora in the Docker dev environment — blocked on Office
  support being designed
- [ ] PHP `exif` / metadata libraries, for the invisible metadata watermark
- [ ] **An Arabic-capable font, bundled and embedded** — the current `resources/fonts` holds
  metrics only, for a standard-14 face with no Arabic coverage, and the image path picks a font
  by *name* from a host list that is Latin-only apart from Arial. See
  [Arabic and RTL support](#arabic-and-rtl-support). Unlike the RHEL 9 questions above this one
  cannot be closed by a package: the glyphs have to travel inside the PDF

### Delivered

- [x] PHP: `tecnickcom/tc-lib-pdf` and `tecnickcom/tc-lib-pdf-parser`, both **pinned to an
  exact version** (`8.67.2` / `3.14.0`) rather than a caret range — the package ships several
  releases a week, so bumps should be deliberate and changelog-checked. They replaced
  `setasign/fpdi` and `tecnickcom/tcpdf`, which have been removed
  - they pull **13 transitive `tc-lib-*` packages**, every one of which must also appear in
    `Application::RUNTIME_VENDOR_PACKAGES` or its classes will not load inside Nextcloud;
    `RuntimeVendorPackagesTest` enforces that against `composer.lock` in both directions
- [x] **`ext-bcmath`** — a hard requirement of `tc-lib-pdf`, declared in `composer.json` and in
  `<dependencies>` in `appinfo/info.xml`, so Nextcloud refuses to enable the app on a host
  without it rather than fatalling on the first PDF. Composer will not even resolve without it.
  `install-php-extensions bcmath` in `ci/php.Dockerfile`, `docker-php-ext-install bcmath` in
  both compose entrypoints
- [x] **Font metrics committed to `resources/fonts`**, because the renderer ships none: the
  Composer package deliberately contains no font data and its `make fonts` target downloads a
  117 MB mirror. Two ~10 KB JSON files, metrics only, found through the global `K_PATH_FONTS`.
  See `resources/fonts/README.md`, including the unresolved licence provenance
- [x] **`GD` is the default image engine, `Imagick` covers what GD cannot decode** — see
  [Images](#images-imagewatermarker) for why the preference was flipped. GD is a Nextcloud
  server requirement already, so the default engine is present on every host by definition;
  Imagick stays optional and stays supported
- [x] ~~**`qpdf`** for `PdfNormalizer` and ~~**`poppler-utils`** for `pdftoppm`~~ — both
  **removed**. See [No external binaries](#no-external-binaries) for what went with them
  - kept here because the reasoning still applies to any future proposal to shell out.
    `qpdf` was chosen over `pdftk` (which drags in a JRE) and Ghostscript (which re-distills
    the document and can shift fonts and colour). **Imagick was deliberately never a
    fallback rasteriser**: on RHEL 9 it is EPEL-only and its PDF delegate *is* Ghostscript,
    disabled by `policy.xml` by default over the Ghostscript CVEs
  - the argument that eventually beat all of them was not about which binary: it was that
    every one of them makes behaviour depend on the host
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

- [x] **Legacy `image_path` rows — cleared** by `Version1003Date20260730120000`'s
  `postSchemaChange()`. Configs predating the reference check survived in the database and
  still looked valid in the admin form while resolving to no image; they are now nulled, which
  is the honest state. Affected admins must re-upload, which was always true
  - the test is `WatermarkImageStore::isReference()`, not a SQL pattern, so there is one
    definition of a valid reference and it is the one the renderers use. It also keeps the
    step portable — that regex is not something MySQL, PostgreSQL and SQLite would agree on
  - the update is skipped entirely when nothing is stale, so a healthy instance does not take
    a write on every upgrade, and chunked at 500 ids so a large one cannot outgrow a
    parameter limit
  - `LegacyImagePathCleanupTest` pins which rows are chosen — a valid reference, an absolute
    path, a traversal attempt, a non-hex name and an empty string — and mutation-tested:
    clearing every row instead of the stale ones fails it
- [ ] Rate-limit or queue on-demand applies for large files — nothing throttles them today
- [x] **FPDI licence question retired, not answered.** FPDI has left the tree entirely
  (step 7 of the [migration](#pdf-stack-migration-to-tc-lib-pdf)), so its licence no longer
  applies to anything here; tc-lib-pdf is LGPL-3.0, as TCPDF already was
  - **the capability gap outlived the licence one**: password-protected PDFs are still
    refused, because tc-lib-pdf refuses every encrypted document — and since `qpdf` was
    removed, the empty-password case is refused too. A plain feature limit with no licensing
    dimension, documented in the README
  - a new provenance question replaced it, and it is smaller but real: the committed font
    metrics under `resources/fonts` come from a mirror whose `core/LICENSE` is a 0-byte
    file. See [What step 2 turned up](#migration-surprises)

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

**Position:** 222 PHPUnit tests and 69 Jest tests, and **no test depends on an external binary**
any more. Two image cases still depend on the host's own PHP build (WebP, and a TrueType font
for rotation). The DAV layer, which used to be the blind spot every delivery bug hid in, now
has 48 of them.

### Open {#open-testing}

- [ ] **Cypress E2E — nothing is automated end to end.** The scenarios are listed under
  [Integration / E2E](#integration--e2e-cypress) and are all currently driven by hand
- [ ] `ZipInterceptorPlugin::streamNode` duplicates core's, and the stubs cannot catch it
  drifting from `ZipFolderPlugin`. Re-diff against core on every Nextcloud upgrade
- [ ] Psalm or PHPStan: neither `php -l` nor php-cs-fixer does any type analysis, so the DAV
  stubs' fidelity to core is unchecked by any tool
- [ ] `ApiControllerTest` gaps: `deleteConfig` and `getLog` have no tests. `getConfig` and
  `saveConfig` are covered for the scope paths only
- [ ] `WatermarkOnUploadJobTest` — an unknown user and a deleted file must be skipped rather
  than fatal, and the acting user must reach `watermarkInPlace()`
- [ ] `OfficeWatermarkerTest`, `MetadataWatermarkerTest` — pending those services
- [ ] **Arabic text cases in `PdfWatermarkerTest` and `ImageWatermarkerTest`**, asserting glyph
  order and shaping rather than "a valid file came out" — the whole failure mode here is output
  that every current assertion accepts. See [Arabic and RTL support](#open-arabic)
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
      keeps only the runtime packages (the `tc-lib-*` stack) and *appends* it so
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

- [x] `WatermarkServiceTest` (42) — config resolution (user / global / default), renderer
  delegation per MIME type, skip / filter / already-watermarked paths, audit row written after
  the write lands, explicit `?IUser $actor` overriding the session, `deliveryTriggerFor()` per
  node, and an unusable stored folder tag degrading
  instead of crashing
  - the **group** resolution case is absent because group resolution does not exist
- [x] `PdfWatermarkerTest` (25) — text / image / combined overlays, multi-page, corrupt PDF, a
  compressed-xref PDF 1.5 watermarked with no external binary, the fixture still structurally
  reproducing that case, encrypted PDFs refused cleanly with **both** a real and an empty user
  password (fixtures built with the renderer's own encryption, so no `exec()`), the rotation
  convention tilting the text uphill to match the settings preview, and the tile geometry: no
  overlap at any rotation, a lattice spanning the whole page, and off-page tiles keeping their
  negative offsets (the regression test for the smear, verified to fail against the old
  placement code)
- [x] `SchemaConvergenceTest` (6) — the whole schema now arrives in **one** migration, which
  therefore meets three different starting states (fresh, applied-1000, applied-1000-and-1001)
  and has to land all of them on identical columns. Also: the flattening columns dropped on
  upgrade, and running twice changing nothing
  - Doctrine is not a dependency of this app, so the schema objects are fakes. What is under
    test is the migration's branching, not Doctrine's DDL
  - **the fake had to be made stricter to be worth anything.** Its `createTable()` first
    replaced an existing table silently; a mutation removing the migration's `hasTable()`
    guard passed every test, because the recreated table happened to have the right columns.
    It now throws like Doctrine's `TableExistsException`, and that mutation fails
- [x] `LegacyImagePathCleanupTest` (2) — which `image_path` rows the migration clears, and
  that no write is issued when every stored reference is valid
- [x] `ImageWatermarkerTest` (26) — JPEG / PNG / WEBP output, opacity, rotation, **tiles never
  overlapping at any rotation**, and the
  **engine-selection matrix**: GD chosen for PNG/JPEG/WebP even with Imagick installed, Imagick
  chosen for WebP when GD lacks libwebp and for anything when GD is absent, and each of the
  three failure messages naming the limit that was hit
  - the matrix runs through `FakeImageStack`, which dictates the three capability probes rather
    than reading the host, so hosts this suite will never run on — a GD without libwebp, a
    server with neither extension — are covered anyway. Probing the real machine would have
    made which branches get exercised an accident of how PHP was compiled
  - `testBothEnginesProduceAWatermarkedImage` forces each engine in turn, since with GD now
    always winning the selection, Imagick would otherwise never execute on a normal host. It
    asserts equivalence — dimensions, format, ink drawn — not a checksum: the two engines are
    not pixel-identical and are not meant to be
  - **Imagick's rendering is therefore only executed where Imagick is installed**, which is CI
    (`ci/php.Dockerfile` installs it) and not a stock macOS dev box
  - `testTilesNeverOverlapInTheRenderedImage` reads the overlap out of the pixels rather than
    out of the geometry: at 50% opacity a singly covered pixel lands on 126 and a doubly
    covered one composites to 62, so the darkest pixel in the output *is* the measurement, and
    antialiasing cannot fake a pass because it only ever lightens. Mutation-tested — shortening
    the lattice step to `textWidth * 0.6` fails it at all five rotations
  - `testFontSizeIsConfigurable` had to change with the fix and is worth knowing about: it
    asserted that a bigger font adds more *ink*, which was only true because the old grid kept
    the tile count roughly fixed (12 tiles at both 12pt and 20pt on a 600×400 image). Spacing
    now scales with type size, so bigger text arrives in fewer tiles and total ink is not
    monotonic. It asserts the longest unbroken run of ink instead — that the glyphs actually
    got bigger, which is what the setting controls
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
- [ ] **An Arabic watermark template, looked at.** Render PDF and image output with an Arabic
  `text_template` and read the result: letters joined, right-to-left order, lam-alef ligature
  formed, and the tile spacing not blown out by a mis-measured shaped width. Also with an
  Arabic display name in `{username}`, and mixed Arabic + `{date}` in one template
- [ ] **The admin UI under an Arabic locale** — settings page, audit log and the live preview at
  `dir="rtl"`, with the preview's text matching the rendered output rather than only looking
  plausible
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
- [ ] Configure an Arabic template, apply on demand, and assert on the delivered file's text —
  the one cell of the Arabic work that is worth automating rather than eyeballing

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
- [ ] **Localisation section:** which languages ship, how to add one (`l10n/<code>.json` +
  `.js`, plus the `lang` attributes in `info.xml`), and what an Arabic *watermark* additionally
  requires — the bundled font, and whatever the shaping decision turns out to be. Record the
  font's licence beside the Helvetica provenance note in `resources/fonts/README.md`
- [ ] Add `CHANGELOG.md`, covering 1.0.0 and the 1.1.0 flattening release
- [ ] Package for the App Store and tag the release
- [ ] Headless LibreOffice in the documented Docker workflow, pending Office support
- [x] `appinfo/info.xml` is at **1.1.0**, bumped so the flattening migration runs. It still
  needs to match whatever tag ships
- [x] README covers requirements, installation, the Docker dev workflow, S3 testing with
  RustFS, and the optional flattening dependency with both `dnf` and `apt-get` lines
