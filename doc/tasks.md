# files_watermark - tasks

What is left, ordered by what would hurt most to ship without: sections first, then the
items inside each one.

This file is only the checklist. The reasoning - how each thing was found, what was
measured, why a design is what it is - lives in the [engineering record](development.md).
**Closed items are deleted from here, not ticked**; the record says what was done and what
it cost to learn.

Before a first release, three things are needed: the bypasses in the first section, Office support, and the release packaging.

**Confirmed** means reproduced on the dev instance. **Read in source** means found by
reading the code against core's and not reproduced. An item marked neither was found by
reading this app's own code.

---

## Protected files that leave clean

The app exists to stop these. Each one is a route by which a file the policy covers
reaches a reader unwatermarked.

- [ ] **An archive lists the folder twice, and only the first listing is checked.**
  `ZipInterceptorPlugin::preRender()` decides which members to render from one walk.
  `streamNode()` then walks the subfolders again with fresh `getDirectoryListing()` calls and
  streams anything missing from `$rendered` with `$node->fopen()`. A protected file that
  lands in a subfolder between the two walks goes into the archive clean, and since rendering
  is slow, that window is seconds long. Stream from the list `preRender()` built, and fail
  closed on any member that is missing from `$rendered` but is marked or share-forced when it
  is streamed.
- [ ] **`on_upload` only sees writes made through Nextcloud.** Files that reach external
  storage some other way (SMB, local, S3 buckets fed by other systems) and are picked up by
  a scan or `occ files:scan` fire no `NodeWrittenEvent`, so they are never marked. A file
  renamed or replaced on the backend directly, or a mount removed and re-added, gets new
  file ids. Its marks are then dropped silently, and `CleanupJob` reaps them. Decide
  whether scanner events should mark too, and say in the README that marks are tied to file
  ids.
- [ ] **Saving from an in-browser editor may write the watermarked render back.** *Not
  checked.* The Viewer's image editor loads the image through a watermarked route and saves
  over the stored file, or as a copy. If that happens, saving burns the editor's name into
  the stored original. For a share recipient with edit rights, that is the "names the wrong
  person" problem the rework removed, and the copy carries no mark. Check what URL the
  editor loads and what `files_pdfviewer` saves back. If either one does this, mark the
  written-back file, or refuse the write when a marked file's new content matches a render.
- [ ] **Team folders are outside both share switches.** A Team folder is neither an
  `ISharedStorage` nor a public link, so "always watermark internal shares" watermarks
  nothing in the one storage shape that is multi-user by construction. `TeamFolder` closed
  this hole for the old `on_share`, and it was removed in the rework. Either bring back a
  fourth signal for Team folders, or state plainly that marking is the answer there.
- [ ] **Task processing streams the files a task names.** *Read in NC 32's source; needs an
  AI provider app to reproduce.*
  `GET /ocs/v2.php/taskprocessing/tasks/{taskId}/file/{fileId}`
  (`TaskProcessingApiController::getFileContents`) returns any file in the user's own task
  with `fopen('rb')`, and scheduling only checks that the user has a mount for the file id.
  With a provider for a task type that takes files, a user can name a marked file in a task
  and fetch it clean. The ExApp route hands the same bytes to the provider. Without a
  provider, nothing can be scheduled.
- [ ] **Preview stamping assumes one preview per request.** `PreviewRequestContext` keeps
  only the first preview a request fetches, and the middleware replaces the whole response.
  A controller that answers with several previews at once (Memories' batch preview endpoint
  is the likely one) would either send a marked file's thumbnail clean when an unmarked one
  came first, or have its response swapped for a single image. Core and the stock apps fetch
  one preview per request, so this only affects third-party apps.
- [ ] **Inventory the third-party egress routes.** This app does not intercept apps that
  read file bytes through their own controllers. The likely ones are ONLYOFFICE and
  Collabora opening a PDF, and Memories' full-size image endpoints; none of them has been
  checked. At the least, the README should name the apps known not to watermark. Core and
  the bundled apps were swept against NC 32 on 2026-10-22, and the only open finding from
  that sweep is task processing, above.

## Wrong marks and wrong names

The file is watermarked, but with the wrong mark, the wrong name, or a wrong record.

- [ ] **A move or copy made without a session marks every file the public-link switch
  covers.** `occ files:transfer-ownership` is the sharpest case. `ShareAccess` reads "no
  session user" as a public-link fetch, and `NodeMovedListener` asks `isForcedByShare()`
  about every file in a move that crosses owners. Under `occ` there is never a session, so
  with "watermark public links" on, each covered file gets an inherited mark naming the old
  owner. The new owner can never remove that mark, and once the old account is deleted,
  nobody can. `NodeCopiedListener` has the same shape. The share switches describe a fetch,
  and a server-side move is not one.
- [ ] **The default template names the reader by a field the reader can edit.**
  `defaultConfig()` uses `{displayname} - {date}`, and users can change their display name
  (and `{email}`) unless the admin turns that off. A reader can therefore make a copy name a
  colleague, or name nobody. `assertDrawable()` uses `trim()`, which keeps NBSP, zero-width
  and other invisible characters, so " - 2026-10-19" counts as a drawn watermark. Put
  `{username}` in the default, warn in the admin form when a template has no `{username}`,
  and treat text that is only invisible characters or whitespace as nothing drawn.
- [ ] **Anonymous deliveries are audited as the owner.** When there is no session,
  `recordLog()` falls back to `readerIdentity()`, which is the owner. Every public-link
  download is logged as the owner downloading their own file, and the two can't be told
  apart. Record `anonymous`, and the share token, instead.
- [ ] **Marks outlive their origin's account.** `UserDeletedListener` clears only
  reservations. A mark that names a deleted user stays, so a new account created with the
  same uid can remove it, and an inherited mark that descends from that user is locked for
  good.
- [ ] **`Depth: infinity` PROPFIND drops reserved lengths.** `primeReservations()` replaces
  the cache for each directory in the walk, and a primed cache is trusted completely, so
  files listed after a subdirectory get core's length and etag. Core disables infinity by
  default. Merge the batches instead of replacing them.
- [ ] **Marking replaces the owner's synced copy with a watermarked one.** *Needs a
  decision.* Measuring a delivery length bumps the file's etag so that sync clients re-read
  it, which is what makes the first virtual-file open work. A client that holds a *full*
  local copy reads the same bump as a server-side change, and downloads the watermarked
  rendering over the clean original. If the owner then edits that copy, the upload writes
  the watermark into the stored file. The bump stays, because without it the Windows VFS
  first-open fails. The client-side guard is in [clients/README.md](clients/README.md).
  Decide whether the bump should skip the owner.
- [ ] **A file the policy could not mark is silent.** Under `on_upload`, a file that cannot
  be marked (a password-protected PDF, or one over `apply_max_bytes`) is stored and served
  as uploaded, and the only record is a warning in the log. The uploader is never told.
  Through a share switch, the same file is refused at fetch time, so the reader is the
  person who needs to be told why.

## What a watermarked PDF loses

These affect every reader of a marked PDF, the owner included.

- [ ] **Watermarked PDFs lose their links, bookmarks and page labels.** *Confirmed* on
  `Nextcloud Manual.pdf`: the stored file has 213 link annotations, an outline and page
  labels, and the delivered copy has none of them. Importing pages as Form XObjects carries
  only page content, so form fields and the tag tree (accessibility) very likely go the same
  way, though that is not checked. Copy the annotations, `/Outlines` and `/PageLabels`
  across, or say in the README that they are lost.
- [ ] **Permission-restricted PDFs come out unrestricted.** `PdfDecryptor` strips an
  empty-user-password encryption so the file can be imported, and the render is written
  unencrypted. The `/P` flags ("no printing", "no copying") are gone, so the watermarked
  copy allows what the original forbade. Re-encrypt the render with the source's `/P` and a
  random owner password. `tc-lib-pdf-encrypt` is already in `vendor/`.

## Load and limits

- [ ] **The logo is re-read on every render and has no pixel ceiling.** Every download and
  every thumbnail copies the logo out of appdata to a temp file, which is an object GET per
  thumbnail on S3 primary storage. The upload checks type and size in bytes but not
  dimensions, so a 2 MiB PNG that declares 30000 x 30000 is decoded on every render and
  takes every marked download down with it. Apply `image_max_pixels` at upload, and keep
  one local copy per request.
- [ ] **The badge reads file contents on every share listing.** `PropFindPlugin` calls
  `isForcedByShare()` without `inspectContent: false`, the parameter 391cba2 added for this
  exact caller, and no caller passes it. With either share switch on, every file in a
  recipient's or public visitor's listing whose name is not a supported type is opened and
  1 KiB of it is read. On S3, that is an object GET per file per PROPFIND, anonymous on
  public links and outside the render rate limit. `PublicShareScriptsListener` does the same
  for up to 200 children on every share page load. The badge is only an indicator, and
  delivery sniffs the bytes on its own.
- [ ] **Measure the per-fetch render cost on a real folder.** Every download of a marked
  file renders, and the preview path renders per thumbnail per viewer. If that does not
  hold up, the obvious answer is a render cache keyed by file id + mtime + viewer uid, but
  that brings back stored watermarked bytes. Measure first.

## Files app

- [ ] **Apply is offered on files the user cannot change.** `isApplyActionEnabled()` does
  not check update permission, so a read-only share recipient sees the action and then gets
  a 403 toast.
- [ ] **A marked file renamed to an unsupported extension cannot be unmarked from the UI.**
  The badge still shows, because the server sniffs the bytes, but both actions gate on the
  MIME type of the name, so Remove never appears. Renaming the file back works.
- [ ] **The Files list thumbnail stays clean until the folder reloads.** Marking moves the
  file's etag and the Viewer picks that up
  ([record](development.md#browser-cache-after-marking)). The list, though, builds its
  preview URL (`&v=`) from the etag on the node it already holds, and Apply/Remove emit
  `files:node:updated` with that same stale etag. Re-reading the node after the action (one
  PROPFIND for `getetag`) and emitting the fresh etag would fix it. A Photos timeline that
  was already open when the file was marked is stale in the same way, and nothing this app
  ships runs inside Photos to fix it. Reloading Photos does.
- [ ] **Verify the public share page badge against a running instance.** The server half
  is tested (`PropFindPluginTest`, public mode), and the client half draws nothing if
  something is missing. Two things were reasoned about but not observed on NC 32: that
  `files_sharing` fires `BeforeTemplateRenderedEvent` for that page, and that its file list
  renders the same `[data-cy-files-list-row-fileid]` rows the badge looks for.

## Not built

- [ ] **Office documents** (SDD goal 1). Nothing exists yet: an `OfficeWatermarker` for
  docx/xlsx/pptx/odt/ods/odp, a headless LibreOffice or Collabora pipeline (also in the
  Docker dev environment and the documented Docker workflow), the MIME types in the
  `SUPPORTED_*` lists, graceful conversion failure, the file action registered for those
  types, and `OfficeWatermarkerTest`.
- [ ] **Invisible metadata watermark** (SDD goal 2). Needs a `MetadataWatermarker` that
  embeds the acting user and timestamp, `metadata` accepted as a `type` (in both
  `VALID_TYPES` and a migration), use alongside *or* instead of a visible mark, proof that
  it survives the download path, the PHP `exif`/metadata libraries in the environment, and
  `MetadataWatermarkerTest`.
- [ ] **A bulk `occ files_watermark:mark <path>`.** There is no upgrade path, so an instance
  upgrading from `on_download` starts with every existing file unmarked and has no UI to
  mark them in bulk. The same goes for an admin who switches to `on_upload`, or picks a
  folder tag that folders already carry: only tagging a folder from then on marks what is
  in it. A first release can do without it; the first instance that migrates
  cannot.
- [ ] **`{date}` / `{datetime}` are locale-free**: ASCII digits, Gregorian calendar. For an
  Arabic deployment, decide on Arabic-Indic digits and/or a Hijri date, and whether that
  follows the viewer's locale or a config field. The bundled font has both digit sets, so
  the font is not the obstacle.
- [ ] **Emit `CriticalActionPerformedEvent`** into the Nextcloud admin audit log.

## Testing and CI

- [ ] **Rerun Cypress against the current tree and record the result.** The last recorded
  run predates `15-mail-share-recipient` and `16-unmark-policy`, and every fix since
  mid-October.
- [ ] **The two share-switch cells `13-share-switches` does not cover.** These are a
  public link fetched by a *signed-in* visitor, and the refusal when a shared file is over
  `apply_max_bytes`. The rest of the matrix ran green on 2026-09-07.
- [ ] **Password-protected PDF through every trigger.** The rework raised the stakes here:
  "deny, never serve clean" turns "this file was skipped" into "nobody can download this
  file". The empty-password case renders now, but it is worth confirming against a real
  office-suite file, not only against fixtures the app generated itself.
- [ ] **The full flow on S3.** The suite is storage-agnostic and would run unchanged
  against `docker-compose.s3.yml`, but nothing wires it up.
- [ ] **Run the migrations on MySQL, PostgreSQL and SQLite.** They use portable types, but
  that has not been observed on all three.
- [ ] **Enforce the one-exception process rule mechanically**, with a static-analysis rule
  or a CI grep. `PdfFlattener` is the only permitted call site, so the check is "no
  `proc_open(`, `exec(`, `shell_exec(`, `system(` or `passthru(` outside
  `lib/Service/PdfFlattener.php`". At the moment, nothing stops a contributor from adding a
  second one.
- [ ] **Psalm level 2: 122 findings.** 77 of them are `ClassMustBeFinal`, which is a design
  opinion the test doubles contradict, not a type check. The rest: 14
  `RedundantConditionGivenDocblockType`, 13 `DocblockTypeContradiction`, 5 redundant casts,
  5 truthy comparisons, 5 `PropertyNotSetInConstructor`, 2 deprecations and 1 missing
  closure return type.
- [ ] **Jest against a *loaded* Arabic catalogue.** The mock returns source strings, so the
  suite proves the calls are wired, never that the catalogue renders.
- [ ] **Import fidelity beyond one generated fixture.** tc-lib-pdf's import subsystem is
  young, and one page of one fixture is thin evidence for it.

## On every Nextcloud upgrade

- [ ] **Re-diff `ZipInterceptorPlugin::streamNode` against core.** It duplicates core's,
  and the stubs cannot catch it drifting.
- [ ] **Recheck Tar archives** (`Accept: application/x-tar`). They are broken in core
  itself, so automating the check would pin core's bug.

## Docs and release

- [ ] Document every API endpoint with its request and response shapes.
- [ ] Developer guide: how to add a new file-type renderer.
- [ ] Localisation section: which languages ship, and how to add one.
- [ ] `CHANGELOG.md`, from 1.0.0 to the current version.
- [ ] **Release note for flattening, in both directions.** Flattening was dropped in 1.2.0
  and came back in 1.7.0 as an optional setting. Because the columns were dropped and
  re-added at their defaults, an admin who had it on before 1.2.0 does *not* get it back by
  upgrading. Say so, along with the `poppler-utils` requirement and the accessibility cost.
- [ ] Package for the App Store and tag the release.

---

## Where this stands

| Area | Position | Open |
| --- | --- | --- |
| Model | Built. The stored file is never modified. A mark is a row, and every fetch renders against the reader's identity, previews included | Per-fetch render cost unmeasured |
| Triggers | On demand and on upload, scoped by type and by a folder tag at any depth. On upload marks a file written, moved or renamed into scope, and a whole tree when it is tagged or moved inside a tagged folder | Writes from outside Nextcloud |
| Share switches | Internal shares and public links watermarked per fetch, whether or not the file is marked | Team folders, moves without a session |
| Egress routes | Every core and bundled-app route found in the 2026-10-22 sweep is intercepted or refused | Archive re-walk, task processing, third-party apps |
| Renderers | PDF and images in pure PHP. PDF 1.5+ and empty-password encryption are read natively. Office not started | Office, PDF links/outline/labels, `/P` flags |
| Watermark content | Visible text and logo, Arabic shaped and reordered | Default template, invisible metadata watermark, `{date}` localisation |
| Admin UI and file actions | Complete | Read-only Apply, Remove after rename, stale list thumbnail |
| Audit | Every render logged | Anonymous rows logged as the owner, admin audit log event |
| Storage backends | S3 verified end to end with no S3-specific code | Cypress on S3 |
| External binaries | One deliberate exception: `PdfFlattener` runs `pdftoppm` for the optional flattening setting, probed, with a fallback | A rule that keeps it to one |
| Data model | The schema carries every implemented feature | `metadata` type, cross-database run |
| Testing | 1322 PHPUnit, 163 Jest, 92 Cypress cases. Psalm level 3 clean | Cypress rerun, S3, Psalm level 2 |
| Docs and release | README covers install, Docker and S3 | API reference, changelog, packaging |
