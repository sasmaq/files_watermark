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

The cost of that location is visibility. This document is the patch set that takes it
back, ordered by what each one costs to keep.

Everything below was **measured against Nextcloud 31.0.14** (`sabre/dav 4.7.0`), not
inferred: each patch was applied to a running instance, the surface re-queried, and the
instance restored. The commands that produced each result are included so you can repeat
them.

## Where the folder shows up

| Surface | Visible before | Closed by |
| --- | --- | --- |
| WebDAV `PROPFIND` listing — desktop, mobile, any client | yes | Patch 1 (app) |
| Web UI file list (goes through the same WebDAV endpoint) | yes | Patch 1 (app) |
| `PROPFIND` addressed straight at the folder | yes | Patch 1 (app) |
| Deleted-files list (`/dav/trashbin/...`) | yes | Patch 1 (app) |
| Unified search (OCS, never touches WebDAV) | yes | Patch 2 (**core**) |
| Activity feed and its API | yes | Patch 3 (**core**) |
| `GET` of the exact path | yes | **nothing** — see [What none of this hides](#what-none-of-this-hides) |
| Quota and parent folder size | yes | **nothing** |

Patch 1 alone covers every *listing* a client can ask for. Patches 2 and 3 exist because
search and activity are built from the file cache and from hooks, and never pass through
the WebDAV response that Patch 1 filters.

---

## Patch 1 — the app, no core changes

The one to apply first, and the only one that survives a Nextcloud upgrade untouched.

`Sabre\DAV\Server::generateMultiStatus()` emits `beforeMultiStatus` with the response's
property list **by reference** (`3rdparty/sabre/dav/lib/DAV/Server.php:1638`), which is
enough to drop entries from any multistatus the server is about to send. Every WebDAV
listing — `PROPFIND` at any depth, and the `REPORT`s the Files app issues — goes through
it, on the whole `/remote.php/dav/` tree rather than the files endpoint alone. That is why
one plugin covers the trashbin as well.

Add `lib/Dav/HideOriginalsPlugin.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Dav;

use OCA\FilesWatermark\Service\OriginalStore;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * Drops the app's preserved originals from every WebDAV listing.
 *
 * `beforeMultiStatus` hands over the response's property list by reference, so an entry
 * removed here never reaches the client — for `PROPFIND` at any depth, for the Files
 * app's `REPORT`s, and for the trashbin, which is the same Sabre tree.
 *
 * This hides; it does not protect. A client that already knows the exact path can still
 * `GET` the file, which is deliberate: the app's own restore path must keep working.
 */
class HideOriginalsPlugin extends ServerPlugin {

	public function initialize(Server $server): void {
		$server->on('beforeMultiStatus', function (&$fileProperties): void {
			$live = '/' . OriginalStore::HOME_FOLDER . '/';
			// The trashbin renames what it takes in: `.files_watermark.d1785710850`.
			$trashed = '/' . OriginalStore::HOME_FOLDER . '.d';

			$filtered = [];
			foreach ($fileProperties as $entry) {
				$href = '/' . ltrim((string)($entry['href'] ?? ''), '/');
				if (str_contains($href, $live) || str_contains($href, $trashed)) {
					continue;
				}
				$filtered[] = $entry;
			}
			$fileProperties = $filtered;
		});
	}
}
```

and register it in `lib/EventListener/SabrePluginAddListener.php`:

```diff
 		$server = $event->getServer();
 		$server->addPlugin($this->container->get(PropFindPlugin::class));
+		$server->addPlugin($this->container->get(HideOriginalsPlugin::class));
 		$server->addPlugin($this->container->get(DownloadInterceptorPlugin::class));
```

with `use OCA\FilesWatermark\Dav\HideOriginalsPlugin;` alongside the other plugin imports.

### Verify

```bash
# Before: the folder is in the listing
curl -s -u admin:admin -X PROPFIND -H "Depth: 1" \
  http://localhost:8080/remote.php/dav/files/admin/ \
  | tr '>' '>\n' | grep -o '<d:href>[^<]*'
```

```text
/remote.php/dav/files/admin/
/remote.php/dav/files/admin/.files_watermark/     <-- gone once the plugin is registered
/remote.php/dav/files/admin/Documents/
...
```

Addressed directly, the folder returns `207` with **no `href` at all**, which clients read
as nothing there:

```bash
curl -s -u admin:admin -X PROPFIND -H "Depth: 0" \
  "http://localhost:8080/remote.php/dav/files/admin/.files_watermark/" | grep -c d:href
# 0
```

Trashbin, after the folder has been deleted once:

```bash
curl -s -u admin:admin -X PROPFIND -H "Depth: 1" \
  "http://localhost:8080/remote.php/dav/trashbin/admin/trash/" \
  | tr '>' '>\n' | grep -o '<d:href>[^<]*'
# only /remote.php/dav/trashbin/admin/trash/ — the .files_watermark.d… entry is gone
```

### Know before you apply it

- the filter matches the folder name **anywhere** in the path, so a folder a user creates
  themselves at `Documents/.files_watermark/` would be hidden too. The app only ever
  writes at the home root; tighten the match to that if the over-reach matters to you
- hiding a path a sync client has already seen reads to that client as a **remote
  deletion**: it removes its local copy. It does not touch the server copy

---

## Patch 2 — core: unified search

Search is built from the file cache by the Files app's search provider and never touches
the WebDAV response, so Patch 1 does not reach it. Unpatched, the folder and its contents
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

## Patch 3 — core: activity feed

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

## What the core patches cost

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

Because of that, Patch 1 is worth applying on its own even if you never take 2 and 3: it
costs nothing at upgrade time and closes every surface a sync client actually lists.

## What none of this hides

- **`GET` by exact path still returns the file** (`200`). These patches hide the copies
  from listings, search and activity; they are not an access control. Anyone who knows the
  path and has the account can fetch the bytes — as they can for the original file itself,
  which is the same data
- **quota and folder sizes still include it.** The owner's usage reflects the copies, and
  nothing in the WebDAV response is rewritten to pretend otherwise. This is the honest
  behaviour: the space really is used
- **`occ` and server-side tooling** see the folder normally — `files:scan`,
  `files:cleanup`, and any app reading the file cache directly
- **other apps' listings.** Anything that builds its own view from the file cache rather
  than from WebDAV needs the same treatment as Patch 2. Search and activity are the two
  that surfaced on a default install; an instance with more apps may have others

## The no-patch option

The Nextcloud desktop client keeps an ignore list (Settings → *Edit Ignored Files*, backed
by `sync-exclude.lst`), and `.files_watermark` can be added to it per client or shipped in
the deployed configuration. That needs no server change at all and cannot be undone by an
upgrade.

It is weaker than Patch 1 in two ways worth stating plainly: it covers only the clients you
control, and it does nothing about the web UI, the mobile apps, search or activity. Treat
it as a supplement to Patch 1 for managed desktops, not a replacement.

> Unlike everything above, this section is **not** measured — it describes client-side
> configuration that was not exercised against a real desktop client here.
