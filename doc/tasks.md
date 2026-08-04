# files_watermark - tasks

What is left, ordered by what would hurt most to ship without.

This file is the checklist and nothing else. The reasoning - how each thing was found,
what was measured, why a design is what it is - lives in
[development.md](development.md), and every item here links to it.

**Closed items are deleted from here, not ticked.** What was done, and what it cost to
learn, is recorded in `development.md`; everything below is genuinely still open.

Verified against **Nextcloud 31.0.14.1**, PHP 8.2 + 8.3. Suites green: **620 PHPUnit**,
**93 Jest**, **89 Cypress**, no host-conditional skips.

The two things standing between this and a 1.0 release are **Office support** and
**release packaging**. Everything else below is refinement.

---

## Correctness

Things that produce a wrong file, or say something untrue about one.

- [ ] **A skipped file is silent to the end user.** An on-demand apply reports the error,
  but an `on_upload` or `on_share` file that could not be watermarked shows up only in the
  audit log. Now narrow - only encrypted PDFs can be skipped - but still worth surfacing.
  [notes](development.md#open-1)
- [ ] **Encrypted PDFs are refused outright**, including the empty-password
  permission-flags case that is not real protection. Decrypting in pure PHP is possible
  (`tc-lib-pdf-encrypt` is already a dependency) but is not wired to the import path.
  [notes](development.md#open-1)
- [ ] **Public file-drop uploads** are watermarked by neither the inline path nor the job:
  there is no session to attribute the watermark to. [notes](development.md#open-3)

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
- [ ] **`{date}` / `{datetime}` are locale-free** - ASCII digits, Gregorian, server
  timezone. For an Arabic deployment, decide on Arabic-Indic digits and/or a Hijri date,
  and whether that follows the viewer's locale or a config field. The bundled font carries
  both digit sets, so the font is not the obstacle. [notes](development.md#open-arabic)
- [ ] **Folder downloads through `/api/v1/download`** - accept a folder path, or document
  that archives go through the DAV route. [notes](development.md#open-3)

## Security and operations

- [ ] **Emit `CriticalActionPerformedEvent`** into the Nextcloud admin audit log.
  [notes](development.md#audit-log)
- [ ] **Enforce the no-`exec()` rule mechanically** - a static-analysis rule or a CI grep.
  Nothing stops a future contributor reintroducing a shell-out.
  [notes](development.md#open-nobinary)
- [ ] **Hide the preserved-originals folder from search and the activity feed**, if its
  *name* showing there matters. Two Nextcloud core patches, written out and measured but
  deliberately not applied. [patches](patch.md)

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

- [ ] **A real Team folder, on an instance with `groupfolders` installed.** The support is
  built and unit-tested, but nothing has met an actual Team folder mount - if neither
  detection signal matches, the feature is inert and fails silent. Four checks, listed in
  order. [notes](development.md#team-folders-unverified)
- [ ] **Encrypted / password-protected PDF** through every trigger.
- [ ] **Concurrent uploads of the same path** - `suppressFor()` is a per-process static and
  does not span two PHP workers; confirm `isAlreadyWatermarked()` is what actually prevents
  a double burn. [notes](development.md#manual-verification-matrix)
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
| [Delivery and triggers](development.md#3-delivery-and-triggers-goal-3) | All four triggers, single-file and archive, on every access path; caps are `occ` settings | Tar (core bug), file-drop uploads |
| [Admin UI and file actions](development.md#4-admin-ui-and-file-actions-goal-4) | **Complete** | - |
| [Storage backends](development.md#5-storage-backends-goal-5) | S3 verified end to end; no S3-specific code needed | - |
| [Team folders](development.md#team-folders) | Built: `on_share` no longer exempts the whole team, originals stay in the folder. No dependency on `groupfolders` | Never met a real Team folder mount |
| [Arabic and RTL](development.md#arabic-and-rtl-support) | **Both halves done** - watermark shaped and reordered, UI translated and RTL-clean. Two `tc-lib-unicode` bugs found and [patched](development.md#vendor-patches) | `{date}` localisation, a real Arabic instance, upstream PRs for both patches |
| [No external binaries](development.md#no-external-binaries) | **Done.** No `exec()` anywhere | A rule that keeps it that way |
| [PDF stack migration](development.md#pdf-stack-migration-to-tc-lib-pdf) | **Complete.** FPDI and TCPDF are gone | - |
| [Preserved originals](development.md#security) | In the owner's storage, so server-side encryption covers them; hidden from every client | Their *name* in search and activity |
| [Data model](development.md#data-model) | Schema carries every implemented feature | `metadata` type, cross-DB run, one dead column |
| [Environment](development.md#environment-and-dependencies) | PHP + `bcmath` + GD, Imagick optional | LibreOffice, `exif` |
| [Security](development.md#security) | Two real vulnerabilities found and fixed. On-demand applies bounded by rate limit + size cap; images by a pixel ceiling on every trigger | - |
| [Testing](development.md#testing) | 500 PHPUnit + 91 Jest + 89 Cypress, no host-conditional skips | Psalm level 3 |
| [Docs and release](development.md#docs-and-release) | README covers install, Docker and S3 | API reference, changelog, packaging |
