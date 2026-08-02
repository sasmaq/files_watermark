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

Verified against **Nextcloud 31.0.14.1**, app version **1.5.0**, PHP 8.2 + 8.3.
Suites re-run 2026-08-01, all three green: **448 PHPUnit** tests, **91 Jest** tests and
**63 Cypress** end-to-end tests (one pending, for the [bidi bug](#open-bidi)), with
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
| [3. Delivery and triggers](#3-delivery-and-triggers-goal-3) | All four triggers work, single-file and archive, on every access path; caps are `occ` settings | Tar (core bug), file-drop uploads |
| [4. Admin UI and file actions](#4-admin-ui-and-file-actions-goal-4) | **Complete.** Settings, audit log, apply/remove actions and the watermarked badge all done; delivery audit is opt-in with an `occ` prune | — |
| [5. Storage backends](#5-storage-backends-goal-5) | S3 verified end to end; no S3-specific code needed | — |
| [Arabic and RTL](#arabic-and-rtl-support) | **Both halves done** — watermark shaped and reordered; UI translated (130 strings), RTL-clean, preview direction pinned | `{date}` localisation, a real Arabic instance, a renderer bidi bug this surfaced |
| [No external binaries](#no-external-binaries) | **Done.** No `exec()` anywhere; flattening removed with `pdftoppm`, decryption with `qpdf` | Release note for the dropped columns |
| [PDF stack migration](#pdf-stack-migration-to-tc-lib-pdf) | **Complete.** FPDI and TCPDF are gone from the tree | — |
| [Data model](#data-model) | Schema carries every implemented feature | `metadata` type, cross-DB run, two dead columns |
| [Environment](#environment-and-dependencies) | PHP + `bcmath` + Imagick/GD. **No external binaries** | LibreOffice, `exif` |
| [Security](#security) | Two real vulnerabilities found and fixed; their residue cleaned up | Rate limiting, font-metrics provenance |
| [Testing](#testing) | 448 PHPUnit + 91 Jest + 63 Cypress E2E, no binary-conditional skips | The rest of the trigger matrix, static analysis |
| [Docs and release](#docs-and-release) | README covers install, Docker and S3 | API reference, changelog, packaging |

The two things standing between this and a 1.0 release are **Office support** and
**release packaging**. Everything else open is a refinement — the
[PDF stack migration](#pdf-stack-migration-to-tc-lib-pdf), which was the one large piece of
scheduled rework, is complete, and so is the [Cypress E2E
suite](#integration--e2e-cypress), which was the other.

---

## What is left

Ordered by what would hurt most to ship without. Each links to the detail below.

### Feature gaps — whole SDD goals not yet built

- [ ] [Office documents](#office-documents-officewatermarker) — no renderer, no conversion
  pipeline, no MIME registration. The largest single piece of missing scope
- [ ] [Invisible metadata watermark](#open-2) — no
  service, and `metadata` is not an accepted config type
- [x] [Group overrides](#open-4) — **removed.** `group_id` was stored and validated but never
  read, so a group policy silently did nothing; the column, its index and the parameter are
  gone rather than wired up
- [x] [Per-user overrides](#open-4) — **removed.** `user_id` *was* read, but no UI ever set
  it, so it was a `curl`-only feature. The policy is now one admin-set config; the config
  endpoints are admin-only and `resolveConfig()` no longer takes a user
- [x] [Arabic in the watermark](#watermark-rendering-arabic-in-the-output) — **done.** IBM Plex
  Sans Arabic Bold (OFL) draws every watermark, subsetted into each file; Arabic is shaped,
  reordered and ligated in both PDFs and images. `{date}` localisation is the one piece open
- [x] [Arabic in the Admin UI](#admin-ui-arabic-interface) — **done.** `l10n/ar.json` +
  `l10n/ar.js` carry all **130** strings, server messages included; the preview's base
  direction is pinned to the text rather than the locale, and the app's own directional CSS
  is gone. Driving the real runtime found a **bidi bug in the renderer** — see below

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

- [ ] [Latin words are reversed inside an RTL watermark](#open-bidi) — `سري - John Doe` is
  drawn `Doe John - سري`. A UAX #9 rule N1 violation in `tc-lib-unicode`, found by comparing
  the pinned preview against the shaper. It names the wrong person, which is the one thing a
  watermark exists not to do
- [x] [PDF 1.5+ with compressed xref](#open-1) — read natively by the renderer. No
  configuration, no external binary, no host-dependent behaviour
- [x] [A page with no resources came out blank](#open-1) — a tc-lib-pdf defect that writes
  `/Resources` with **no value**, mis-pairing every entry after it. Corrected in
  `WatermarkPdfDocument`
- [ ] [Content lost on some Windows "Microsoft Print to PDF" files](#open-1) — reported from
  the field, **not reproduced**. 30+ synthetic variants of that producer's structure all
  round-trip intact; `tests/diagnose-pdf.php` is the instrument for the actual file
- [ ] [Archive caps](#open-3) are class constants, not configuration
- [ ] [Rate limiting](#open-security) for on-demand applies on large files
- [ ] [Public file-drop uploads](#open-3) are watermarked by neither the inline path nor the job
- [ ] support the selected encryption module, and add tests, and encrypt the originals using the selected module in appdata.
- [ ] support team folders.

### Test and CI gaps

- [x] [Cypress E2E](#integration--e2e-cypress) — **built.** 52 tests across 10 specs, run
  against a real Nextcloud 31, each judged on the bytes that come back rather than on the UI
  saying it worked
- [ ] [The trigger × access matrix](#manual-verification-matrix) — `on_share` is now driven
  automatically through all six cells; the other three modes still are not. Every cell of it
  has historically caught a real bug
- [ ] [`ZipInterceptorPlugin::streamNode` drift](#open-testing) against core, re-checked by hand
  on every Nextcloud upgrade
- [x] [Psalm](#open-testing) — **built.** `lib/` is type-checked against core's OCP API and
  the server stubs, clean at level 4 with no baseline, and it found three server classes
  `lib/` referenced that no stub declared. Level 3 is the open backlog

### Documentation and release

- [ ] [API reference, developer guide, changelog, packaging](#docs-and-release)

---

## 1. Renderers (Goal 1)

**Position:** PDFs and images are complete, in pure PHP. Office formats are not started.
Rasterised flattening existed for tamper resistance and was **removed** — see
[No external binaries](#no-external-binaries).

### Open {#open-1}

- [x] **A page with no resources came out blank — a library defect, corrected.** tc-lib-pdf
  assembles the imported page's Form XObject dictionary with `sprintf`, and its resource
  cloner returns an empty *string* rather than `<< >>` for a page that resolves to no
  resources. The dictionary then reads
  `/Resources /Group << … >> /Filter /FlateDecode /Length 96`: `/Resources` swallows
  `/Group`, the group dictionary is left standing where a **key** belongs, and every entry
  after it pairs with the wrong name — `/Filter` included, so a reader hands deflate bytes
  to the content interpreter and draws nothing. The page arrives blank with the watermark on
  it and every original byte still in the file
  - a page resolves to no resources when it declares none and inherits none, which is legal
    for content that names no font, image or graphics state — and is also what a
    `/Resources` object that cannot be found produces
  - corrected in `WatermarkPdfDocument` by overriding `getOutImportedObjects()`, the
    library's own hook for that block of the body. **Repairing the finished file instead
    corrupts it**: the five inserted bytes shift everything after them while the xref still
    points at the old offsets, so the document stops parsing at all. That was the first
    attempt, and re-reading the output inside the test is what caught it
  - pinned by `testAPageWithoutResourcesKeepsAUsableFormDictionary`, which asserts against
    the dictionary as a reader pairs it, not against the bytes that were written. Verified
    to fail without the override
- [ ] **Content lost on some files printed with Windows "Microsoft Print to PDF" —
  reported, not reproduced.** The report is a white page carrying only the watermark, which
  is the same signature as the two defects already fixed here (the CropBox offset, and the
  valueless `/Resources` above), so it is likely a third path to the same end rather than
  anything to do with that producer by name
  - **30+ synthetic variants of that producer's structure all round-trip intact**: object
    streams behind a cross-reference stream, `/Resources` inline / indirect / inherited /
    absent, `/Font` sub-dictionaries indirect, Type0 → CIDFontType2 → `FontFile2` chains,
    ICCBased colour spaces, images with soft masks, transparency groups, `/Contents` arrays,
    indirect `/Length`, `/Filter` as a name and as an array, CRLF and bare-CR line endings,
    `stream\r\n` against `stream\n`, and multi-page documents sharing one font object
  - `tests/diagnose-pdf.php` is what closes this: run against the real file, it reports per
    page whether the content survived the import, whether it is still readable as operators,
    and whether every resource the copied stream names is still defined. It is a bench
    instrument, not part of the app — for a file that cannot be shared
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

- [x] **Pages cropped away from the origin came out blank — fixed.** Reported from the field:
  a PDF produced by a Windows print-to-PDF driver from an image was watermarked and the result
  had no visible content. The file was intact — right page count, image bytes present, the
  watermark itself rendering perfectly — because the original content was being drawn where
  nothing could see it
  - **the cause is a coordinate-system mismatch in the import.** A new page is created at the
    source box's *size*, origin (0, 0), but the imported form keeps the source's own
    coordinates: its `/BBox` is the visible box as the source numbered it. The library's
    `addPageFromImport()` then places the form with an identity matrix, so a page cropped to
    `/CropBox [300 300 612 792]` draws its content at x≥300 on a page 312 wide. Measured on
    the fixture: **2% of the content still on the page**, and **0%** once the page was also
    rotated
  - `getMediaBox()` reports that box despite the name — it is the **CropBox** whenever the
    source has one, which is exactly the case that goes wrong
  - the correction has to be **rotated with the page**. The library builds its form matrix from
    the box's width and height and never its coordinates, so at 90° it emits
    `[0 -1 1 0 0 312]`; translating by `(-x0, -y0)` there pushes the content further off the
    page. All four orientations are handled and asserted
  - the signs are asymmetric — `useImportedPage()` measures y from the *top* and emits
    `pageHeight - y - formHeight` — which is the kind of thing that is only ever established by
    driving it, not by reading it
  - **a page whose box starts at the origin offsets by zero**, so the common case, and every
    fixture this app had before, is untouched. That is asserted rather than assumed: the two
    origin-box rows of the provider stay green under the mutation that reinstates the old
    placement
  - `testCroppedPagesKeepTheirContentOnThePage` asserts on **geometry**, because nothing
    cheaper can see this failure: it composes the form's own `/Matrix` with the placement `cm`
    and intersects the result with the page. Page count, file size and a search for the image
    bytes all pass on a blank file. Mutation-tested twice — reverting the offset fails 5 of 7
    rows, applying it without rotating it fails 3
  - **what this does not establish is that it was the reporter's file.** It reproduces the
    reported symptom exactly and is a real bug either way, but the original file has not been
    seen. Sixteen other structural variants were tried first and all rendered correctly:
    inherited `/Resources` and `/MediaBox`, object streams, compressed and array content
    streams, indirect `/Length`, nested form XObjects, DCTDecode / predictor / SMask / indexed
    / CMYK images, `/UserUnit`, transparency groups, and multi-page sources
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
  at the string it was drawing. The *default* `{username} - {date}` template overran its
  neighbour by 12px at the default font size; `Mohammed Al-Amri - 2026-07-31 14:22:05` overran
  by 312px, which is more than the whole step, so it ran through the tile beyond as well. Long
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

- [x] Text watermark with `{displayname}`, `{username}`, `{email}`, `{date}`, `{datetime}`,
  `{filename}`
- [x] **Account name and display name separated, having been conflated.** `{username}`
  resolved to `getDisplayName()`, so the token said one thing and rendered another, and the
  account name — the only identifier that is unique and stable — could not be watermarked at
  all. `{username}` is now the uid and `{displayname}` the human-readable name
  - **which one to reach for is a real decision, so the form says so** rather than leaving it
    to be inferred from two similar chips: a display name is what a person reading the
    watermark recognises, but users can change it and two accounts can share one, so a
    watermark meant to identify exactly one account needs `{username}`. The chips carry a
    label and an example each (*John Doe* against *john.doe*), and the sample values are
    deliberately unalike — equal ones would have let either resolution look correct
  - the default template is `{displayname} - {date}`, in the service and in the form both:
    watermarks are read by people, and "Alice Smith" places a leak in a way "asmith3" does not
  - **no existing watermark changed on upgrade.** `Version1004Date20260731000000` rewrites
    stored `{username}` to `{displayname}`, so what an admin already approved keeps rendering
    byte-for-byte and the account name becomes an opt-in choice. Without it every existing
    install would have switched identity silently, with nothing in the UI or the audit log to
    explain why — the app version is bumped to **1.3.0**, without which the migration would
    not run at all
  - the anonymous fallback covers *both* tokens. Missing that would leave a public-link
    download watermarked with a blank where the identity belongs, which reads as a rendering
    fault rather than as "nobody was signed in"
  - `ApiControllerTokenTest` guards the drift that this change could most easily have shipped:
    the form offers a chip per token and the server keeps its own allowlist, so a token added
    to one and not the other is a 400 in the admin's face. It asserts every offered token is
    accepted, and that a near-miss (`{displayName}`) is rejected *by name* — an error listing
    only the allowed tokens would leave an admin comparing two strings that differ by one
    capital letter
- [x] Image watermark (logo overlay), and combined text + image
- [x] Tiled diagonal placement, 45° default, configurable font size / colour / opacity /
  rotation

---

## 3. Delivery and triggers (Goal 3)

**Position:** all four triggers work, for single files and archives, across owner, internal
share, and public-link access. This is where every delivery-time bug has been found.

### Open {#open-3}

- [x] **Tar archives are broken in core.** `Accept: application/x-tar` yields a truncated
  archive, and it reproduces identically on the untouched core path, so it is not caused by
  this app. Browsers request zip. Worth an upstream report
- [x] **Archive caps are configurable** — `occ config:app:set files_watermark
  archive_max_members` / `archive_max_bytes`, defaults unchanged at 200 / 256 MiB. The two
  constants moved into `ArchiveLimits`, which the plugin reads **once per request** rather
  than per member: a ceiling must not move underneath a walk that is already half-rendered,
  and a bad value should warn once, not once per file
  - **app config rather than the policy table, deliberately.** These are host tuning — sized
    by the temp filesystem and how long a request may take — and they change nothing about the
    watermark that comes out. Putting them on the policy would have meant either two more
    fields on a form about appearance, or two more columns with no way to set them. The
    second is exactly what [group and per-user overrides](#open-4) turned out to be, and it is
    the mistake this app has already had to migrate its way out of twice
  - **there is no "unlimited".** A value below 1 is refused and the default used, with a
    warning. The cap is not a preference: it is the bound that keeps the fail-closed
    pre-render from filling the temp filesystem, and `0` reads far too much like "off" for a
    setting that would let one folder download fill a disk
  - every read degrades to the default rather than throwing, including a value an admin
    stored with `--type=string`. This runs on the delivery path, and a typo in an `occ`
    command becoming an HTTP 500 on every folder download is the shape of bug this app
    shipped once already, from a mistyped system tag
  - **the key names are pinned end to end.** The unit tests stub `IAppConfig`, so they prove
    the plugin honours whatever `ArchiveLimits` returns and prove nothing about the key an
    admin types — a rename on either side would leave them green and the setting inert.
    `06-archive-caps.cy.js` sets it with a real `occ config:app:set` against a
    three-member folder (far under the default), watches the same fetch go 200 → 403 → 200
    as the value is set and deleted, and is the only test in either suite that can see that
- [x] **Audit granularity for archives decided: one `watermark_log` row per watermarked
  member, per fetch.** Confirmed as wanted rather than changed, and now pinned by tests so it
  is not quietly batched by whoever next reads the pre-render loop
  - **a row per archive would answer the wrong question.** "bob downloaded an archive at
    14:02" cannot say which documents were in it, and tracing a leaked document back to the
    person who received it is the one thing this app exists to do. Rows are keyed by file id
    for the same reason the indicator and the double-burn guard read them that way; an
    archive-level row would need a null or synthetic file id and would break both
  - **the per-fetch cost is real and deliberate.** Delivery triggers render per fetch, so a
    second download of the same folder writes the same rows again. That is not an archive
    quirk — a single-file `on_download` logs per fetch too — and batching only the archive
    path would make it *less* informative than the single-file path it mirrors
  - **what makes it safe to confirm now is the cap.** Write amplification is bounded by
    [`archive_max_members`](#open-3) (200 by default), so one request cannot write an
    unbounded number of rows. That was an open question when the caps were class constants
  - pinned twice: `testEachWatermarkedMemberIsRenderedOnceAndSkippedMembersNotAtAll` (one
    render — therefore one row — per member, and none for a member the policy skips), and
    end to end in `05-archives.cy.js`, which reads the real audit table and asserts exactly
    three rows naming the three PDFs, none for the unwatermarkable `notes.md`, and three more
    on a second fetch
- [x] **Log growth answered, in the two places it had to be**: delivery rows are now written
  only when the policy asks for them, and `occ files_watermark:prune-log` deals with what is
  already there. Neither changes the granularity decision above — a recorded delivery is still
  one row per member
  - **the switch covers the delivery rows only, and that is the whole design.** The in-place
    rows (`on_demand`, `on_upload`, `removed`) are not history an admin may decline to keep:
    `findWatermarkedFileIds()` reads them to draw the badge and to stop a second burn on a
    file that already carries one. A switch that reached them would silently un-badge every
    file and let watermarks stack. `WatermarkLogMapper::NON_DESTRUCTIVE_TRIGGERS` is the one
    list that decides all three questions — which rows flag a file, which are optional, and
    which the prune command takes by default — because they are the same question asked three
    times
  - **it is off by default, including on upgrade**, which is a real behaviour change for an
    instance already running a delivery trigger: downloads stop being recorded until an admin
    ticks the box. Defaulting existing rows to *on* would have left every current install with
    the unbounded growth this release exists to fix, and the box is one click away
  - `Version1007Date20260801120000` adds `log_delivery`; the app version is **1.6.0**, without
    which the migration would not run. `SchemaConvergenceTest` gained the step and — the part
    that matters — its pre-1007 seed now omits the column, so the upgrade paths actually
    execute the `addColumn` instead of skipping it on `hasColumn`. Both mutations (dropping
    the guard, and the step doing nothing) fail it
  - **the prune command cannot reach the in-place rows at all** — not by default, but by
    construction: `deleteBefore()` takes no parameter for it and always emits the trigger
    clause. An earlier draft had an `--include-applied` flag whose help text warned you not
    to use it, which is a flag that should not exist; **retention shortens the history of who
    downloaded what, it never makes the app forget that a file is watermarked.** `--days`
    refuses anything that is not a positive whole number: coercing `--days=abc` to 0 would
    make a typo the most destructive form of the command
  - the form offers the switch **only for `on_download` / `on_share`**, since it does nothing
    for the others — offering it there would promise something the server will not do
  - end-to-end (`11-prune-log.cy.js`) because two things have no other witness: that the
    command is **registered** at all (it reaches `occ` through `<commands>` in `info.xml`,
    which no unit test reads — an unlisted class is a command that does not exist, with its
    unit tests still green), and that its `WHERE` clauses match the right rows in a real
    database. The last test asks for the removed flag and expects the command to **refuse**,
    with the apply row and `nc:is-watermarked` both intact afterwards — a guarantee is worth
    more when something tries to break it
- [x] **Two audit rows per download — fixed, and it was two full renders as well.** Reported
  as a doubled log entry; the log was the symptom
  - the Files app downloads by sending **HEAD then GET**, and Sabre serves a HEAD by cloning
    the request as a GET and re-dispatching it (`CorePlugin::httpHead`). Both arrived at the
    interceptors as ordinary downloads, so one click rendered the whole watermarked file
    twice and recorded it twice
  - `X-Sabre-Original-Method: HEAD` is the marker Sabre leaves on the clone, and the only
    thing that tells the two apart. Both interceptors now defer those to core
  - **the log was the cheap half of the bug.** A HEAD carries no body, so the render was pure
    waste — and on a folder, a HEAD would have built the entire archive. Skipping the render
    is the fix; not logging it would have left the cost in place and still passed a "one row"
    test, which is why the E2E asserts a HEAD **on its own** adds nothing
  - deferring also makes the headers consistent: PROPFIND already reports the stored file's
    size rather than the watermarked copy's, so a HEAD that answered with the render's length
    was the odd one out
  - measured before and after by driving the real Files UI: **2 rows → 1**
- [ ] Extend `DownloadController` (`/api/v1/download`) to accept a folder path, or document
  that it stays single-file only (it currently answers "Path is not a file")
- [ ] **Public file-drop uploads** have no session to attribute a watermark to, so neither the
  inline path nor the job covers them. Not a confidentiality leak — the dropper is
  watermarking their own upload — but on-upload does not cover them. The open decision is
  whether to attribute the burn to the **share owner** (the only identity available at drop
  time, and the one the identity tokens would then render), or to leave file-drop out of scope and
  say so in the README. Doing neither is what makes it a gap
- [x] **The three missing archive unit tests are written** — and one of the three turned out
  to be half-written already, which is why the item is worth reading rather than ticking:
  - **the handler claims only `Directory` + archive-accepting GETs.** It sits on `method:GET`
    ahead of core, so *every* GET on the server passes through it and anything it claims by
    mistake is a request core never gets to answer. Now driven by a provider: `application/zip`
    and `application/x-tar` claimed along with core's own `zip` / `tar` shorthands, a browser
    page load and a header-less GET not — each negative row set up with a member that **would**
    have been substituted, so "not claimed" cannot pass merely because there was no work to do.
    Plus a GET on a *file* (that is `DownloadInterceptorPlugin`'s) and an unresolvable path
    (that is core's 404), both left alone
  - **tar member size.** A test of this name existed and drove **zip**, which is the one format
    that cannot fail it: `ZipStreamer` derives the size while streaming, `TarStreamer` writes it
    into the member header before the bytes. It is now parameterised over both, and asserts the
    other half too — that a member nobody rendered declares *its own* size rather than the last
    temp copy's
  - **over-cap `on_share` denies / `on_download` defers.** Already covered for the byte cap in
    both directions, and for the member cap under `on_share`. What was genuinely missing was
    the member cap **degrading**, and it is not symmetrical with the byte cap: the byte cap
    trips on `getSize()` before anything is rendered, the member cap trips *mid-render* with
    temp copies already on disk. The test asserts they are cleaned up, since otherwise a
    best-effort download leaves 200 plaintext copies of user content in the temp dir
  - all six are **mutation-tested**: dropping the `DavDirectory` guard, dropping the
    archive-type guard, declaring the original size for a substituted member, declaring a temp
    size for an untouched one, skipping the cleanup on the degrade path, and always building a
    zip each fail exactly the rows that name them
- [x] **Archive scenarios automated** — folder and multi-select ZIPs, on both DAV servers,
  with every member unpacked and probed. See [Integration / E2E](#integration--e2e-cypress)

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
    explicitly; otherwise the identity tokens render "Unknown" and the audit row says "system"
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
badge are all built. Both scoping features the SDD described — group overrides and per-user
overrides — have been **removed** rather than finished: the policy is one config, set by an
admin, in force server-wide. Nothing here is stored without being honoured.

### Open {#open-4}

- [x] **Group overrides removed, rather than implemented.** `group_id` was accepted by
  `saveConfig`, validated, indexed and stored, while `WatermarkService` resolved
  user → global → default and never read it; the mapper had no `findByGroup` and no UI ever
  set it. A group policy did nothing at all, which is worse than not offering one — an admin
  who stored one saw it persist and would reasonably conclude it was in force
  - dropped on the same reasoning that removed the flattening columns: a stored setting no
    code consults is a setting being quietly ignored. Per-user overrides went the same
    way immediately after (see below), so what is left is one policy: **global → default**
  - `Version1005Date20260731140000` drops the column and its `wm_config_group_idx` index for
    instances that already applied 1003, which no longer creates either. Both halves are
    needed: 1003 will not re-run on an instance that has recorded it, and an index left over
    a dropped column is DDL the database platforms disagree about
  - `SchemaConvergenceTest` gained the **applied 1003** starting state and now drives both
    schema steps, so all four states still converge on one column list. `FakeTable` had to
    learn indexes for it — it discarded them before, which would have let the index drop go
    unnoticed
  - the app version is bumped to **1.5.0**; without it the migration would not run at all

- [x] **Per-user overrides removed too — the policy is now one config, set by an admin.**
  `user_id` was the other half of the same idea and was genuinely read: `resolveConfig()`
  looked for the acting user's row first and fell back to the global one. What it never had
  was a way in. No personal settings section was ever registered, and no Vue component set
  the field, so the only way to create an override was a direct API call — a feature reachable
  by `curl` and nothing else
  - **the resolution signature is where the change is real.** `resolveConfig()` takes no user
    id at all now, so every trigger and access path resolves the same policy and there is no
    "whose config is this" question left to answer differently in four places. The
    per-request memo went from an array keyed by uid to one slot
  - `saveConfig`, `getConfig` and `deleteConfig` are **admin-only**; `NoAdminRequired` is
    gone from all three, with an explicit `isAdmin()` check in each so a direct call is
    refused the same way the routing layer would. The check runs *before* validation, so a
    non-admin's 403 says nothing about whether the value would have been accepted.
    `applyWatermark` / `removeWatermark` / `getWatermarkedStatus` stay open to ordinary users
    — applying a watermark on demand is not configuring the policy
  - **`Version1006Date20260731160000` deletes the per-user rows before dropping the column,
    and that ordering is the whole point of the migration.** Dropping first would leave those
    rows in the table as ordinary ones, indistinguishable from the global config — and
    `findGlobal()` takes the first row it finds, so a former per-user override could silently
    become the server-wide policy. The delete therefore lives in `preSchemaChange`, which is
    the only hook that runs while the column still exists
  - this is the one migration in the app that **destroys live data**: an install that had
    per-user policies loses them, and those users fall back to the global one. That is the
    requested behaviour, not a side effect, but it is a change in what their files get
    watermarked with
  - `ApiControllerImageTest` had to split: the arbitrary-server-path rejection is now
    asserted as an admin, since that is the only role that reaches the validation, with a
    separate case pinning the 403 for everyone else. The path check stays server-side
    regardless of who can call it

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
    `LoadAdditionalScriptsListener` resolves the effective trigger (global → default)
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

**Position: both halves are done.** Arabic is shaped, reordered and drawn correctly in PDFs
and images with an OFL face bundled, and the interface is translated, RTL-clean, and pinned to
one preview direction. What is left is `{date}` localisation, a run on a real Arabic instance,
and **a renderer bidi bug that only became visible once the two halves could be compared**.

Two halves that share only the tokens they render, and they were worth keeping apart. The UI
half was ordinary Nextcloud translation work with a known shape. The watermark half was a
text-shaping problem that reaches into the font stack, and it is the one that can *look*
finished while being wrong: Arabic drawn as disconnected left-to-right letters is still a
valid PDF with a valid overlay, and every existing assertion would stay green.

The order mattered, and doing the watermark half first paid off exactly as expected. The
settings live preview is rendered by a browser — which shapes and reorders Arabic correctly —
so shipping the UI translation first would have created a preview promising output the
renderers could not produce. Having both, the preview could be pinned *against* the renderer's
own rule rather than against the browser's default, and the one place where the two still
disagree is now a recorded renderer bug rather than an unnoticed difference. That is the same
trap as the rotation convention, where the preview is the contract and the renderer had to be
made to match it.

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

**Position: the watermark half is done**, and so is the [Admin UI](#admin-ui-arabic-interface)
below. Arabic is shaped, reordered and drawn correctly in both PDFs and images, with a bundled
OFL face. What is left here is the `{date}` locale question — plus the
[bidi bug](#open-bidi) that comparing the two halves brought to light.

- [x] **Shaping strategy decided, and it cost nothing: the library already shapes.** TCPDF
  did it (`utf8Bidi()`), and tc-lib-pdf is the same author's rewrite — the machinery sits in
  `tecnickcom/tc-lib-unicode` and `tc-lib-unicode-data`, both already installed as transitive
  dependencies and already in `Application::RUNTIME_VENDOR_PACKAGES`. Nothing new to require
  - established **by rendering, not by reading**, as the plan insisted. `الاختبار` through
    `Com\Tecnick\Unicode\Bidi`: 8 code points in, **7 glyphs out, every one in Arabic
    Presentation Forms-B, one of them a lam-alef ligature** — which is where the eighth went —
    and the last source letter drawn first, so the reordering is real too
  - the same string through `getTextCell()` emits `(fead fe8e fe92 fe98 fea7 fefb fedf) Tj`,
    which is that shaper output exactly. So **the PDF path must never pre-shape**: it would
    put an already-visual string through reordering a second time
  - the image path *does* shape, through the same library. One shaper, one result, no
    dependence on whether the host's ImageMagick was built against Raqm/HarfBuzz — which is
    the "app shapes once" option, arrived at for free
- [x] **IBM Plex Sans Arabic Bold bundled (SIL OFL), and it draws *every* watermark** — Latin
  and Arabic alike, so the same configuration produces the same typeface whatever the text
  contains. An earlier round kept Latin on standard-14 Helvetica and embedded a second face
  only when needed, which was cheaper but meant a watermark changed appearance depending on
  whether someone's display name happened to be Arabic
  - **the face was chosen by measurement across ten OFL candidates, and the binding
    constraint is not looks.** The shaper substitutes into **Arabic Presentation Forms-B**
    (U+FE70–FEFF), so a font omitting that block draws nothing for shaped Arabic however
    complete its `U+0600` coverage looks. Most modern faces omit it because they shape
    through OpenType `GSUB` instead
  - **Cairo was proposed and ruled out on evidence**: it lacks 14 forms everyday Arabic
    produces, including U+FEAD (final reh), U+FE8D (isolated alef) and U+FEED (isolated waw).
    Tajawal, Almarai, Markazi Text and Readex Pro fail the same way; the SIL Naskh faces
    (Scheherazade New, Lateef, Harmattan) miss 64 forms apiece. Noto Naskh Arabic has the
    Arabic but only **15 printable ASCII** — no Latin letters, no hyphen — so
    `{displayname} - {date}` would lose its separator and any Latin name with it
  - three covered both scripts completely. IBM Plex Sans Arabic has the smallest font program
    of them (**103 KB** against Noto Kufi's 210 KB and Noto Sans Arabic's 445 KB) and is a
    sans, which is the register a watermark wants
  - the licence question the Helvetica metrics left open is answered for this face: OFL, with
    `IBMPlexSansArabic-OFL.txt` committed beside it. The Helvetica 0-byte-`LICENSE` gap is
    unchanged and still recorded, but nothing shipped to a user is drawn with it any more —
    those metrics now serve the *test fixtures* alone
  - `php-cs-fixer` still leaves `resources/fonts` alone, confirmed against what the generator
    emitted
- [x] **Subsetting is what makes one face affordable.** `subsetfont: true` embeds only the
  glyphs actually drawn, and a watermark repeats one short string: measured **31 KB against
  125 KB** for the whole face, on paths that render per fetch. It is also *faster* — 0.025s
  against 0.071s — despite the library's docblock calling the option "computational and
  memory intensive", which is worth having measured rather than believed
  - `/ToUnicode` is still written, so the watermark stays searchable and selectable. Without
    it the "text layer survives" promise would have quietly become false for every file
  - pinned by the six-letter subset tag PDF requires on the font name
    (`AAAAAB+IBMPlexSansArabic-Bold`), which is definitive where a file-size bound is only
    suggestive. Mutation-tested: turning subsetting off fails it
  - one consequence worth knowing: **Latin text is no longer written as ASCII** in the content
    stream, because the embedded face is a Unicode font with two-byte code units. Three tests
    that grepped for literal `Alice` / `Confidential` had to learn that
- [x] **`measure()` needed no change.** It already measured through `getTextCell()`, which
  shapes internally, so the width it reports is the shaped one — 62.1pt for the probe's seven
  glyphs, against the meaningless 44.5pt it reported for eight `?` characters before. The font
  is inserted before the measurement, which is what makes that true
  - `tilePositions()` untouched, as instructed, and its assertions never moved
- [x] **The image path shapes, and draws with the same bundled face.** `findSystemFont()` is
  gone: its name list — DejaVu, Liberation, macOS Arial — could not express "has the glyphs
  this string needs", and two of those three carry no Arabic at all, so image output depended
  on what the host had installed. GD's bitmap-font fallback went with it, since a missing
  bundled font is a broken install rather than a routine condition
  - **the font is not committed twice.** `ibmplexsansarabicb.z` is the font program the PDF
    embeds and is the original TTF under zlib, verified byte for byte against upstream;
    `ShapedText::bundledFontPath()` inflates it to a temp file cached by content hash, since
    GD and ImageMagick draw through FreeType and need bytes on disk. The glyphs in a JPEG are
    therefore provably the ones in a PDF
- [x] **Failure mode decided: refuse, never draw something unreadable.** If the text needs the
  bundled font and it cannot be read, the renderer throws and the file takes the app's existing
  honest-failure path — skip plus an audit row for the in-place triggers, deny for `on_share`,
  a named error for an on-demand apply. The alternatives were the bitmap fallback (mojibake) or
  a Latin TTF (a row of empty boxes), and both produce a *valid image file that no one can
  read*, which is the outcome the plan said to rule out
- [x] **Arabic round-trips `saveConfig` byte for byte**, pinned by
  `ApiControllerTokenTest::testAnArabicTemplateRoundTripsUnchanged` — the token check runs a
  regex over the template, and one that was not UTF-8-aware would mangle or reject it, which
  would surface as a rendering bug rather than a validation one
  - still unverified: **4-byte UTF-8 storage on MySQL**. Arabic itself is 2-byte, so this is
    not blocking it, but it belongs with the cross-database run under [Data model](#open-data)
- [x] **Tests assert shape, not validity** — the whole point, since unshaped Arabic produces a
  perfectly valid file of the right size and page count
  - `ShapedTextTest` (9) pins the transform precisely: every glyph in Presentation Forms-B,
    8 code points becoming 7, the lam-alef ligature present, the last source letter drawn
    first, Latin and Latin-1 left untouched and *not* dragging in the embedded font
  - `PdfWatermarkerTest` reads the glyph codes back out of the emitted `Tj` operand and
    compares them to the shaper's output. Mutation-tested: forcing Helvetica fails it
  - the image test could not do that — an image carries no text — so it uses shaping's own
    non-idempotency as a discriminator. A second pass reads visual order as logical and
    reverses it, a third puts it back, so `x` and `shape(shape(x))` are **different strings
    that shape identically**: a renderer that shapes draws them the same, one that draws what
    it is handed cannot. The first version of this test compared raw against shaped output and
    was **vacuous** — it passed with shaping deleted, which is exactly how it was caught
- [ ] **`{date}` / `{datetime}` are still locale-free** — `date('Y-m-d')` in
  `WatermarkService::buildPlaceholders()`: ASCII digits, Gregorian, server timezone. For an
  Arabic deployment, decide whether to offer Arabic-Indic digits (`٠١٢٣`) and/or a Hijri date,
  and whether that follows the *viewer's* locale or a config field. IBM Plex Sans Arabic carries both
  digit sets, so the font is not the obstacle
  - this is a real trade-off rather than cosmetics: the watermark is traceability evidence, and
    a date that renders differently depending on who fetched the file is harder to reason about
    in an audit
- [ ] **Drive a real Arabic instance.** Everything above is asserted against generated
  fixtures and rendered output read back byte by byte, and the interface half against the real
  `@nextcloud/l10n` runtime driven directly — but neither has been through the Files
  app with an Arabic display name and an Arabic template on a real server

### Admin UI (Arabic interface) {#admin-ui-arabic-interface}

**Done.** `l10n/ar.json` and `l10n/ar.js` ship **130 strings** — every one the app asks for,
server messages included — the interface no longer fights `dir="rtl"`, and the live preview's
base direction is pinned to the rule the renderer uses rather than to the viewer's locale.

- [x] **`l10n/ar.json` + `l10n/ar.js`, generated from one table** so the two cannot disagree.
  Nextcloud reads the JSON from PHP and the JS from the browser, and nothing in the platform
  checks that they match — a string fixed in one and not the other gives a settings page whose
  server errors and interface are in different languages
- [x] **The audit for unwrapped strings found four**, all in `WatermarkForm.vue`: the
  `PLACEHOLDERS` sample values, and `LOGO`, which went through `t()` on one branch and not the
  other so the word changed when an admin uploaded a logo. `TYPE_OPTIONS`, `TRIGGER_OPTIONS`,
  the MIME labels and `AuditLog.vue`'s headers were already wrapped
  - **the samples are translated on purpose**, and it is the one entry here that is a decision
    rather than a chore. `John Doe` in a Latin face tells an Arabic deployment nothing about
    its own watermarks, so `ar` renders `صالح المقوشي` / `s.almuqwashi` / `مستند.pdf` — which puts
    real Arabic through the preview's shaping and direction handling the moment the page opens
  - the help text that names those samples now **takes them as parameters** instead of
    repeating them, because a sentence quoting `(John Doe)` beside a chip tooltip reading
    `صالح المقوشي` is two translations of one fact, drifting independently
  - the `{displayname} - {date}` field placeholder went the other way: **un-wrapped**. The
    tokens are identifiers the server matches literally, so a translated copy would offer an
    admin a template that comes straight back as a 400. It reads from `DEFAULTS` now
- [x] **Server-side strings translated at every boundary the UI shows verbatim** —
  `ApiController` (21 messages), `WatermarkImageStore` (4), `WatermarkService` (4). Each took
  an `IL10N`, and the interpolated variables became `%s` / `%1$s` parameters, which is the part
  worth testing: `System tag ID "%s" does not exist` translated *without* its `%s` still reads
  as a fluent Arabic sentence, one that has quietly dropped the only thing telling the admin
  which tag is wrong
  - `t()` is registered explicitly with `Util::addTranslations()` beside both `addScript()`
    calls rather than left to whatever the server does implicitly. A missing bundle is not an
    error — every string simply falls back to English, which looks exactly like a working
    translation
- [x] **`info.xml` metadata translated** (`<name>`, `<summary>`, `<description>`), since the
  apps list and the App Store read those and `l10n/` does not cover them. Structure checked
  against the published `info.xsd`: all three are `maxOccurs="unbounded"` with a unique `@lang`
- [x] **The app's first `n()` call, and Arabic is why.** `Estimated processing time: ~{n}
  second(s)` was an English-only shortcut: Arabic has six plural forms and inflects the noun
  differently in each, so "ثانية واحدة" / "ثانيتين" / "3 ثوانٍ" are not one string with a
  number swapped in
  - **verified by driving `@nextcloud/l10n` itself**, not by reading the file: registered the
    catalogue, called `setLanguage('ar')`, and checked all six forms select correctly at
    n = 0, 1, 2, 3, 11, 47, 100. The library takes the Arabic rule from **its own table**, not
    from the `pluralForm` line in the JSON — the line still has to be right, because the PHP
    side does read it, but a file that looked correct could have been selected wrongly and
    this is the only way to see which
  - two of the six forms deliberately **omit `%n`**: Arabic's singular and dual carry the count
    in the noun, so "نحو 2 ثانيتين" is a mistake rather than a translation. The catalogue test
    encodes exactly that — forms may drop a placeholder the grammar supplies, never introduce
    one the source cannot fill, and forms 3-5 must keep their count
- [x] **RTL: the app's own directional CSS is gone.** `text-align: left` on the log headers
  became `start`, and the status-toast keyframe — a `translateX(-4px)` a keyframe cannot express
  logically — got a mirrored twin selected by `[dir="rtl"]`
  - two rules that were *silently wrong rather than mirrored*: `letter-spacing` on the uppercase
    labels pulls Arabic's joined letters apart, and `text-transform: uppercase` means nothing in
    a script with no case. Both are undone under RTL instead of left to apply harmlessly
  - **file paths are pinned `dir="ltr"`**, which is a bug fix, not styling: a path is
    structurally LTR, and its leading slash is a neutral that an RTL paragraph renders at the
    far end — `/Documents/تقرير.pdf` reads as though the file were named backwards
- [x] **The tag picker's dropdown was broken in Arabic, in two ways, and both are in
  `@nextcloud/vue` / `vue-select` rather than here.** Reported from the field — "the list is
  not shown correctly" — and both are fixed from this app with two rules in the one **unscoped**
  style block in `WatermarkForm.vue`. Unscoped is not laziness: `NcSelect` renders with
  `appendToBody` (true by default), so the list is a child of `<body>` carrying none of the
  component's scope attributes, and a scoped rule cannot reach it at all
  - **the list appeared at the edge of the window instead of under the field.** An
    over-constrained absolute box: `.vs__dropdown-menu--floating` sets `inset-inline-start: 0`
    as a fallback, which is `right: 0` under RTL, while floating-ui writes the real position as
    an inline `left` *and* an inline `width`. With `left`, `right` and `width` all non-auto the
    box is over-constrained, and CSS 2.2 §10.3.7 discards `left` when the direction is RTL — so
    the computed position is thrown away. In LTR the same fallback resolves to `left: 0` and the
    inline `left` simply overrides it, which is why this could not be seen until the interface
    was Arabic
  - **the option labels were left-aligned inside a right-to-left list.** vue-select does ship an
    RTL block, but it is written `.v-select[dir=rtl] .vs__dropdown-menu` and cannot match for two
    independent reasons: the component renders `dir="auto"` on its root, not `dir="rtl"`, and the
    menu is outside `.v-select` entirely once appended to the body. The unconditional base rule,
    `text-align: left`, is what applies
  - **measured in Chrome against the app's own built stylesheet**, with the markup `NcSelect`
    actually produces, because this is a layout claim and nothing cheaper can see it: a field at
    x=400 had its list at **x=506, the window edge, and `text-align: left`**; after the two rules,
    **x=400 and `text-align: start`**, with LTR unchanged at x=400 throughout
  - not fixed, and not worth it: `.vs__actions` keeps its LTR padding under RTL (`0 6px 0 3px`
    against the `0 3px 0 6px` the unreachable RTL block intended), a 3px asymmetry on the
    clear/expand buttons
- [x] **The preview's direction is decided by the text, never by the locale** — and the rule is
  not a style choice, it is the shaper's. `Com\Tecnick\Unicode\Bidi` resolves the paragraph
  direction from the first strong character of the string it is handed, so the preview computes
  the same thing from the first strong character of the template
  - letting it inherit the page's `dir` would make one stored template preview two different
    ways depending on who opened settings, and only one of the two could be what the renderer
    produces
  - the page mock-up around it stays `dir="ltr"`: the faux content lines and the logo box are a
    picture of a document, not interface text, and a mirrored page is not one any renderer emits
- [x] **The rotation contract survives the reversal.** `patternTransform="rotate(-45)"` is
  identical for a Latin and an Arabic template, asserted directly, and it should be: what
  reverses is the glyph order *inside* the line, not the line the tiles are laid along
- [x] **Two drift guards, both mutation-tested.** `L10nCatalogueTest` extracts every `t()` /
  `n()` source string from `lib/` and `src/` the same way Nextcloud's own extractor does and
  compares it with the catalogue in both directions — a missing translation is otherwise
  invisible, because it falls back to English and looks like success
  - it caught a bug in itself first: the test's app root resolved *through* `tests/`, so its
    "skip test files" filter matched the whole source tree and it compared the catalogue against
    an empty list. It passed. The reverse check — stale translations no source asks for — is
    what failed and exposed it, which is the argument for having both directions
  - a `t()` built by **string concatenation is invisible to every extractor**, including
    Nextcloud's, so the one place doing it (the system-tag message) was joined into a single
    literal. Nothing in the tooling would have reported the missing translation
- [x] **Version bumped to 1.4.0.** No migration needs it, unlike the previous two bumps —
  Nextcloud cache-busts an app's JS, CSS and translation bundles by app version, so without it
  an upgraded instance can keep serving the pre-translation bundle it already cached
- [x] **Jest: 85 tests, up from 74.** The existing English assertions survive unchanged — the
  test mock returns source strings — and the new ones pin the preview direction (six cases,
  including that it ignores `dir="rtl"` on the document), the LTR file path, and the plural

### Found while doing this: the renderer reverses Latin words in an RTL line {#open-bidi}

Comparing the preview against the shaper — the thing the pinned direction makes possible — turned
up a real bug in `tc-lib-unicode`'s Bidi implementation, in the app's dependency rather than in
its own code.

**In a right-to-left paragraph, each space-separated Latin word is placed as its own run, so a
multi-word Latin name comes out backwards.** Measured through `ShapedText::shape()`:

| Template | Drawn as | Correct? |
| --- | --- | --- |
| `سري - John Doe` | `Doe John - ﻱﺮﺳ` | **no** — the name is reversed |
| `سري John` | `John ﻱﺮﺳ` | yes |
| `John سري Doe` | `John ﻱﺮﺳ Doe` | yes (LTR base) |
| `John Doe - سري` | `John Doe - ﻱﺮﺳ` | yes (LTR base) |

Per UAX #9 rule N1 a neutral between two same-direction characters takes that direction, so the
space inside `John Doe` is L and the name is one run. The library resolves it to the paragraph
direction instead, splitting the name in two and ordering the halves right to left.

- [ ] **It bites exactly the default template with an Arabic prefix** — `سري - {displayname}`
  with a two-word display name — which is a plausible thing for an Arabic deployment to
  configure, and the watermark then names the person backwards. A watermark exists to identify
  someone, so this is not cosmetic
- [ ] The browser gets it right, so **the preview and the output now disagree** for this one
  shape. That is by design of the pinning: the preview is the contract, and a disagreement is a
  renderer bug rather than something to paper over by making the preview wrong too
- [ ] Untouched here deliberately: the fix is in a vendored dependency, and this task was the
  interface. Worth an upstream report, with a pre-pass in `ShapedText` (bidi-run detection
  before handing the string over) as the local option if upstream is slow

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

- [x] **Zero binary-conditional skips.** 258 PHPUnit tests, none of them needing a binary. The
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

**Position:** the schema carries every implemented feature. One column is stored but never
read, and one SDD type is still missing.

### Open {#open-data}

- [ ] Verify the migrations run cleanly on **MySQL, PostgreSQL and SQLite**. They use the
  portable schema builder and run on SQLite; a cross-DB run has not been done
- [ ] `metadata` is not an accepted `type` — needs both `VALID_TYPES` and a migration
- [ ] **One dead column left.** `position` is accepted, validated and stored, and then never
  read by anything: text is always tiled and images always centred, which matches the UI copy,
  so the column encodes a choice the renderers do not offer. It looks supported from the
  outside. Either wire it up or remove it, as `group_id` was
- [x] **`group_id` and `user_id` dropped**, with the group- and per-user-override features
  they scoped — see [Admin UI](#open-4). `watermark_config` now holds exactly one row: the
  server-wide policy. (`watermark_log.user_id` is untouched — that one records who acted.)
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
- [x] `WatermarkConfigMapper` — `findAll`, `findGlobal`, `findById`, `hasDeliveryTrigger`.
  `findByUser` and `findByUserAndMimeType` are gone with per-user policies; the latter had
  no caller even before that
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

**Position:** 448 PHPUnit tests, 91 Jest tests and 63 Cypress tests, and **no test depends on
an external binary** any more. Two image cases still depend on the host's own PHP build (WebP,
and a TrueType font for rotation). The DAV layer, which used to be the blind spot every
delivery bug hid in, now has 64 unit tests — and, as of this version, an end-to-end suite that
fetches the same files through the same servers a browser does.

### Open {#open-testing}

- [x] **Cypress E2E — built and green.** See
  [Integration / E2E](#integration--e2e-cypress) for what it covers and what it deliberately
  does not
- [ ] `ZipInterceptorPlugin::streamNode` duplicates core's, and the stubs cannot catch it
  drifting from `ZipFolderPlugin`. Re-diff against core on every Nextcloud upgrade
- [x] **Psalm — built.** `psalm.xml`, `composer psalm`, a job in `.github/workflows/php.yml`
  and a stage in the `Jenkinsfile`. `lib/` is clean at **errorLevel 4 with no baseline**; the
  configuration is commented in `psalm.xml`. What it does and does not settle:
  - `nextcloud/ocp` supplies the *typed* OCP interfaces, so `lib/` is now checked against
    core's declared API rather than against nothing. It ships no autoload section, hence the
    `<extraFiles>` entry pointing at the directory
  - the DAV stubs are fed in as `<stubs>`, the same `tests/stubs/CoreStubs.php` and
    `tests/bootstrap.php` the test suite loads — one source of truth rather than a second set
    of stubs free to drift from the first. They must be `<stubs>` and not `<extraFiles>`:
    every declaration in them is behind an `if (!class_exists(...))` guard, and Psalm's
    scanner only registers conditional declarations from stub files. As extraFiles they scan
    to nothing and every symbol silently comes back undefined
  - this still does **not** check the stubs against core — only a diff against a real server
    does, see the entry below. What it checks is that `lib/Dav/` matches the stubs, which no
    tool did before
  - it found real gaps on the first run: `SabrePluginAddEvent`, `LoadAdditionalScriptsEvent`
    and `OC\Hooks\Emitter` were referenced by `lib/` and declared by nothing, which is why
    `SabrePluginAddListener`'s `@template-implements` had been quietly meaningless. The two
    events are now transcribed into `CoreStubs.php` from 31.0.14 like the rest
  - `doctrine/dbal` (^3.9, the line Nextcloud 31 ships) joined require-dev so the migrations'
    `ISchemaWrapper` docblocks resolve to real `Schema` / `Table` types
  - two suppressions, both commented in `psalm.xml`: `MissingOverrideAttribute` (`#[\Override]`
    is PHP 8.3 and the floor is 8.2) and `UndefinedClass` in `ImageWatermarker` alone
    (ext-imagick is optional and has no stub package, so it reads as undefined on a host
    without it — both CI runners load it, so that branch *is* analysed there)
- [ ] **Psalm level 3: 39 findings, a real backlog.** 29 are in `ImageWatermarker` —
  `imagecreatefrom*()` and `imagecolorallocate()` returns are `false` / `null` on failure and
  are passed straight on. The rest: `WatermarkImageStore` (4), `ShapedText` (2),
  `ZipInterceptorPlugin` (2), `WatermarkService` (1), `UploadWatermarkPlugin` (1). Deliberately
  not baselined — a baseline would turn the gate green with all 39 still in place
- [ ] `ApiControllerTest` gaps: `deleteConfig` and `getLog` have no tests. `getConfig` and
  `saveConfig` are covered for the scope paths only
- [ ] `WatermarkOnUploadJobTest` — an unknown user and a deleted file must be skipped rather
  than fatal, and the acting user must reach `watermarkInPlace()`
- [ ] `OfficeWatermarkerTest`, `MetadataWatermarkerTest` — pending those services
- [x] **Arabic text cases in `PdfWatermarkerTest` and `ImageWatermarkerTest`** — done with the
  watermark half, asserting glyph order and shaping rather than "a valid file came out", which
  is the failure mode every other assertion accepts. See [Arabic and RTL support](#open-arabic)
- [ ] **No Jest coverage of a *loaded* Arabic locale.** The mock returns source strings, so the
  suite proves the calls are wired, never that the catalogue renders — that gap is covered
  instead by `L10nCatalogueTest` and by driving `@nextcloud/l10n` directly
- [ ] `WatermarkConfigMapperTest` — mapper finders and insert/update (see
  [Data model](#open-data))

### DAV plugin test harness

`lib/Dav/` has 64 unit tests under `tests/Unit/Dav/`. This was the priority gap, because
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
- [x] `ZipInterceptorPluginTest` (32) — **regression: gate per member, never per container**;
  a configured cap moving the ceiling in both directions; one render — and so one audit
  row — per watermarked member; a Sabre HEAD sub-request left to core rather than building the
  whole archive for a bodiless response;
  archive naming and root path for whole-folder vs selection; `files=` + `X-NC-Files` parsing;
  `BeforeZipCreatedEvent` veto honoured; over-cap → 403 under `on_share` but plain archive
  under `on_download`, for both the byte cap and the member cap; defer to core when nothing was
  substituted; **what the handler claims** (archive-accepting GETs on a `Directory`, and
  nothing else); and each member declaring the size of the bytes it actually carries, asserted
  under tar as well as zip
  - the per-member gate was **mutation-tested**: reinstating the old container gate makes the
    regression test fail, so the guard is real rather than merely green. So were the six
    behaviours added later — see the archive-unit-test note under
    [Delivery and triggers](#open-3)
- [x] `UploadWatermarkPluginTest` (12) — **regression: `afterMethod:MOVE` is hooked**, since
  chunked uploads never PUT their final path and a PUT-only hook silently skips every large
  file; job removed only on success and left queued on failure; no session, wrong trigger,
  unsupported MIME and unresolvable config all no-op
- [x] `DownloadInterceptorPluginTest` (11) — `on_download` streams a copy; `on_share` denies
  (403) when a render fails rather than serving the original; owner fetch untouched;
  `$publicContext` forces share treatment; hooks `method:GET` and never `beforeMethod:GET`;
  and **a Sabre HEAD sub-request left to core**, with a real GET as the control
- [x] `PropFindPluginTest` (9) — `is-watermarked` for file nodes only; a folder listing costs a
  constant two queries rather than one per child

### Unit (PHPUnit)

Counts below are PHPUnit *test cases*, so a data-provider-driven test contributes one per row —
which is why `PdfWatermarkerTest` reads 20 against 8 test methods.

- [x] `WatermarkServiceTest` (44) — config resolution (user / global / default), renderer
  delegation per MIME type, skip / filter / already-watermarked paths, audit row written after
  the write lands, explicit `?IUser $actor` overriding the session, `deliveryTriggerFor()` per
  node, and an unusable stored folder tag degrading
  instead of crashing
  - the **group** resolution case is absent because group resolution does not exist
- [x] `PdfWatermarkerTest` (35) — Arabic drawn as shaped glyphs, one embedded face for every watermark, the subset tag present, text / image / combined overlays, multi-page, corrupt PDF, a
  compressed-xref PDF 1.5 watermarked with no external binary, the fixture still structurally
  reproducing that case, encrypted PDFs refused cleanly with **both** a real and an empty user
  password (fixtures built with the renderer's own encryption, so no `exec()`), the rotation
  convention tilting the text uphill to match the settings preview, and the tile geometry: no
  overlap at any rotation, a lattice spanning the whole page, and off-page tiles keeping their
  negative offsets (the regression test for the smear, verified to fail against the old
  placement code)
- [x] `SchemaConvergenceTest` (9) — the schema arrives in **two** steps (1003 builds it, 1005
  drops `group_id`), which between them meet four different starting states (fresh,
  applied-1000, applied-1000-and-1001, applied-1003) and have to land all of them on identical
  columns. Also: the flattening columns dropped on upgrade, `group_id` and its index dropped on
  upgrade and skipped on a fresh install, and running twice changing nothing
  - Doctrine is not a dependency of this app, so the schema objects are fakes. What is under
    test is the migrations' branching, not Doctrine's DDL
  - `FakeTable` records **index names** as well as columns, which it did not have to before.
    Dropping a column whose index survives is invalid DDL on some platforms and invisible to a
    fake that discards indexes, so the group-column drop would have looked correct while
    leaving `wm_config_group_idx` behind
  - **the fake had to be made stricter to be worth anything.** Its `createTable()` first
    replaced an existing table silently; a mutation removing the migration's `hasTable()`
    guard passed every test, because the recreated table happened to have the right columns.
    It now throws like Doctrine's `TableExistsException`, and that mutation fails
- [x] `ShapedTextTest` (9) — the shaping transform: presentation forms, the lam-alef ligature,
  visual reordering, Latin and Latin-1 left alone, and the bundled font inflating to a
  FreeType-usable TrueType file
- [x] `LegacyImagePathCleanupTest` (2) — which `image_path` rows the migration clears, and
  that no write is issued when every stored reference is valid
- [x] `ArchiveLimitsTest` (6) — the shipped defaults when nothing is configured, a configured
  value used, the keys read under the app's own id, a value below 1 refused with a warning,
  and a value stored with the wrong type degrading to the default instead of throwing on the
  delivery path
  - the caps were mutation-tested against the plugin, and **one of the new tests failed the
    check**: the lowered-byte-cap case passed with the configured value ignored, because with
    no render stubbed the plugin was deferring to core for having nothing to substitute rather
    than for being over the cap. It now stubs the render and asserts the control — same folder,
    default cap, archive claimed — which is the version the mutation kills
- [x] `ImageWatermarkerTest` (29) — JPEG / PNG / WEBP output, opacity, rotation, **tiles never
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
- [x] `ApiControllerTokenTest` (9) — every token the settings form offers is accepted by
  `saveConfig`, both identity tokens work together in one template, and a near-miss token is
  rejected *by name*. This is a drift guard: the form and the server keep separate lists
- [x] `UsernameTokenRewriteTest` (3) — which templates `Version1004Date20260731000000`
  rewrites, that **every** occurrence in a template is rewritten rather than the first, and
  that an instance with nothing to migrate takes no write
- [x] `ApiControllerScopeTest` (11) — unsupported and mistyped MIME types rejected, blank
  normalised to null, a tag name rejected, a non-existent tag id rejected, a real tag accepted,
  and no tag lookup when none is given
- [x] `ApiControllerFlattenTest` (9) — capability reported to the form, the setting rejected
  when the renderer is absent, off by default, DPI clamped
- [x] `ApiControllerApplyWatermarkTest` (6), `ApiControllerRemoveWatermarkTest` (7),
  `ApiControllerWatermarkedStatusTest` (5), `ApiControllerImageTest` (9)
- [x] `NodeWrittenListenerTest` (9) — queues the job rather than watermarking inline, trigger
  gating, no-session and already-watermarked skips, `suppressFor()` re-entrancy
- [x] `WatermarkLogMapperTest` (8) — batched lookup, distinct ids, a removal cancelling an
  earlier apply, apply → removed → apply counting as watermarked again, and the prune pair's
  `WHERE` clauses: cutoff and delivery-only by default, the trigger clause *absent* when in-place
  rows are included, and no restriction at all for "everything"
- [x] `PruneLogTest` (11) — the retention default, `--days` moving the cutoff, `--all` dropping
  the age filter, `--dry-run` counting without deleting, and a `--days` that is not a positive
  whole number **refused** rather than coerced to 0, which would make a typo the most
  destructive form of the command
- [x] `WatermarkImageStoreTest` (8), `DownloadControllerTest` (4),
  `BeforePreviewFetchedListenerTest` (4), `WatermarkConfigMapperTest` (4, entity only)
  - no `ShareCreatedListenerTest`: on-share is delivery-time, so it is covered by
    `WatermarkServiceTest` and `BeforePreviewFetchedListenerTest`

### Frontend (Jest)

- [x] `WatermarkForm.spec.js` (41) — the two identity placeholders: both offered, each
  labelled with what it resolves to, previewing as *different* values, and the display name as
  the default. Then image upload validation and server rejection, the
  flattening block's presence, absence and DPI reveal, and the "Where to apply" controls:
  exactly the supported types offered, a stored filter reflected, the selection written back in
  canonical order, the tag picker used instead of a typed id, and the corrected help text
  - the delivery-audit switch is covered as a **gating** question, not just a field: offered
    for `on_download` / `on_share`, **absent** for the in-place triggers (whose rows are not
    optional), off by default, a stored value reflected, and present in the payload the server
    is asked to save
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

Several of these have since been promoted, and are marked with the spec that took them over.
What is left by hand is either S3-specific, blocked on a feature, or a judgement a machine
cannot make (the Arabic UI at `dir="rtl"`).

- [ ] **Trigger × access matrix.** For each of `on_demand` / `on_upload` / `on_download` /
  `on_share`: owner direct fetch, owner ZIP, recipient direct fetch, recipient ZIP,
  public-link fetch, public-link ZIP. Expected: `on_share` watermarks for everyone *except*
  the owner; `on_download` for everyone including the owner; the in-place triggers watermark
  the stored bytes so every path carries it and no interceptor engages
  - **`on_share` is now automated through all six cells** (`04-on-share.cy.js`,
    `05-archives.cy.js`), which is the mode where the cells differ from each other and where
    every delivery bug so far has lived
  - `on_download` is automated for owner direct, owner ZIP and a multi-file selection; its
    recipient and public-link cells are not, because the interceptor takes the same path
    there as `on_share` — a reason to expect them to pass, not evidence that they do
  - the in-place triggers are automated for owner direct only. What they need from the other
    cells is the *negative*: that no interceptor engages, since the watermark is already in
    the stored bytes
- [ ] **Tar archives** (`Accept: application/x-tar`) — broken in core itself; recheck. The E2E
  suite asks for zip throughout for that reason; automating tar would pin core's bug
- [ ] **Public file-drop upload** — watermarked by neither path; decide whether to cover it
- [x] **Large-file / many-member archive** — `06-archive-caps.cy.js` builds a 201-member folder
  and crosses `MAX_MEMBERS`: `on_share` answers 403, `on_download` degrades to core's plain
  archive with its members clean
- [ ] **Encrypted / password-protected PDF** through every trigger
- [x] **An Arabic watermark template, read off the file** — `07-arabic.cy.js` asserts on the
  delivered PDF's own glyph codes: every Arabic code unit in Presentation Forms-B, no raw
  U+0600-block code point, and `الاختبار` arriving as **seven** glyphs rather than eight
  because lam-alef ligates. Mixed Arabic + `{date}` covered; an Arabic *display name* is not,
  since that needs a second account whose name the watermark then renders
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

**62 tests across 11 specs**, run against a real Nextcloud 31 from `docker-compose.yml`, plus
one pending test that records the [bidi bug](#open-bidi). `npm run test:e2e`; the whole run is
about **80 seconds**. Setup, layout and the reasoning behind each probe are in
[`cypress/README.md`](../cypress/README.md).

**The test instance needs its rate limiter turned off**, which is a setup step rather than a
detail: core allows **20 shares per 10 minutes per user** (`#[UserRateLimit]` on
`ShareAPIController::createShare`), and a suite that rebuilds its users, folders and shares on
every run legitimately looks like abuse — two runs inside that window exhaust the budget and
the share specs fail in their setup with an empty **429**. `occ config:system:set
ratelimit.protection.enabled --value false --type boolean`, in `cypress/README.md` and in the
CI workflow. Two things learned the hard way and worth keeping: the toggle only stops new
attempts being *recorded*, so an existing backlog still has to expire on its own; and the
harness now names the limit and the fix in the failure, because a 429 from OCS has an empty
body and read as "expected undefined to be one of [100, 200]".

**The rule the suite is built around: every scenario is judged on the bytes that come back.**
Not on a spinner stopping, not on a toast, and not on the file having changed. That last one
matters most — a watermarked file differs from its source in a hundred ways that have nothing
to do with a watermark being drawn, so "the download differs from the upload" passes just as
readily against a re-encode that drew nothing. Concretely:

- **PDFs** are read for `/BaseFont /XXXXXX+IBMPlexSansArabic`. Every watermark this app draws
  uses the bundled subsetted face, and nothing else puts it in a file, so its presence *is* the
  watermark and its absence is a clean original. The same probe reads `/FontFile2`,
  `/ToUnicode` and the page count, which is how "the text layer survives" stays asserted
- **images** are decoded to pixels. Fixtures are a flat white field, `inkRatio` is the fraction
  of pixels that are no longer that colour, and the clean control upload has to measure **zero**
- **archives** are unpacked and probed member by member. This is not optional: the
  container-gate bug produced a *valid* archive of *valid* files, every one of them the clean
  original
- **byte-identical** is used where it is the actual contract — the restored original after a
  remove, and the stored file after an `on_download` fetch

Delivered:

- [x] **On demand** (`01-on-demand.cy.js`) — clean before, stamped after, `nc:is-watermarked`
  over DAV, the already-watermarked skip, remove restoring a **byte-identical** original, the
  second remove answering 422, and both the apply and the undo present in the audit log
- [x] **On upload without waiting for cron** (`02-on-upload.cy.js`) — the assertion is made
  immediately after the upload response, with no cron run in between, because a suite that ran
  the job worker first would pass against the bug this covers. Both shapes: a plain PUT, and a
  **chunked upload** that lands as a MOVE and never PUTs its final path
- [x] **On download** (`03-on-download.cy.js`) — the owner's own fetch watermarked, two fetches
  both watermarked (a burn would have been caught by the already-watermarked guard), the stored
  bytes byte-identical afterwards, and `/api/v1/download` streaming a copy while leaving the
  file alone
- [x] **On share** (`04-on-share.cy.js`) — the recipient's download watermarked and the
  **owner's not**, the public link through `public.php/dav`, the share page's own
  `/s/<token>/download` (a 303 onto that endpoint, followed rather than assumed), previews
  denied to both the recipient and the link visitor, and the owner's own previews still working
- [x] **Archives** (`05-archives.cy.js`) — whole-folder and `files=` selections on both DAV
  servers, an unsupported member travelling through untouched, the owner's own archive left
  clean under `on_share`, and the regression that matters most: **"download selected" on a
  received single-file share**, where the container is the recipient's own home and the old
  gate shipped clean originals. Also the audit granularity, read off the real table: one row
  per watermarked member, none for the unwatermarkable one, and the same rows again on a
  second fetch
- [x] **Archive caps** (`06-archive-caps.cy.js`) — 201 members: `on_share` denies with 403,
  `on_download` degrades to core's plain archive. Either half alone is satisfied by a bug.
  Plus the one assertion no unit test can make: a cap **set with a real `occ` command** on a
  three-member folder, watched 200 → 403 → 200 as the value is set and deleted, which is what
  pins the key name an admin types to the key the code reads
- [x] **Arabic** (`07-arabic.cy.js`) — presentation forms, no raw Arabic code points, and the
  lam-alef ligature counted in the delivered file's own glyph codes
- [x] **Images** (`08-images.cy.js`) — ink drawn and tiled across the canvas, size preserved,
  the blank original restored byte for byte, and a JPEG that comes back a JPEG of the same
  dimensions
- [x] **Prune command** (`11-prune-log.cy.js`) — registered with `occ` at all (a `<commands>`
  entry no unit test reads), delivery rows deleted while the apply row and its badge survive,
  `--dry-run` deleting nothing, the 90-day default matching nothing seconds old, and
  the removed `--include-applied` flag being **refused** rather than silently ignored
- [x] **Admin settings page** (`09-admin-settings.cy.js`) — the Vue app mounts into the
  server-rendered section, a saved policy survives a reload, the preview renders the template
  rather than the raw token, and **what the form displays is what the API stored** — the one
  failure neither side can see alone
- [x] **File actions** (`10-files-app.cy.js`) — Apply from the row menu through the modal, the
  badge appearing without a folder reload, the two actions mirroring so a row never offers
  both, Remove restoring the original, and both actions disappearing when the effective trigger
  is not `on_demand`

Two decisions worth recording, because both cost time to arrive at:

- **the transport is split, and it has to be.** `cy.request` serialises a body as UTF-8, so a
  PDF uploaded through it arrives corrupt and every assertion afterwards is about the
  corruption. Anything carrying file bytes goes through Node tasks. The app's own `/api/v1/*`
  endpoints go the other way — through the browser session — because none of them carries
  `#[NoCSRFRequired]`, so a basic-auth call gets HTTP 412. That is the app being right, and it
  means the suite drives config, apply and remove exactly as the settings page does
- **`\OC\Streamer` writes ZIP64.** A streamed archive cannot know its sizes up front, so the
  32-bit central-directory fields are the `0xFFFF…` sentinels and the real values live in the
  ZIP64 records. The first version of the reader followed a sentinel as an offset and seeked to
  byte 4294967295
- **the log table accumulates across runs, so nothing asserts on its absolute contents.**
  Two versions of the same mistake were shipped and caught here: a count difference against a
  `?limit=N` window (three new rows push three old ones out of view, so the difference
  measures nothing), and a filter by *file path* in a spec whose fixtures are recreated with
  the same names every run (which matched every previous run's rows too). Rows are matched by
  **id** — the log row's own for "what did this fetch add", the file's for "which rows belong
  to this fixture". Both versions passed on a fresh instance and failed once the table grew,
  which is the kind of test that is worse than none
- **the audit log endpoint is a sliding window, so rows are identified and never counted.**
  `GET /api/v1/log?limit=N` returns the newest N: on an instance the suite has run against a
  few times, three new rows push three old ones out of view, and a difference in length
  measures nothing. The audit-granularity test compares row **ids** against a set taken
  before the fetch. It passed on a fresh table and failed once the table grew, which is the
  kind of test that is worse than none

Open:

- [ ] **The full flow on an S3-backed instance.** The suite is storage-agnostic and would run
  against `docker-compose.s3.yml` unchanged — nothing in it touches the local filesystem — but
  nothing wires that up, in CI or by hand
- [ ] **Office documents**, pending the renderer
- [ ] **`{date}` under a non-English locale**, which is the open half of the Arabic work

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
- [x] **Static analysis in CI** — a `psalm` job in the same workflow and a stage in the
  `Jenkinsfile`, both `composer psalm` on 8.2 only: `psalm.xml` pins `phpVersion="8.2"`, so
  an 8.3 leg would re-prove the same analysis. Unlike the coding-standard job it loads the
  extension set the PHPUnit job uses, because Psalm reflects extension classes from the
  running PHP and that is what gets the optional Imagick branch analysed. See
  [Psalm](#open-testing) for what it checks
- [x] **Frontend CI** — `.github/workflows/nodejs.yml` runs three jobs: `npm run lint`
  (ESLint, Node 20), Jest on **Node 20 and 22**, and a `npm run build` webpack job. The build
  job is the one that matters beyond the suites: `js/` is committed, so a build that fails in
  CI while the checked-in bundle still works is the drift this catches
- [x] **E2E CI** — `.github/workflows/e2e.yml` builds both halves on the runner (`composer
  install --no-dev`, `npm ci && npm run build`), brings up `docker-compose.yml`, waits for the
  first-run install, enables the app and runs the suite. Failure screenshots are uploaded and
  the instance's own log is dumped, because a UI spec that fails in CI leaves nothing else
  behind
  - `--no-dev` is not just for speed: the dev tree is what shadowed core's Sabre once, and CI
    should install what a release installs
  - `npm run lint` now covers `cypress/` as well as `src/`, so the harness is held to the same
    standard as the app
- [x] `Jenkinsfile` mirrors the GitHub workflow for the internal CI, including the E2E stage
  - the E2E stage is the one that **cannot** run on a container agent: it bind-mounts the
    workspace into the Nextcloud container, and a bind mount issued from inside a Docker agent
    resolves against the *host* path, mounting the wrong directory. It runs on the agent
    itself and needs php, node and `docker compose` on PATH

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
