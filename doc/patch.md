<!--
	MD010 (no hard tabs) is off for this file alone. Every code block here is either PHP
	held to the Nextcloud coding standard or a diff hunk meant to apply to tab-indented
	core files — converting those tabs to spaces would produce patches that do not apply.
-->
<!-- markdownlint-disable MD010 -->

# Hiding `.files_watermark` from clients

The app keeps a pre-watermark copy of every file it burns a watermark into, at
`{owner}/files/.files_watermark/originals/{fileId}`. It lives in the owner's own storage
for one reason: that is the only place Nextcloud's **server-side encryption** reaches, so
the copy is ciphertext exactly like the file it was taken from. See
[`tasks.md`](tasks.md) under Security for why appdata could not be made to work.

The cost of that location is visibility, and most of it is already paid for **in the app**:
`HideOriginalsPlugin` takes the folder off WebDAV entirely and `ShareGuardListener` refuses
to share a copy. Nothing to do for those — they ship, they are registered, and their tests
fail if either guard is weakened. Read the class docblocks for why each hook is where it is.

**This document is what is left: two patches to Nextcloud's own code**, which close the two
places the app cannot reach from outside. They are deliberately *not* applied — patching
shipped code has consequences that belong to whoever runs the instance, see
[What these patches cost](#what-these-patches-cost).

Both were **measured against Nextcloud 31.0.14**, not inferred: each was applied to a
running instance, the surface re-queried, and the instance restored. The commands that
produced each result are included so you can repeat them.

## Where the folder shows up

| Surface | Closed by |
| --- | --- |
| WebDAV `PROPFIND` listing — desktop, mobile, any client | `HideOriginalsPlugin` (app) |
| Web UI file list (goes through the same WebDAV endpoint) | `HideOriginalsPlugin` (app) |
| Legacy `/remote.php/webdav/` endpoint | `HideOriginalsPlugin` (app) |
| `PROPFIND` addressed straight at the folder | `HideOriginalsPlugin` (app) |
| Deleted-files list (`/dav/trashbin/...`) | `HideOriginalsPlugin` (app) |
| `GET`, `PUT`, `DELETE`, `MOVE`, `COPY` of the exact path | `HideOriginalsPlugin` (app) |
| A public link created **by path**, serving the bytes | `ShareGuardListener` (app) |
| Unified search (OCS, never touches WebDAV) | **Patch 1**, below |
| Activity feed and its API | **Patch 2**, below |
| Thumbnails / previews | nothing needed — see [below](#what-none-of-this-hides) |
| Quota and parent folder size | **nothing** |

The app-side guards already cover every route that serves the **bytes**. The two patches
below cover the two that leak the folder's *name*: search and activity are built from the
file cache and from hooks, so they never pass through the WebDAV response the plugin
filters, and no app-side hook reaches them.

---

## Patch 1 — unified search

Search is built from the file cache by the Files app's search provider and never touches
the WebDAV response, so the app's DAV guard does not reach it. Unpatched, the folder and its contents
come back by name:

```bash
curl -s -u admin:admin -H "OCS-APIRequest: true" -H "Accept: application/json" \
  "http://localhost:8080/ocs/v2.php/search/providers/files/search?term=files_watermark"
```

```json
{"entries":[{"title":".files_watermark","attributes":{"fileId":"59","path":"/.files_watermark"}}]}
```

**File:** `apps/files/lib/Search/FilesSearchProvider.php` (in `search()`, the array the
results are mapped over — line 134 in 31.0.14)

```diff
 			$searchResultEntry->addAttribute('fileId', (string)$result->getId());
 			$searchResultEntry->addAttribute('path', $path);
 			return $searchResultEntry;
-			}, $userFolder->search($fileQuery)),
+			}, array_values(array_filter(
+				$userFolder->search($fileQuery),
+				static fn (Node $node): bool => !str_contains($node->getPath() . '/', '/.files_watermark/'),
+			))),
 			$query->getCursor() + $query->getLimit()
 		);
```

`Node` is already imported by that file. `array_values` is not decoration: `array_filter`
preserves keys, and `array_map` over a gapped array would serialise as a JSON object
instead of a list.

### Verify

```bash
curl -s -u admin:admin -H "OCS-APIRequest: true" -H "Accept: application/json" \
  "http://localhost:8080/ocs/v2.php/search/providers/files/search?term=files_watermark"
# {"entries":[], ...}

curl -s -u admin:admin -H "OCS-APIRequest: true" -H "Accept: application/json" \
  "http://localhost:8080/ocs/v2.php/search/providers/files/search?term=Readme"
# unaffected — ordinary results still come back
```

---

## Patch 2 — activity feed

Activity is written from hooks, so it announces the copies as they are made — in the web
UI feed and through the activity API that mobile clients read:

```text
file_created | You created .files_watermark/originals/4242, .files_watermark/originals and .files_watermark
```

`addNotificationsForFileAction()` is the single choke point for created / changed /
deleted / restored, and it already carries a precedent for exactly this kind of exclusion
(`.part` files), which is where the new condition goes.

**File:** `apps/activity/lib/FilesHooks.php` (line 143 in activity 4.0.0)

```diff
 	protected function addNotificationsForFileAction($filePath, $activityType, $subject, $subjectBy) {
 		// Do not add activities for .part-files
 		if (substr($filePath, -5) === '.part') {
 			return;
 		}
+
+		// Do not add activities for files_watermark preserved originals
+		if (str_contains($filePath . '/', '/.files_watermark/')) {
+			return;
+		}
```

### Verify

```bash
# a write inside the folder, and a control write outside it
curl -s -u admin:admin -T ./any-file \
  "http://localhost:8080/remote.php/dav/files/admin/.files_watermark/originals/9001"
curl -s -u admin:admin -T ./any-file \
  "http://localhost:8080/remote.php/dav/files/admin/control.txt"

curl -s -u admin:admin -H "OCS-APIRequest: true" -H "Accept: application/json" \
  "http://localhost:8080/ocs/v2.php/apps/activity/api/v2/activity/all?limit=5"
# "You created control.txt" is there; nothing for 9001
```

Entries written before the patch stay in the feed — this stops new ones, it does not
rewrite history.

---

## What these patches cost

Both files are shipped, signed code, so patching them **fails Nextcloud's integrity
check**. Measured, not predicted:

```console
$ occ integrity:check-app files
  - INVALID_HASH:
    - lib/Search/FilesSearchProvider.php:
      - expected: da8d12fa86b04df4be5bd8b341b4f0…
      - current:  fa1d86c6b352d0a42000bd4475ba21…
```

The admin overview shows "Some files have not passed the integrity check" until the file
is restored. Reverting the file clears it with no further action.

Consequences to plan for:

- **every upgrade reverts them.** `files` ships with the server, `activity` updates on its
  own cadence. Keep both as `.patch` files in your deployment and re-apply after each
  upgrade, with a post-upgrade check that fails loudly if an anchor no longer matches
- the anchors are pinned to Nextcloud 31.0.14 and activity 4.0.0. Re-read both hunks
  before applying to any other version — a patch that applies to shifted context is worse
  than one that fails
- do **not** reach for `'integrity.check.disabled' => true`. It silences the warning by
  disabling the check for the whole instance, which is a real protection traded away for a
  cosmetic reason

That is why these two are left to you while the app-side guards ship: the guards cost
nothing at upgrade time and close every route that actually serves the bytes. These two buy
the folder's *name* disappearing from search results and the activity feed, at the price of
re-applying them after every upgrade. The trade is yours to make, and doing neither leaves
the contents just as unreachable.

## What none of this hides

The app's own guards already close every route found here that serves the bytes; these two
patches close the two that leak the name. What neither touches:

- **quota and folder sizes still include it.** The owner's usage reflects the copies, and
  nothing in the WebDAV response is rewritten to pretend otherwise. This is the honest
  behaviour: the space really is used
- **`occ` and server-side tooling** see the folder normally — `files:scan`,
  `files:cleanup`, and any app reading the file cache directly. It has to: the app's own
  restore path reads these files through the same Files API
- **shares that already exist.** `ShareGuardListener` refuses new ones; it does not revoke
  old ones, so list them once on an instance that ran without it
- **other apps' listings.** Anything that builds its own view from the file cache rather
  than from WebDAV needs the same treatment as Patch 1 here. Search and activity are the
  two that surfaced on a default install; an instance with more apps may have others
- **anyone with the account and shell or database access.** None of this is an
  access-control boundary — it is about what clients are shown. The copies hold the same
  bytes as the user's own file, which that user can read anyway

### Previews are not a leak here, but not by design

Thumbnails of the copies cannot be generated at all: preserved originals are named for
their file id with **no extension**, so Nextcloud detects them as
`application/octet-stream`, for which no preview provider exists.

```console
.files_watermark/originals/4244   mime=application/octet-stream   previewable=false
Nextcloud.png                     mime=image/png                  previewable=true
```

Both files hold identical PNG bytes. This falls out of the naming scheme rather than from
anything in these patches — change how copies are named and previews start working, at
which point `/core/preview?fileId=…` becomes a route worth closing.

## The no-patch option

The Nextcloud desktop client keeps an ignore list (Settings → *Edit Ignored Files*, backed
by `sync-exclude.lst`), and `.files_watermark` can be added to it per client or shipped in
the deployed configuration. That needs no server change at all and cannot be undone by an
upgrade.

It is weaker than what the app already does, in two ways worth stating plainly: it covers
only the clients you control, and it does nothing about the web UI, the mobile apps, search
or activity. The app's own guards need no client cooperation at all, so this is at most a
belt-and-braces measure for managed desktops — never a substitute.

> Unlike everything above, this section is **not** measured — it describes client-side
> configuration that was not exercised against a real desktop client here.
