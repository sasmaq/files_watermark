# files_watermark - tasks

What is left, ordered by what would hurt most to ship without.

This file is the checklist and nothing else. The reasoning - how each thing was found,
what was measured, why a design is what it is - lives in
[development.md](development.md), and every item here links to it.

**Closed items are deleted from here, not ticked.** What was done, and what it cost to
learn, is recorded in `development.md`; everything below is genuinely still open.

Verified against **Nextcloud 31.0.14.1**, PHP 8.2 + 8.3. PHPUnit (**555**) and Jest
(**93**) are green, along with Psalm at level 3, php-cs-fixer and ESLint.

**The Cypress suite has not been run since the trigger rework.** Every spec was rewritten
for the new model and none of them has met an instance - the rework changed what the app
does at the level the e2e suite measures, so the specs are currently a statement of intent
rather than a result. Running them is the first thing to do.

The **trigger rework** below has landed, and it deleted or superseded a good deal of what
follows it. **Office support** and **release packaging** are what stand between this and a
1.0 release.

---

## The trigger rework - **built**

Landed on 2026-08-06: two triggers, neither of which touches the stored file, and a marked
file rendered watermarked at every fetch against the identity of whoever is asking. The
model, the six decisions it was built on and the preview design are written up in
[development.md](development.md#trigger-rework). What follows is what remains.

- [ ] **Measure the per-fetch render cost on a real folder.** Every download of a marked
  file now renders, and the preview path renders per thumbnail per viewer. A render cache
  keyed by file id + mtime + viewer uid is the obvious answer if it does not hold up, and
  the obvious answer reintroduces stored watermarked bytes - so measure first.
- [ ] **Team folders are outside both share switches.** A Team folder is neither an
  `ISharedStorage` nor a public link, so *"always watermark internal shares"* watermarks
  nothing in the one storage shape that is multi-user by construction - the same hole
  `TeamFolder` was written to close for the old `on_share`, and it went with the rework.
  Needs the fourth signal back, or a deliberate statement that marking is the answer there.
  [notes](development.md#share-switches)
- [ ] **A bulk `occ files_watermark:mark <path>`.** [No upgrade
  path](development.md#trigger-rework-settled) means an instance upgrading from `on_download`
  has every existing file unmarked and no UI to mark them in bulk. Not needed for a first
  release; needed by the first instance that migrates.

## Correctness

Things that produce a wrong file, or say something untrue about one.

- [ ] **A skipped file is silent to the end user.** An on-demand apply reports the error,
  but an `on_upload` or `on_share` file that could not be watermarked shows up only in the
  audit log. Now narrow - only encrypted PDFs can be skipped - but still worth surfacing.
  The trigger rework moves this, it does not close it: the failure moves from apply time to
  fetch time, where the reader is the one who needs to be told. [notes](development.md#open-1)
- [ ] **Encrypted PDFs are refused outright**, including the empty-password
  permission-flags case that is not real protection. Decrypting in pure PHP is possible
  (`tc-lib-pdf-encrypt` is already a dependency) but is not wired to the import path.
  [notes](development.md#open-1)
- [ ] **Public file-drop uploads** are watermarked by neither the inline path nor the job:
  there is no session to attribute the watermark to. [notes](development.md#open-3)
- [ ] **A marked file's earlier versions are not marked, and go out clean.** A version is a
  *copy* with its own file id, and a mark is a row against the live file's id - so
  `isMarked()` is false for every revision. `files_versions`' preview controller previews
  the version node, and a version download resolves to it too, which makes this wider than
  a thumbnail: the last revision of a marked document is one click away in the sidebar,
  unwatermarked. The trash is *not* this bug and is closed - a delete is a move, so the id
  and the mark carry over - but it took its own fix on the download path. Needs a decision
  before it needs code: whether a mark reaches a file's history at all, or whether the
  answer is that versions of marked files are not offered. [notes](development.md#preview-watermarking)

## Features not built

- [ ] **Office documents** (SDD goal 1) - no renderer and no conversion pipeline.
  `OfficeWatermarker` for docx/xlsx/pptx/odt/ods/odp, a headless LibreOffice or Collabora
  pipeline, the MIME types in `WatermarkService::SUPPORTED_*`, graceful conversion failure,
  and the file action registered for those types.
  [notes](development.md#office-documents-officewatermarker)
- [ ] **Invisible metadata watermark** (SDD goal 2) - `MetadataWatermarker`, embedding
  acting user and timestamp, `metadata` accepted as a `type` (needs both `VALID_TYPES` and
  a migration), usable alongside *or* instead of a visible mark, and proven to survive the
  download path. [notes](development.md#open-2)
- [ ] **`{date}` / `{datetime}` are locale-free** - ASCII digits, Gregorian. For an Arabic
  deployment, decide on Arabic-Indic digits and/or a Hijri date, and whether that follows the
  viewer's locale or a config field. The bundled font carries both digit sets, so the font is
  not the obstacle. (The timezone half is done: they read `default_timezone` from
  `config.php`.) [notes](development.md#open-arabic)
- [ ] **Folder downloads through `/api/v1/download`** - accept a folder path, or document
  that archives go through the DAV route. [notes](development.md#open-3)
**Dropped: the file-versions undo option** ([notes](development.md#open-versions-undo)).
Eight items, all of them about making the destructive burn cheaper to undo. The rework
deletes the burn and `OriginalStore` with it, so there is no second copy to give back and
no undo to pay for. Recorded here rather than silently removed because the measurements it
called for - whether `files_versions` even cuts a version on the `on_upload` path, whether
a labelled version survives expiry - were never made, and nothing in the new design needs
them.

## Security and operations

- [ ] **Emit `CriticalActionPerformedEvent`** into the Nextcloud admin audit log.
  [notes](development.md#audit-log)
- [ ] **Enforce the no-`exec()` rule mechanically** - a static-analysis rule or a CI grep.
  Nothing stops a future contributor reintroducing a shell-out.
  [notes](development.md#open-nobinary)
- [ ] **Marking does not reach previews already in a browser's cache.** Confirmed on the
  instance, and it is the first thing an admin reports - "I marked it and the preview has
  no watermark", while every server route serves a watermarked one. Two facts meet:
  core serves the clean preview with `Cache-Control: private, max-age=86400, immutable`,
  and the cache-busting parameter the Viewer and the Files list put in the URL is the
  **file's ETag** (`&etag=`, `&c=`). Marking writes one row in `oc_watermark_mark` and
  deliberately touches nothing about the file, so the ETag does not move, the URL does not
  change, and an `immutable` entry is never revalidated. A hard refresh ends it; so does
  waiting a day.
  - **a server-side preview-cache bust does not fix this** - the browser never asks. The
    real options are bumping the file's ETag when it is marked (which is a filecache
    write, not a content write, but makes every sync client re-fetch the file - arguably
    right, since what they would re-fetch is the watermarked copy), or having the global
    middleware downgrade `immutable` on *clean* previews of watermarkable types so marking
    takes effect within minutes, at the cost of revalidation traffic on every thumbnail
  [notes](development.md#preview-watermarking)
- [ ] ~~**Hide the preserved-originals folder from search and the activity feed.**~~ Dropped
  with `OriginalStore`: the rework never writes a preserved original, so no folder appears
  to be hidden. The two core patches in [patch.md](patch.md) stay written down - they were
  measured, and the next feature that needs to hide a node from search will want them.

## Data model

- [ ] **Run the migrations on MySQL, PostgreSQL and SQLite.** They use portable types, but
  that has not been observed on all three. [notes](development.md#open-data)
- [ ] **`metadata` is not an accepted `type`** - needs `VALID_TYPES` and a migration, with
  the invisible watermark above. [notes](development.md#open-data)

## Testing and CI

- [ ] **Psalm level 2: 53 findings**, 42 of them `ClassMustBeFinal` - a design opinion the
  test doubles contradict, not a type check. Behind it: 4 redundant casts, 3 truthy
  comparisons, 3 `PropertyNotSetInConstructor`, 1 docblock contradiction.
  [notes](development.md#open-testing)
- [ ] **`ZipInterceptorPlugin::streamNode` drift.** It duplicates core's and the stubs
  cannot catch it drifting; re-diff against core on every Nextcloud upgrade.
  [notes](development.md#open-testing)
- [ ] **Jest against a *loaded* Arabic catalogue** - the mock returns source strings, so
  the suite proves the calls are wired, never that the catalogue renders.
  [notes](development.md#open-testing)
- [ ] **Import fidelity beyond one generated fixture** - tc-lib-pdf's import subsystem is
  young, and one page of one fixture is thin evidence for it.
  [notes](development.md#migration-risks)
- [ ] `OfficeWatermarkerTest`, `MetadataWatermarkerTest` - pending those services.

### End-to-end gaps

- [ ] ~~**A real Team folder, on an instance with `groupfolders` installed.**~~ Dropped with
  `TeamFolder` - it exists to make `on_share` honest in a folder with no owner to exempt,
  and the new model exempts nobody, so there is nothing left to verify.
  [notes](development.md#team-folders-unverified)
- [ ] **Encrypted / password-protected PDF** through every trigger. Sharper after the
  rework, not milder: [deny, never serve
  clean](development.md#trigger-rework-settled) turns "this file was skipped" into "this
  file cannot be downloaded by anyone".
- [ ] ~~**Concurrent uploads of the same path.**~~ Closed by the rework - there is no burn
  to double, `suppressFor()` is gone, and a mark is idempotent on a primary key.
  [notes](development.md#manual-verification-matrix)
- [ ] **The two share switches, end to end.** Unit-tested on both sides - `ShareAccess`
  against the storage and the session, the service against the policy - and never run against
  a real share. Four cells: a received share and the owner's own copy of the same file under
  *internal*; a public link fetched anonymously and by a signed-in visitor under *external*;
  plus the denial when a shared file is over `apply_max_bytes`.
  [notes](development.md#share-switches)
- [ ] **The full flow on S3.** The suite is storage-agnostic and would run against
  `docker-compose.s3.yml` unchanged; nothing wires it up.
  [notes](development.md#integration--e2e-cypress)
- [ ] **Tar archives** (`Accept: application/x-tar`) - broken in core itself, so automating
  it would pin core's bug. Recheck on upgrades.
  [notes](development.md#manual-verification-matrix)

## Environment

- [ ] Headless **LibreOffice / Collabora** in the Docker dev environment - blocked on
  Office support.
- [ ] PHP **`exif` / metadata libraries** - blocked on the invisible metadata watermark.

## Docs and release

- [ ] Document every API endpoint (including `/api/v1/download`) with request and response
  shapes. [notes](development.md#docs-and-release)
- [ ] Developer guide: how to add a new file-type renderer.
- [ ] Localisation section: which languages ship, how to add one.
- [ ] `CHANGELOG.md`, covering 1.0.0 and the 1.1.0 flattening release.
- [ ] **Release note for the dropped `flatten_pdf` / `flatten_dpi` columns.** The migration
  has no `down()`, so an admin who had flattening on loses it silently on upgrade and the
  audit log will not explain why watermarked PDFs are suddenly selectable text.
  [notes](development.md#open-nobinary)
- [ ] Package for the App Store and tag the release.
- [ ] Headless LibreOffice in the documented Docker workflow, pending Office support.

---

## Where this stands

| Area | Position | Open |
| --- | --- | --- |
| [Renderers](development.md#1-renderers-goal-1) | PDF and images complete, pure PHP, PDF 1.5+ read natively. Office not started | Office pipeline, encrypted PDFs |
| [Watermark content](development.md#2-watermark-content-goal-2) | Visible watermarks complete | Invisible metadata watermark |
| [Trigger rework](development.md#trigger-rework) | **Built.** Two triggers, a mark instead of a burn, rendered per fetch against the reader - previews included | Per-fetch render cost unmeasured, bulk `occ` mark |
| [Delivery and triggers](development.md#3-delivery-and-triggers-goal-3) | Single-file, archive and preview delivery on every access path; caps are `occ` settings | **The four-trigger model it describes is [superseded](development.md#trigger-rework)**; Tar (core bug), file-drop uploads |
| [Share switches](development.md#share-switches) | Built: internal shares and public links can be watermarked whether or not the file is marked, decided per fetch | Team folders, end-to-end verification |
| [Admin UI and file actions](development.md#4-admin-ui-and-file-actions-goal-4) | **Complete** | - |
| [Storage backends](development.md#5-storage-backends-goal-5) | S3 verified end to end; no S3-specific code needed | - |
| [Team folders](development.md#team-folders) | Built: `on_share` no longer exempts the whole team, originals stay in the folder. No dependency on `groupfolders` | **Deleted by the rework** - nothing is exempt, so there is nothing to detect |
| [Arabic and RTL](development.md#arabic-and-rtl-support) | **Both halves done** - watermark shaped and reordered, UI translated and RTL-clean. Two `tc-lib-unicode` bugs found and [patched](development.md#vendor-patches) | `{date}` localisation, a real Arabic instance, upstream PRs for both patches |
| [No external binaries](development.md#no-external-binaries) | **Done.** No `exec()` anywhere | A rule that keeps it that way |
| [PDF stack migration](development.md#pdf-stack-migration-to-tc-lib-pdf) | **Complete.** FPDI and TCPDF are gone | - |
| [Preserved originals](development.md#security) | In the owner's storage, so server-side encryption covers them; hidden from every client | **Deleted by the rework** - no burn, so no copy to preserve, hide or give back |
| [Data model](development.md#data-model) | Schema carries every implemented feature; the whole migration chain is squashed into one step at app version 1.2.0 | `metadata` type, cross-DB run |
| [Environment](development.md#environment-and-dependencies) | PHP + `bcmath` + GD, Imagick optional | LibreOffice, `exif` |
| [Security](development.md#security) | Two real vulnerabilities found and fixed. On-demand applies bounded by rate limit + size cap; images by a pixel ceiling on every trigger | - |
| [Testing](development.md#testing) | 622 PHPUnit + 93 Jest + 89 Cypress, no host-conditional skips | Psalm level 3 |
| [Docs and release](development.md#docs-and-release) | README covers install, Docker and S3 | API reference, changelog, packaging |
