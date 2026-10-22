# files_watermark - tasks

What is left, ordered by what would hurt most to ship without.

This file is the checklist and nothing else. The reasoning - how each thing was found,
what was measured, why a design is what it is - lives in the engineering record beside it.

**Closed items are deleted from here, not ticked.** What was done, and what it cost to
learn, is recorded there; everything below is genuinely still open.

Verified against **Nextcloud 32.0.14.1**, PHP 8.3. PHPUnit (**899**, one skip) and
Jest (**150**) are green, along with Psalm at level 3, php-cs-fixer and ESLint. The skip is
`PdfFlattenerTest::testAgainstTheRealRendererIfThisHostHasOne`, the only case that wants a
real `pdftoppm`; CI installs `poppler-utils`, so it runs there.

**The Cypress suite has not been run since the trigger rework**, and there are now two
reasons it is the first thing to do. Every spec was rewritten for the new model and none of
them has met an instance - the rework changed what the app does at the level the e2e suite
measures, so the specs are a statement of intent rather than a result. On top of that, the
`tc-lib-pdf-font` 4.0 bump changed how a font subset is numbered, so
[`cypress/tasks/pdf.js`](../cypress/tasks/pdf.js) had to be rewritten to decode drawn glyphs
through `/ToUnicode`. That probe backs every Arabic and text assertion in the suite. It was
verified against a real watermarked PDF from the same renderer, but not against an instance.

The **trigger rework** below has landed, and it deleted or superseded a good deal of what
follows it. **Office support** and **release packaging** are what stand between this and a
1.0 release.

---

## Task to be resolved

Not yet sorted into the sections below. Most severe first. **Confirmed** items were
reproduced on the dev instance; the rest come from reading the code against core's.

- [ ] **Theming's custom background copies any file the reader can see and serves it
  back as stored.** *Read in NC 32's source, not reproduced.* Setting a custom background
  from Files (`UserThemeController::setBackground`, type `custom`, `value` = a path) copies
  `$userFolder->get($path)->fopen('r')` into the account's appdata as `background.jpg`
  (`BackgroundService::setFileBackground`), and `GET /apps/theming/background` serves those
  bytes unchanged. A marked JPEG or PNG comes back clean. The copy happens before the image
  is checked, so a PDF comes back too once the account already has a custom background.
  Refuse `setBackground` when the path is a delivery candidate.
- [ ] **Core's avatar-from-file hands back a full-resolution copy.** *Read in NC 32's
  source, not reproduced.* `POST /avatar/` with `path` (`AvatarController::postAvatar`)
  reads any JPEG or PNG the user can see, up to 20 MB, and answers a non-square one with the
  whole image re-encoded as a base64 `data:` URI in the JSON. The type check goes by the
  cached MIME type, so by the name. Same refusal as theming; first check that a global
  middleware reaches core's own controllers.
- [ ] **`MeasureDeliveryJob` never measures anything.** *Confirmed.* It resolves the file
  with `IRootFolder::getById()`, which finds nothing under cron because no user filesystem
  is set up (the same CLI gotcha `Version1010` works around). `occ background-job:execute`
  on a queued job for an existing marked file reports success and writes no reservation.
  So no `on_upload` or inherited mark is ever measured before its first download, and the
  Windows VFS first-open failure the job exists to prevent happens on every one of them,
  with nothing in the log. Resolve through `IUserMountCache::getMountsForFileId()` +
  `getUserFolder()`. `MeasureDeliveryJobTest` mocks `getById()`, which is why it passes.
- [ ] **`occ files:transfer-ownership` marks every transferred file when "watermark public
  links" is on.** `ShareAccess` reads "no session user" as a public-link fetch, and
  `NodeMovedListener` asks `isForcedByShare()` about every file in a move that crosses
  owners. Under `occ` there is never a session, so each file the policy covers gets an
  inherited mark naming the old owner: one the new owner can never remove, and nobody can
  once the old account is deleted. `NodeCopiedListener` has the same shape, and so does any
  move or copy made without a session. The share switches describe a fetch, and a
  server-side move is not one.
- [ ] **Under `on_upload`, renaming a file into a supported type keeps it unmarked.**
  `NodeWrittenListener` checks the MIME of the name at write time, and a rename fires no
  write event (core's `View::rename` emits write hooks only for `.part` files). Upload
  `report.bin`, rename it to `report.pdf`, and the policy never sees it. That is the user
  off switch `marksAreRemovable()` was written to take away. Mark on `NodeRenamedEvent`
  too, or sniff the bytes at write time as delivery already does.
- [ ] **An archive lists the folder twice, and only the first listing is checked.**
  `ZipInterceptorPlugin::preRender()` decides which members to render from one walk;
  `streamNode()` walks the subfolders again with fresh `getDirectoryListing()` calls and
  streams anything missing from `$rendered` with `$node->fopen()`. A protected file that
  lands in a subfolder between the two walks goes into the archive clean. Rendering is slow,
  so that window is seconds long. Stream from the list `preRender()` built, and fail closed
  on any member that is not in `$rendered` but is marked or share-forced when it is streamed.
- [ ] **The default template names the reader by a field the reader can edit.**
  `defaultConfig()` uses `{displayname} - {date}`, and display names (like `{email}`) can
  be changed by the user unless the admin turns that off. A reader can make a copy name a
  colleague, or name nobody. `assertDrawable()` uses `trim()`, which keeps NBSP,
  zero-width and other invisible characters, so " - 2026-10-19" counts as a drawn watermark.
  Put `{username}` in the default, warn in the admin form when a template has no
  `{username}`, and treat text that is only invisible or whitespace as nothing drawn.
- [ ] **Anonymous deliveries are audited as the owner.** `recordLog()` falls back to
  `readerIdentity()`, which is the owner when there is no session, so every public-link
  download shows up as the owner downloading their own file, and the two can't be told
  apart. Record `anonymous` (and the share token) instead. That also makes
  `MeasureDeliveryJob`'s and other no-session rows honest.
 *Confirmed* on
  `Nextcloud Manual.pdf`: the stored file has 213 link annotations, an outline and page
  labels; the delivered copy has none. Importing pages as Form XObjects carries only page
  content, so form fields and the tag tree (accessibility) very likely go the same way (not
  checked). Every reader of a marked PDF, the owner included, gets a degraded document.
  Copy annotations, `/Outlines` and `/PageLabels` across, or say in the README that they go.
- [ ] **Permission-restricted PDFs come out unrestricted.** `PdfDecryptor` strips an
  empty-user-password encryption so the file can be imported, and the render is written
  unencrypted, so the `/P` flags ("no printing", "no copying") are gone: the watermarked
  copy allows what the original forbade. Re-encrypt the render with the source's `/P` and a
  random owner password; `tc-lib-pdf-encrypt` is already in `vendor/`.
- [ ] **`on_upload` only sees writes made through Nextcloud.** Files that reach external
  storage (SMB, local, S3 buckets fed by other systems) and are picked up by a scan or
  `occ files:scan` fire no `NodeWrittenEvent`, so they are never marked. A file renamed or
  replaced on the backend directly, or a whole mount removed and re-added, gets new file
  ids, and its marks are dropped silently (then reaped by `CleanupJob`). Decide whether
  scanner events should mark too, and say in the README that marks are tied to file ids.
- [ ] **Under `on_upload`, moving a file into the tagged folder never marks it.** This is
  the folder-tag version of the rename item above: scope is only checked on write. A file
  uploaded somewhere else and then moved into the tagged folder stays unmarked, and so does
  everything already in a folder when the tag is added. Re-run the policy on
  `NodeRenamedEvent` when the destination is in scope.
- [ ] **Saving from an in-browser editor may write the watermarked render back.** *Not
  checked.* The Viewer's image editor loads the image through a watermarked route and
  saves over the stored file (or as a copy). If so, saving burns the editor's name into the
  stored original. For a share recipient with edit rights, that is the "names the wrong
  person" problem the rework removed, and the copy carries no mark. Check what URL the
  editor loads and what `files_pdfviewer` saves back. If either does this, mark the
  written-back file, or refuse the write when a marked file's new content matches a
  render.
- [ ] **The folder-tag scope only looks at the immediate parent.** `assertFolderTagMatches()`
  tests `$file->getParent()`, so a file one level below the tagged folder is outside the
  policy: not marked on upload, refused on demand, not watermarked through a share. The form
  says "Limit to a tagged folder". Walk the ancestors, or say "direct children only" in the
  UI. The same check also loads every object id carrying the tag on each call, once per row
  of a share listing; `haveTag()` on the candidate ids would be one indexed query.
- [ ] **The badge reads file contents on every share listing.** `PropFindPlugin` calls
  `isForcedByShare()` without `inspectContent: false`, the parameter 391cba2 added for
  exactly this caller; no caller passes it. With either share switch on, every file in a
  recipient's or public visitor's listing whose name is not a supported type is opened and
  1 KiB read. On S3 that is an object GET per file per PROPFIND, anonymous on public links
  and outside the render rate limit. `PublicShareScriptsListener` does the same for up to
  200 children on every share page load. The badge is an indicator; delivery sniffs for
  itself.
- [ ] **Preview stamping assumes one preview per request.** `PreviewRequestContext` keeps
  only the first preview a request fetches, and the middleware replaces the whole response.
  A controller that answers with several previews at once (Memories' batch preview endpoint
  is the likely one) would send a marked file's thumbnail clean when an unmarked one came
  first, or have its response swapped for a single image. Core and the stock apps fetch one
  preview per request, so this is about third-party apps.
- [ ] **Task processing streams the files a task names.** *Read in NC 32's source, not
  reproduced; needs an AI provider app.* `GET /ocs/v2.php/taskprocessing/tasks/{taskId}/file/{fileId}`
  (`TaskProcessingApiController::getFileContents`) returns any file in the user's own task
  with `fopen('rb')`, and scheduling only checks that the user has a mount for the file id.
  With a provider for a task type that takes files, naming a marked file in a task and
  fetching it gets it clean; the ExApp route hands the same bytes to the provider. With no
  provider nothing can be scheduled.
- [ ] **Inventory the third-party egress routes.** Apps that read file bytes through their
  own controllers are not intercepted. ONLYOFFICE and Collabora opening a PDF, and Memories'
  full-size image endpoints, are the likely ones; none has been checked. At the least the
  README should name the ones known not to watermark. Core and the bundled apps were swept
  on 2026-10-22 against NC 32; the three items above are what that turned up.
- [ ] **The logo is re-read on every render and has no pixel ceiling.** Every download and
  every thumbnail copies the logo out of appdata to a temp file (an object GET per thumbnail
  on S3 primary storage). The upload checks type and bytes but not dimensions, so a 2 MiB
  PNG that declares 30000 x 30000 is decoded on every render and takes every marked
  download down with it. Apply `image_max_pixels` at upload, and keep one local copy per
  request.
- [ ] **Marks outlive their origin's account.** `UserDeletedListener` clears reservations
  only. A mark naming a deleted user stays, so a new account created with the same uid can
  remove it, and an inherited mark descending from that user is locked for good.
- [ ] **`Depth: infinity` PROPFIND drops reserved lengths.** `primeReservations()` replaces
  the cache for each directory in the walk, and a primed cache is trusted completely, so
  files listed after a subdirectory get core's length and etag. Core disables infinity by
  default; merge the batches instead of replacing them.
- [ ] **Apply is offered on files the user cannot change.** `isApplyActionEnabled()` does
  not check update permission, so a read-only share recipient gets the action and then a
  403 toast.
- [ ] **A marked file renamed to an unsupported extension cannot be unmarked from the UI.**
  The badge still shows, since the server sniffs the bytes, but both actions gate on the
  name's MIME, so Remove never appears. Renaming it back works.

## The trigger rework - **built**

Landed on 2026-08-06: two triggers, neither of which touches the stored file, and a marked
file rendered watermarked at every fetch against the identity of whoever is asking. The
model, the six decisions it was built on and the preview design are written up in the
engineering record. What follows is what remains.

- [ ] **Measure the per-fetch render cost on a real folder.** Every download of a marked
  file now renders, and the preview path renders per thumbnail per viewer. A render cache
  keyed by file id + mtime + viewer uid is the obvious answer if it does not hold up, and
  the obvious answer reintroduces stored watermarked bytes - so measure first.
- [ ] **Team folders are outside both share switches.** A Team folder is neither an
  `ISharedStorage` nor a public link, so *"always watermark internal shares"* watermarks
  nothing in the one storage shape that is multi-user by construction - the same hole
  `TeamFolder` was written to close for the old `on_share`, and it went with the rework.
  Needs the fourth signal back, or a deliberate statement that marking is the answer there.
- [ ] **A bulk `occ files_watermark:mark <path>`.** No upgrade
  path means an instance upgrading from `on_download`
  has every existing file unmarked and no UI to mark them in bulk. Not needed for a first
  release; needed by the first instance that migrates.

## Correctness

Things that produce a wrong file, or say something untrue about one.

- [ ] **A skipped file is silent to the end user.** An on-demand apply reports the error,
  but an `on_upload` or `on_share` file that could not be watermarked shows up only in the
  audit log. Narrower still now that only password-protected PDFs can be skipped, but
  still worth surfacing.
  The trigger rework moves this, it does not close it: the failure moves from apply time to
  fetch time, where the reader is the one who needs to be told.
- [x] ~~**Encrypted PDFs are refused outright**, including the empty-password
  permission-flags case that is not real protection.~~ Closed 2026-09-08 by
  `PdfDecryptor`, in pure PHP on `tc-lib-pdf-encrypt`. All five revisions of the standard
  security handler, object streams included. A **real** password stays refused, which is
  policy rather than a gap.
- [ ] **Public file-drop uploads** are watermarked by neither the inline path nor the job:
  there is no session to attribute the watermark to.
- [ ] **Marking replaces the owner's synced copy with a watermarked one.** Measuring a
  delivery length bumps the file's etag so sync clients re-read it - which is what makes
  the first virtual-file open work - but a client holding a *full* local copy reads the
  same bump as a server-side change and downloads the watermarked rendering over the clean
  original. An edit to it then uploads the watermark into the stored file. Kept, because
  the alternative is the Windows VFS first-open failure the bump exists to prevent; the
  client-side guard is in [clients/README.md](clients/README.md). Needs a decision on
  whether the bump should skip the owner.

## Features not built

- [ ] **Office documents** (SDD goal 1) - no renderer and no conversion pipeline.
  `OfficeWatermarker` for docx/xlsx/pptx/odt/ods/odp, a headless LibreOffice or Collabora
  pipeline, the MIME types in `WatermarkService::SUPPORTED_*`, graceful conversion failure,
  and the file action registered for those types.
- [ ] **Invisible metadata watermark** (SDD goal 2) - `MetadataWatermarker`, embedding
  acting user and timestamp, `metadata` accepted as a `type` (needs both `VALID_TYPES` and
  a migration), usable alongside *or* instead of a visible mark, and proven to survive the
  download path.
- [ ] **`{date}` / `{datetime}` are locale-free** - ASCII digits, Gregorian. For an Arabic
  deployment, decide on Arabic-Indic digits and/or a Hijri date, and whether that follows the
  viewer's locale or a config field. The bundled font carries both digit sets, so the font is
  not the obstacle. (The timezone half is done: they read `default_timezone` from
  `config.php`.)
**Dropped: the file-versions undo option**.
Eight items, all of them about making the destructive burn cheaper to undo. The rework
deletes the burn and `OriginalStore` with it, so there is no second copy to give back and
no undo to pay for. Recorded here rather than silently removed because the measurements it
called for - whether `files_versions` even cuts a version on the `on_upload` path, whether
a labelled version survives expiry - were never made, and nothing in the new design needs
them.

## Security and operations

- [ ] **Emit `CriticalActionPerformedEvent`** into the Nextcloud admin audit log.
- [ ] **Enforce the one-exception process rule mechanically** - a static-analysis rule or
  a CI grep. `PdfFlattener` is the single permitted call site, so the check to write is
  "no `proc_open(`, `exec(`, `shell_exec(`, `system(` or `passthru(` outside
  `lib/Service/PdfFlattener.php`". Nothing stops a future contributor adding a second one.
- [ ] **Verify the public share page badge against a running instance.** The server half is
  tested (`PropFindPluginTest`, public mode) and the client half degrades to drawing
  nothing, but two things were reasoned about rather than observed on NC 32: that
  `files_sharing` fires `BeforeTemplateRenderedEvent` for that page, and that its file list
  renders the same `[data-cy-files-list-row-fileid]` rows the badge looks for.
- [ ] **The Files list thumbnail stays clean until the folder reloads.** Marking moves the
  file's etag and the Viewer picks that up
  ([record](development.md#browser-cache-after-marking)), but the list builds its preview
  URL from the etag on the node it already holds (`&v=`), and Apply/Remove emit
  `files:node:updated` with that same stale etag. Re-reading the node after the action (one
  PROPFIND for `getetag`) and emitting the fresh one would close it. A Photos timeline that
  was already open when the file was marked has the same staleness, and nothing this app
  ships runs inside Photos to fix it; reloading Photos does.
- [ ] ~~**Hide the preserved-originals folder from search and the activity feed.**~~ Dropped
  with `OriginalStore`: the rework never writes a preserved original, so no folder appears
  to be hidden. The two core patches in [patch.md](patch.md) stay written down - they were
  measured, and the next feature that needs to hide a node from search will want them.

## Data model

- [ ] **Run the migrations on MySQL, PostgreSQL and SQLite.** They use portable types, but
  that has not been observed on all three.
- [ ] **`metadata` is not an accepted `type`** - needs `VALID_TYPES` and a migration, with
  the invisible watermark above.

## Testing and CI

- [ ] **Psalm level 2: 53 findings**, 42 of them `ClassMustBeFinal` - a design opinion the
  test doubles contradict, not a type check. Behind it: 4 redundant casts, 3 truthy
  comparisons, 3 `PropertyNotSetInConstructor`, 1 docblock contradiction.
- [ ] **`ZipInterceptorPlugin::streamNode` drift.** It duplicates core's and the stubs
  cannot catch it drifting; re-diff against core on every Nextcloud upgrade.
- [ ] **Jest against a *loaded* Arabic catalogue** - the mock returns source strings, so
  the suite proves the calls are wired, never that the catalogue renders.
- [ ] **Import fidelity beyond one generated fixture** - tc-lib-pdf's import subsystem is
  young, and one page of one fixture is thin evidence for it.
- [ ] `OfficeWatermarkerTest`, `MetadataWatermarkerTest` - pending those services.

### End-to-end gaps

- [ ] **The rewritten PDF probe, against an instance.** `cypress/tasks/pdf.js` now decodes
  drawn glyphs through the font's `/ToUnicode` CMap, because `tc-lib-pdf-font` 4.0 numbers a
  subset from zero instead of by code point. Every text and Arabic assertion in the suite -
  `textRuns`, `shapedArabicGlyphs`, `unshapedArabicCodepoints` - is derived from it, so a
  mistake there fails or passes twelve specs at once. Verified against a real watermarked
  PDF from the same renderer; not yet against Nextcloud.
- [ ] ~~**A real Team folder, on an instance with `groupfolders` installed.**~~ Dropped with
  `TeamFolder` - it exists to make `on_share` honest in a folder with no owner to exempt,
  and the new model exempts nobody, so there is nothing left to verify.
- [ ] **Password-protected PDF** through every trigger. Sharper after the rework, not
  milder: deny, never serve clean turns "this file was skipped" into "this file cannot be
  downloaded by anyone". The empty-password case is no longer part of this - it renders
  now - but it is worth confirming against a real office-suite file rather than only
  against fixtures the app generated itself.
- [ ] ~~**Concurrent uploads of the same path.**~~ Closed by the rework - there is no burn
  to double, `suppressFor()` is gone, and a mark is idempotent on a primary key.
- [ ] **The two share switches, end to end.** Unit-tested on both sides - `ShareAccess`
  against the storage and the session, the service against the policy - and never run against
  a real share. Four cells: a received share and the owner's own copy of the same file under
  *internal*; a public link fetched anonymously and by a signed-in visitor under *external*;
  plus the denial when a shared file is over `apply_max_bytes`.
- [ ] **The full flow on S3.** The suite is storage-agnostic and would run against
  `docker-compose.s3.yml` unchanged; nothing wires it up.
- [ ] **Tar archives** (`Accept: application/x-tar`) - broken in core itself, so automating
  it would pin core's bug. Recheck on upgrades.

## Environment

- [ ] Headless **LibreOffice / Collabora** in the Docker dev environment - blocked on
  Office support.
- [ ] PHP **`exif` / metadata libraries** - blocked on the invisible metadata watermark.

## Docs and release

- [ ] Document every API endpoint with request and response
  shapes.
- [ ] Developer guide: how to add a new file-type renderer.
- [ ] Localisation section: which languages ship, how to add one.
- [ ] `CHANGELOG.md`, covering 1.0.0 through 1.7.0.
- [ ] **Release note for flattening, in both directions.** It was dropped in 1.2.0 and is
  back in 1.7.0 as an optional setting - and because the columns were dropped and re-added
  at their defaults, an admin who had it on before 1.2.0 does *not* get it back by
  upgrading. Say so, along with the `poppler-utils` requirement and the accessibility cost.
- [ ] Package for the App Store and tag the release.
- [ ] Headless LibreOffice in the documented Docker workflow, pending Office support.

---

## Where this stands

| Area | Position | Open |
| --- | --- | --- |
| Renderers | PDF and images complete, pure PHP, PDF 1.5+ and empty-password encryption read natively. Office not started | Office pipeline |
| Watermark content | Visible watermarks complete | Invisible metadata watermark |
| Trigger rework | **Built.** Two triggers, a mark instead of a burn, rendered per fetch against the reader - previews included | Per-fetch render cost unmeasured, bulk `occ` mark |
| Delivery and triggers | Single-file, archive and preview delivery on every access path; caps are `occ` settings | **The four-trigger model it describes is superseded**; Tar (core bug), file-drop uploads |
| Share switches | Built: internal shares and public links can be watermarked whether or not the file is marked, decided per fetch | Team folders, end-to-end verification |
| Admin UI and file actions | **Complete** | - |
| Storage backends | S3 verified end to end; no S3-specific code needed | - |
| Team folders | Built: `on_share` no longer exempts the whole team, originals stay in the folder. No dependency on `groupfolders` | **Deleted by the rework** - nothing is exempt, so there is nothing to detect |
| Arabic and RTL | **Both halves done** - watermark shaped and reordered, UI translated and RTL-clean. The two `tc-lib-unicode` bugs found here are fixed upstream in 3.0; the local patches are gone | `{date}` localisation, a real Arabic instance |
| No external binaries | **One exception, deliberate.** `PdfFlattener` shells out to `pdftoppm` for the optional tamper-resistance setting; nothing else spawns a process. Optional, probed, and falls back to the ordinary watermark | A rule that keeps it to the one |
| PDF stack migration | **Complete.** FPDI and TCPDF are gone | - |
| Preserved originals | In the owner's storage, so server-side encryption covers them; hidden from every client | **Deleted by the rework** - no burn, so no copy to preserve, hide or give back |
| Data model | Schema carries every implemented feature; the whole migration chain is squashed into one step at app version 1.2.0 | `metadata` type, cross-DB run |
| Environment | PHP + `bcmath` + GD, Imagick optional | LibreOffice, `exif` |
| Security | Two real vulnerabilities found and fixed. On-demand applies bounded by rate limit + size cap; images by a pixel ceiling on every trigger | - |
| Testing | 837 PHPUnit + 131 Jest + 80 Cypress; one skip, the flattener's real-renderer case, which CI does run | Psalm level 3, and Cypress against an instance |
| Docs and release | README covers install, Docker and S3 | API reference, changelog, packaging |
