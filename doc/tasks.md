# files_watermark - tasks

What is left, ordered by what would hurt most to ship without.

This file is the checklist and nothing else. The reasoning - how each thing was found,
what was measured, why a design is what it is - lives in
[development.md](development.md), and every item here links to it.

**Closed items are deleted from here, not ticked.** What was done, and what it cost to
learn, is recorded in `development.md`; everything below is genuinely still open.

Verified against **Nextcloud 31.0.14.1**, PHP 8.2 + 8.3. Suites green: **647 PHPUnit**,
**95 Jest**, **91 Cypress**, no host-conditional skips - except the two page-reload cases
added to `10-files-app.cy.js`, which have not been run against an instance yet.

The **trigger rework** below is the dominant item and it invalidates parts of everything
under it - read it first. After that, **Office support** and **release packaging** are
what stand between this and a 1.0 release.

---

## The trigger rework

**Two triggers, and neither one touches the stored file.**

- **On demand** - the action menu *marks* the file.
- **On upload** - a supported upload is marked automatically.

A marked file is then rendered watermarked **at every fetch** - download, archive member
and preview alike - against the identity of whoever is asking. A share recipient sees
their own name, a public-link visitor sees owner of the file who created the public link.

Two things go away with it. `on_download` and `on_share` stop being separate policies:
delivery is now the only way a watermark is ever produced, so the trigger only decides
*which files* carry the mark, never *when* the render happens. And the in-place burn goes
entirely - nothing is written back to storage, so there is nothing to preserve and nothing
to undo, which is most of the deletion list below.

The reasoning here is inline rather than in [development.md](development.md), against this
file's own convention: none of it has been built yet, so there is nothing to link to. It
moves there as each piece lands.

### Settled

Decided on 2026-08-06. These are the premises the rest of this section is built on, not
open questions - each one is here because the code has to be able to point at the answer.

1. **A marked file is watermarked for its owner too.** No exemption for anybody: the
   watermark carries *the reader's* identity, and the owner is a reader. This is a visible
   change from `on_share`, which exempts the owner deliberately, so the admin UI has to say
   it - an owner who finds their own file stamped will otherwise read it as a bug.
2. **A marked file whose render fails is denied, never served clean.** Both existing
   behaviours collapse onto `on_share`'s: 403, not a fallback to the original. The cost is
   that a marked file the renderer cannot parse (an encrypted PDF) becomes undownloadable,
   so the error has to say exactly that rather than reading as a server fault.
3. **No upgrade path.** Files already burned by the current version are left as they are -
   not marked, not restored - and configs still set to `on_download` / `on_share` are not
   migrated. **This ships as if it were the app's first version**, which is what removes the
   whole class of work the other answer needed: no restore command, no bulk-mark command, no
   log-rows-to-marks conversion, and no reason to keep `OriginalStore`'s read path alive for
   a release. The one thing still owed is that an unrecognised trigger value in the database
   must not resolve to *some* behaviour by accident - see the API section.
4. **Apply and Remove are not offered under `on_upload`.** The existing
   `isOnDemandTrigger()` gate stays exactly as it is: under `on_upload` the app marks
   uploads itself and the manual actions are hidden. Files that predate the policy stay
   unmarked and there is no UI to mark them, which follows from 3.
5. **An overwrite keeps the mark.** The mark describes a policy attached to a file id, not
   a property of a particular byte range, so a user's own write over a marked file changes
   nothing. `noteContentReplaced()` and the `replaced` trigger go with this.
6. **The caps bound the *mark*, not the fetch.** `ApplyLimits` and `ImageLimits` keep
   their current meaning and apply in both modes: a file over the ceiling is refused a mark
   (on demand) or skipped (on upload), and having refused it there is never a delivery
   render to bound. Two consequences are carried into the sections below - marking on
   upload now has to read enough of the file to check, and 5 lets a marked file grow past
   the cap afterwards.

### The mark

- [ ] **New `watermark_mark` table** (`file_id` PK, `marked_by`, `trigger`, `config_id`,
  `created_at`) and its migration. The mark stops living in `watermark_log`, which is
  currently two things at once - an audit history *and* the app's record of which files are
  watermarked. That overload is why `PruneLog` cannot touch in-place rows and why
  `findWatermarkedFileIds()` has to replay a per-file event stream to answer one boolean.
  The table is created **empty** - settled 3 - so there is no conversion of existing
  in-place log rows and no file carries a mark on upgrade. The rows those files left behind
  stay in `watermark_log` as history and stop meaning anything, which is the point of
  splitting the two.
- [ ] `WatermarkService::mark()` / `unmark()` / `isMarked()` and a batched
  `markedFileIds(int[])` for the PROPFIND path. `mark()` is idempotent: settled 5 means a
  second write to a marked file re-marks it, and `on_upload` fires on every write.
- [ ] **`watermark_log` becomes pure audit.** `NON_DESTRUCTIVE_TRIGGERS`,
  `CANCELLING_TRIGGERS`, `REMOVAL_TRIGGER`, `REPLACEMENT_TRIGGER` and
  `findWatermarkedFileIds()` all go, and `PruneLog` can then prune by date alone with no
  carve-out.
- [ ] **Decide what a delivery row costs now.** Every fetch renders, so delivery rows are
  no longer a subset of the traffic - they are all of it. `log_delivery` defaulting to off
  means the default install audits nothing at all, which is a different product than the
  one the audit log was built for.

### Delivery

- [ ] **`resolveDelivery()` gates on the mark, not on the trigger.** This is the single
  change that moves the app to the new model; everything in this section follows from it.
- [ ] **Identity is the session user at fetch time.** `buildPlaceholders()` already reads
  it, but it has only ever run for the *owner* on the in-place paths - verify it against a
  share recipient, an archive member fetch and a public link, where the answer must be the
  recipient, the recipient, and "Public link" respectively.
- [ ] **Deny is now the only failure path** (settled 2). `DownloadInterceptorPlugin`,
  `ZipInterceptorPlugin` and `DownloadController` lose the `deliveryTrigger() === 'on_share'`
  special case: a marked file that fails to render is a 403 for everyone, including the
  owner, and `on_download`'s "serve the original on failure" fallback goes with it. Each of
  the three currently degrades differently - the archive path especially - so this is three
  changes, not one.
- [ ] **A marked file can grow past the cap after it is marked** - settled 5 keeps the mark
  across an overwrite, and settled 6 puts the only size check at mark time. So a 1 MB file
  marked today can be 500 MB tomorrow with its mark intact, and every fetch renders it.
  Cheapest fix consistent with settled 2: re-check the size from the file cache at delivery
  and deny past the ceiling. No content is read to do it, and denying is already what a
  failed render does.
- [ ] **`ArchiveLimits` is the one cap that stays a delivery cap**, since an archive is only
  ever a fetch. Re-read it once the per-member gate is the mark rather than the trigger: it
  was sized against how many members a policy would *typically* watermark, which the mark
  changes.
- [ ] **A render cache**, keyed by file id + mtime + viewer uid, if the per-fetch cost does
  not hold up. Worth measuring before building: it reintroduces stored watermarked bytes,
  which is the thing this rework exists to get rid of, so it needs an eviction story and a
  reason.

### Previews - the hard part

- [ ] **Core's preview cache is keyed by file id and dimensions, never by viewer.** A
  watermarked preview that reaches it is served to the *next* viewer with the *first*
  viewer's name on it. `IPreview::getPreview()` gained its `$cacheResult` argument in
  **32.0.0**, and this app targets 31 - so there is no supported way to ask core for an
  uncached preview here. Nothing may put a watermarked image into that cache.
- [ ] **Intercept the preview requests instead.** `registerMiddleware($class, global: true)`
  (the global argument is NC 26+) reaches core's controllers, so the app can render per
  request and answer with `Cache-Control: private, no-store`. Note that `beforeController`
  cannot return a response - the interception has to throw and answer from
  `afterException`.
- [ ] **Enumerate every preview route in 31** before writing that middleware; one missed
  route is an unwatermarked leak, not a missing feature. At least `core.Preview.getPreview`
  and `getPreviewByFileId`, the `files_sharing` public preview controllers, and whatever
  the Viewer and Photos apps call. Verified against core's `routes.php`, not from memory.
- [ ] **Decide what a preview is rendered from.** Stamping core's *clean* cached thumbnail
  is orders of magnitude cheaper and is the only affordable option for a folder of 200
  images; watermarking the full file and downscaling is what makes the preview match the
  download. **Recommend stamping the thumbnail**, with the clean thumbnail staying in
  core's cache (it is only reachable through the endpoints being intercepted).
- [ ] **Fail closed.** A preview that cannot be watermarked is not served - which is what
  `BeforePreviewFetchedListener` already does for `on_share`. Keep it as the fallback
  rather than deleting it, and re-point it at the mark.
- [ ] **The Viewer fetches the full file over DAV**, not a preview, for images and PDFs -
  confirm that, because if true it is already covered by the download interceptor and if
  false it is a second leak path.

### Deletions

Everything that existed to support the burn. Each is dead weight the moment delivery is
the only render path, and leaving one in place leaves a second way for a file's bytes to
change:

- [ ] `OriginalStore`, `HideOriginalsPlugin`, `ShareGuardListener`, `OriginalStoreTest`,
  and every `isBackup()` guard scattered through `WatermarkService`, `NodeWrittenListener`
  and `ApplyLimits`. **Deleted outright, read path included** - settled 3 means nothing ever
  reads a preserved original again. Existing backup folders are left on disk untouched;
  deleting user-visible files during an upgrade is not something this app should do
  unasked, and an admin can remove them by hand.
- [ ] `WatermarkOnUploadJob` and `UploadWatermarkPlugin`. Both exist because
  `NodeWrittenEvent` fires while the write still holds the node lock and a burn needs to
  write. **A mark is one insert and takes no lock**, so `NodeWrittenListener` can do it
  inline - which also fixes the "clean until cron runs" window that the DAV plugin was
  built to paper over.
  What replaces them is `NodeWrittenListener` marking inline - and per settled 6 it has to
  apply both caps while doing it, so "the listener just inserts a row" is not the whole
  job: an oversized upload must be left unmarked, and say so in the log, because nothing
  downstream will refuse it later.
- [ ] `NodeWrittenListener::suppressFor()` and its static `$suppressed` map - there is no
  longer a write of ours to tell apart from a user's.
- [ ] `watermarkInPlace()`, `removeWatermark()`'s restore half, `noteContentReplaced()`.
- [ ] `TeamFolder`. Its only purpose is making `on_share` honest in a folder with no owner
  to exempt, and the new model exempts nobody. Confirm `OriginalStore` is its only other
  caller before deleting.
- [ ] `isShareAccess()`, `isReceivedShare()` and the `$publicContext` flag **as inputs to
  the decision**. The anonymous *label* still needs the public-link case, so
  `anonymousLabel()` stays.

### API, admin UI and Files integration

- [ ] `VALID_TRIGGERS` becomes `['on_demand', 'on_upload']`; `saveConfig()` rejects the
  other two with a message that says what replaced them.
- [ ] **An unrecognised trigger already in the database must resolve to nothing.** The one
  loose end settled 3 leaves: an instance that had `on_download` saved keeps that row, and
  `resolveDelivery()` must not treat an unknown value as either of the two live triggers.
  Reject it where the config is resolved, log it once, and mark nothing - the failure an
  admin can see, not a policy they did not choose.
- [ ] `WatermarkForm.vue` drops both options and explains what the remaining two mean -
  the label has to say the file is watermarked *on access*, not now, or every admin will
  read "on upload" as the old destructive behaviour. It also has to say that the owner sees
  the watermark too (settled 1).
- [ ] `/api/v1/apply` marks instead of burning: `already_watermarked` becomes
  `already_marked`. **The size cap and its 413 stay here** (settled 6) - the file is not
  read, so the check is the same `getSize()` read from the file cache it already is.
- [ ] **The pixel cap needs a new home.** `ImageLimits` is enforced today by
  `assertPixelsAllowed()` inside the render, on a temp copy that exists because a render was
  happening. With no render at mark time, checking it means reading the image *header* from
  storage - the first few KB through `fopen`/`fread`, never the whole file, which is what
  `assertPixelsAllowed()`'s own note about allocating nothing was already arguing for.
- [ ] **The `UserRateLimit(20/60)` on both endpoints is now far too tight for what it
  guards** - it was sized for a request that rendered a PDF, and both are a DB write. It is
  still the only bound on marking from the UI, so raise it rather than dropping it.
- [ ] `/api/v1/remove` unmarks. Its "no preserved original" 422 has nothing left to
  describe and goes.
- [ ] `PropFindPlugin`'s `is-watermarked` property now means *marked*. The property name
  can stay; the frontend copy cannot - "This file is watermarked" describes bytes that are
  not watermarked, and the badge tooltip, the modal text and the action names all say some
  version of it.
- [ ] `main-files.js`: **the `isOnDemandTrigger()` gate stays untouched** (settled 4) - the
  work here is the copy, not the gating. The badge tooltip, both modals and the two action
  names all describe a burn. `WatermarkModal.vue`'s time estimate goes entirely: it is
  computed from the file size for a render that no longer happens when the button is
  pressed.

### Tests

- [ ] `WatermarkServiceTest` is the big one - most of its cases assert in-place behaviour
  that will not exist.
- [ ] Delete `WatermarkOnUploadJobTest`, `UploadWatermarkPluginTest`, `OriginalStoreTest`,
  `ShareGuardListenerTest`, `TeamFolderTest`; rewrite `NodeWrittenListenerTest` around
  inline marking, and `ApiControllerApplyWatermarkTest` / `ApiControllerRemoveWatermarkTest`
  around mark/unmark.
- [ ] New: mark table + mapper, the mark-gated delivery decision, and the preview
  middleware - including *"a second viewer gets their own name"*, which is the whole point.
- [ ] New for the settled answers that are easy to regress into their old behaviour: the
  owner gets watermarked too (1), a failed render is a 403 on all three delivery paths (2),
  an overwrite leaves the mark standing (5), and an oversized file is refused a mark in
  **both** modes (6) - the `on_upload` half of that has no equivalent today.
- [ ] Cypress `03-on-download.cy.js`, `04-on-share.cy.js` and `12-trigger-matrix.cy.js`
  describe policies that will not exist. `01`/`02` need their assertions inverted: the
  stored file must come back **unchanged** after an apply, and watermarked only when
  fetched.
- [ ] New e2e: two users downloading the same marked shared file get **different**
  watermarks, and the same for previews - the one behaviour this rework is for.

### Docs and release

- [ ] `README.md`, `doc/sdd.md` §5.3 and `appinfo/info.xml` (both language descriptions)
  all advertise on-download and on-share as features.
- [ ] **A release note that states the no-upgrade-path decision plainly** (settled 3).
  There is no migration to document, which is exactly why it needs writing down: an instance
  that upgrades keeps its burned files burned, keeps its `OriginalStore` folders on disk with
  nothing reading them, and - if its policy was `on_download` or `on_share` - stops
  watermarking altogether until an admin picks one of the two remaining triggers. Every one
  of those is silent, and the release note is the only place they can be seen.

---

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
  rework, not milder: settled 2 turns "this file was skipped" into "this file cannot be
  downloaded by anyone".
- [ ] ~~**Concurrent uploads of the same path.**~~ Closed by the rework - there is no burn
  to double, `suppressFor()` is gone, and a mark is idempotent on a primary key.
  [notes](development.md#manual-verification-matrix)
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
| [Delivery and triggers](development.md#3-delivery-and-triggers-goal-3) | All four triggers, single-file and archive, on every access path; caps are `occ` settings | **Being replaced by the two-trigger rework above**; Tar (core bug), file-drop uploads |
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
