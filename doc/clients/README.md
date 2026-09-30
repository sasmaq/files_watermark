# Watermarking in the native clients

This folder is for developers adding the watermark feature to the Nextcloud clients: the
Windows desktop client, the Android app and the iOS app. It describes what the
`files_watermark` server app (1.8.x, Nextcloud 31) exposes to a client, what a client has to
do with it, and what a client must not do.

| File | Covers |
| --- | --- |
| [README.md](README.md) (this file) | The server contract all clients share: properties, endpoints, download behaviour, rules |
| [windows.md](windows.md) | The desktop sync client on Windows: discovery, virtual files (CfAPI), Explorer badge and menu, read-only local copies |
| [android.md](android.md) | The Android app: listing, badge, file actions, the SAF provider, thumbnails, downloads |
| [ios.md](ios.md) | The iOS app: listing, badge, file actions, the File Provider extension, previews, downloads |

Read this file first. The platform files build on it and do not repeat it.

## How the feature works

The server never changes a stored file. **Marking** a file records a policy against it. From
then on, every download and every preview of the file is rendered on the fly with a watermark
naming **whoever is fetching it**. Two accounts downloading the same file get two different
documents.

A download is watermarked for one of two reasons:

- **The file is marked.** This applies to every reader, the owner included. Marks are placed
  by hand (the *Apply watermark* action) or automatically on upload, depending on the admin's
  trigger setting.
- **The file reaches this reader through a share the admin's policy watermarks.** The admin
  can switch this on for internal shares and for public links. The owner's own downloads are
  not affected.

Four types are supported: `application/pdf`, `image/jpeg`, `image/png` and `image/webp`.
Nothing else is ever watermarked.

### What a stock client already gets

The watermark is applied on the server, on requests every client already makes:

- `GET` on `/remote.php/dav/files/{user}/…` and `/remote.php/dav/trashbin/{user}/…`
- every preview endpoint (`/index.php/core/preview` and the others)
- public-link downloads under `/public.php/dav/…`

So an unmodified client already downloads and displays watermarked copies. What it lacks
falls into four areas:

| Area | Without it |
| --- | --- |
| **Status**: read two WebDAV properties and draw a badge | Users can't see which files are protected, and actions can't be gated |
| **Actions**: *Apply watermark* and *Remove watermark* | Users have to open the web UI to mark or unmark a file |
| **Download handling**: 403, `Range`, checksums, `HEAD` | Retry loops on a file that can never be served; false "corrupt download" errors |
| **Not undoing the protection**: read-only local copies, no direct links, no version downloads | A local edit uploads the reader's watermark into the stored file; some download paths hand out the clean original |

### Terms

| Term | Meaning |
| --- | --- |
| Marked file | A file with a mark on the server. Every download of it is watermarked, for everyone |
| Reader | The account fetching the file. The watermark names the reader |
| Owner | The file's `oc:owner-id`. Only the owner may remove a mark |
| Inherited mark | Copying a marked file marks the copy. Only the owner of the *original* may remove that mark, so the person who made the copy can't, even though they own the copy |
| Trigger | Admin setting: `on_demand` (users mark files by hand) or `on_upload` (every supported upload is marked) |

## 1. Status: two WebDAV properties

Both are in the `http://nextcloud.org/ns` namespace, the same one as `nc:is-encrypted`. Add
them to the listing PROPFIND you already send. The server batches them per folder, so they
cost one database query per listing, not one per row.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
  <d:prop>
    <d:getetag/>
    <d:getcontentlength/>
    <d:getcontenttype/>
    <oc:fileid/>
    <oc:owner-id/>
    <oc:permissions/>
    <nc:is-watermarked/>
    <nc:watermark-locked/>
  </d:prop>
</d:propfind>
```

| Property | Values | Meaning |
| --- | --- | --- |
| `nc:is-watermarked` | `1` / `0` | `1` when a `GET` of this file **by the requesting account** is watermarked, either because the file is marked or because it reaches this account through a share the policy watermarks |
| `nc:watermark-locked` | `1` / `0` | `1` when the file carries an inherited mark that this account may not remove. Only ever `1` when `is-watermarked` is `1` |

A marked file on a server with the app, then the same properties on a server without it:

```xml
<d:response>
  <d:href>/remote.php/dav/files/alice/Documents/report.pdf</d:href>
  <d:propstat>
    <d:prop>
      <d:getetag>"193968820ab4980f90c84357ec27d562"</d:getetag>
      <d:getcontentlength>41411</d:getcontentlength>
      <d:getcontenttype>application/pdf</d:getcontenttype>
      <oc:fileid>1234</oc:fileid>
      <oc:owner-id>alice</oc:owner-id>
      <oc:permissions>RGDNVW</oc:permissions>
      <nc:is-watermarked>1</nc:is-watermarked>
      <nc:watermark-locked>0</nc:watermark-locked>
    </d:prop>
    <d:status>HTTP/1.1 200 OK</d:status>
  </d:propstat>
</d:response>

<!-- server without the app, or with it disabled -->
<d:propstat>
  <d:prop>
    <nc:is-watermarked/>
    <nc:watermark-locked/>
  </d:prop>
  <d:status>HTTP/1.1 404 Not Found</d:status>
</d:propstat>
```

How to read them:

- **They belong to the account, not the file.** A share recipient can see `is-watermarked=1`
  on a file whose owner sees `0` (a file watermarked only because it arrived through a share).
  Store the values against the account.
- **Folders get a value too. Ignore it.** Only files are watermarked, and a folder's value says
  nothing about what is inside it.
- **Detecting the feature.** A server without the app, or with it disabled, returns both
  properties in a `404 Not Found` propstat. Treat that as "feature absent": no badge and no
  actions. The app publishes no capability entry.
- **The trash listing** (`/remote.php/dav/trashbin/{user}/trash`) answers `is-watermarked` as
  well, for marks only. Show the badge there, but offer no actions.
- **`SEARCH` and `REPORT` listings** (favourites, recent, media) resolve properties through the
  same Sabre machinery, so the two properties should come back there too if you request them.
  This repository's tests don't cover that. Check it against your server, and fall back to the
  REST call below if they come back as 404.

### How status changes reach a client

The file's content doesn't change when its status does. What a client gets to see differs by
cause:

| Change | What the server does | What a client sees |
| --- | --- | --- |
| A file is marked (by hand or on upload) | Gives the file a new etag and propagates it to every parent folder, keeping the mtime | The normal etag-driven refresh picks it up. No polling needed |
| A file is unmarked | **Nothing** beyond deleting the mark | Parent folder etags stay the same, so etag-driven discovery doesn't look again. Local watermarked copies stay until something else changes in that folder |
| The admin changes the share switches | Nothing per file | Already-synced copies stay as they were until the file changes |
| The admin saves the policy | Invalidates every stored length promise (see [Downloads](#3-downloads)); changes no etag | Nothing until a marked file is next downloaded. That download arrives at a new length and changes the etag, so a sync client downloads the file once more, and with virtual files that first open can fail once |

So after the client itself removes a watermark, it has to refresh that file explicitly. See
[After a successful call](#after-a-successful-call).

### REST fallback: status by file id

For file ids you hold without a listing (activity, notifications, a search result that came
back without the properties):

```text
GET /index.php/apps/files_watermark/api/v1/watermarked?ids=12,34,56
```

Send the same headers as for the actions below. The response:

```json
{"watermarked": [12], "locked": []}
```

- It reports **marks only**, not share-based watermarking. The DAV property is the more
  complete answer, so use this only where there is no listing.
- Ids outside the account's reach (not in its files and not in its trash) are dropped without
  an error.
- The ids travel in the query string. Send at most 100 per request: longer URLs can exceed the
  web server's request-line limit and fail with `414` before the app ever sees them.
- Treat each entry as an integer id, and accept numeric strings too.

## 2. Actions: Apply and Remove

### Calling the API

The two actions and the status fallback are ordinary Nextcloud app routes, not OCS routes.
Authenticate the way the clients already do, with Basic auth and the account's app password,
and send these headers:

| Header | Value | Why |
| --- | --- | --- |
| `OCS-APIRequest` | `true` | **Required.** These routes are CSRF-protected. Nextcloud skips the CSRF check for requests that carry this header. Without it, every call fails with `412 Precondition Failed` ("CSRF check failed") |
| `Accept` | `application/json` | Responses are plain JSON, with no OCS envelope |
| `Content-Type` | `application/json` | For the `POST` bodies |

The base URL is `{server}/index.php/apps/files_watermark/api/v1/`. The `/index.php/` prefix
works whether or not the server has pretty URLs enabled.

```sh
curl -s -u 'alice:APP-PASSWORD' \
  -H 'OCS-APIRequest: true' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"path": "/Documents/report.pdf"}' \
  https://cloud.example.com/index.php/apps/files_watermark/api/v1/apply
# {"status":"watermarked","path":"/Documents/report.pdf"}
```

### Endpoints

| Method and path | Body | Success |
| --- | --- | --- |
| `POST …/apply` | `{"path": "/Documents/report.pdf"}` | `200 {"status": "watermarked", "path": …}`. `"already_watermarked"` also means success |
| `POST …/remove` | `{"path": "/Documents/report.pdf"}` | `200 {"status": "removed", "path": …}`. `"not_watermarked"` also means success |
| `GET …/watermarked?ids=…` | none | `200 {"watermarked": […], "locked": […]}` |

`path` is the file's path relative to the account's files root, with a leading `/`. That is
the DAV href with `/remote.php/dav/files/{userId}` removed and percent-encoding decoded. Send
it as a JSON string, not URL-encoded. Non-ASCII names, Arabic included, are fine.

Neither action touches the file's bytes, so both return immediately: nothing is rendered and
nothing is uploaded.

### Errors

Every error body is `{"error": "<message>"}`, already translated into the account's Nextcloud
language. Show the message as it is.

| Status | Apply | Remove | What the client does |
| --- | --- | --- | --- |
| 400 | The path is a folder | The path is a folder | Fix the gating; it should never happen |
| 401 | Bad credentials | Bad credentials | The usual re-authentication flow |
| 403 | The account can't read or can't modify the file | The account can't read the file, isn't its owner, or the mark is inherited from someone else's file | Show the message and refresh the file's properties |
| 404 | File not found | File not found | Refresh the listing |
| 412 | `OCS-APIRequest` header missing | Same | Add the header |
| 413 | The file is over the server's size limit (`apply_max_bytes`, 64 MiB by default) or pixel limit (`image_max_pixels`, 40 MP by default) | - | Show the message; it names both the size and the limit |
| 415 | Unsupported file type | - | Fix the gating; it should never happen |
| 422 | The admin's policy excludes this file (type filter or tagged-folder scope) | - | Show the message |
| 429 | More than 120 calls to this action from this account within a minute | Same | Back off and try later; never retry in a loop |

### When to offer each action

Mirror the web client so users see the same menu everywhere. Offer an action only when every
row in its column holds:

| Condition | Apply | Remove |
| --- | --- | --- |
| Exactly one item is selected, and it is a file | Required | Required |
| `d:getcontenttype` is one of the four supported types | Required | Required |
| The normal files view: not the trash, not a version, not a public link | Required | Required |
| `nc:is-watermarked` | Must be `0` | Must be `1` |
| `oc:permissions` contains `W` | Recommended (the server enforces it; the web client doesn't check first) | - |
| `oc:owner-id` equals the account's user id | - | Required |
| `nc:watermark-locked` | - | Must be `0` |
| The trigger is `on_demand` | Required | Required |

**The last row can't be checked by a native client today.** The web client reads the trigger
from its own page, and no API exposes it to accounts that aren't admins. Treat the trigger as
`on_demand`, which is also the web client's own fallback. The server accepts both actions
under either trigger. The one difference under `on_upload` is that an owner can unmark a file
from the client, where the web menu would not have offered it.

Compare the owner against the user id in the account's DAV URL
(`/remote.php/dav/files/{userId}`), not against the login name. With LDAP the two can differ.

The web client offers both actions for one file at a time. If you add a bulk action, send the
calls one after another and stop at the first `429`.

### Confirmation and wording

The web client asks for confirmation before both actions. Reuse its strings so the feature
reads the same on every platform. `l10n/ar.json` in this repository has the Arabic
translations, keyed by the English text.

| Where | String |
| --- | --- |
| Menu entries | `Apply watermark`, `Remove watermark` |
| Apply dialog | Title `Apply Watermark`; `Apply watermark to: {file}`; `The file itself is not changed. From now on, every download and every preview of it carries a watermark naming whoever fetched it.` |
| Apply result | `Watermark applied successfully.` or `This file is already watermarked.` |
| Remove dialog | Title `Remove Watermark`; `Remove the watermark from: {file}`; `The file itself does not change. Downloads and previews of it simply stop being watermarked, and you can apply the watermark again at any time.` |
| Remove result | `Watermark removed.` |
| On `429` | `Too many watermark requests at once. Wait a moment and try again.` |
| Badge tooltip and accessibility label | `Downloads and previews of this file are watermarked` |
| Buttons | `Apply`, `Cancel`, `Close` |

The badge icon is the app's mark, `img/app.svg` (a 24×24 viewBox, single colour), with
`img/app-dark.svg` for dark backgrounds. Convert it to each platform's vector format.

### After a successful call

1. Update the item's stored `is-watermarked` straight away, so the badge and the menu change
   without waiting for the next listing.
2. Re-read the file's properties with a `Depth: 0` PROPFIND.
3. Drop the local copy and any cached preview of the file, and download it again if it is kept
   offline. After an Apply, the local copy no longer matches what the server delivers. After a
   Remove, it still carries the watermark.

Step 3 matters most after a Remove: the server doesn't change any etag when a mark is removed
(see [How status changes reach a client](#how-status-changes-reach-a-client)), so nothing else
will prompt the client to replace the watermarked copy.

## 3. Downloads

The request doesn't change. For a watermarked file, the response does:

| | File not watermarked | Watermarked file |
| --- | --- | --- |
| Body | The stored bytes | Rendered for this request. Two downloads by the same account differ, because the watermark can carry a timestamp |
| `Content-Length` | Stored size | The `d:getcontentlength` that PROPFIND advertised; each render is padded to it. The one exception is described below |
| `ETag` | Stored etag | The same per-account etag PROPFIND advertised. It stays the same across renders |
| `OC-Checksum` | The stored file's checksum | SHA1 of the bytes actually sent |
| Request with `Range` | `206` with the requested part | **`Range` is ignored: `200` with the full body** |
| `HEAD` | Stored size and etag | **Also the stored size and etag**, which don't match PROPFIND |

What that means for a client:

- **Size.** For a watermarked file, PROPFIND reports in `d:getcontentlength` the length the
  download will have, and the server pads each render to exactly that length. The exception
  is a download the server has no valid measurement for: **an account's first download of the
  file** (usually a share recipient's; the owner's is measured when the file is marked), and
  **the first download after the admin changes the policy**. That body's length differs from
  what PROPFIND said. The server measures it and changes the etag, and the next listing carries
  the right size. Only `d:getcontentlength` is corrected; `oc:size` still reports the stored
  size.
- **Integrity.** Check the body against the `OC-Checksum` response header. Never compare a
  watermarked download against `oc:checksums` from PROPFIND: that is the stored file's
  checksum, and it will never match.
- **Change detection.** Use the etag. Never hash the content to decide whether a file changed,
  or to deduplicate: every render is different.
- **Resuming.** A resumed download gets `200` and the full body. If the response has no
  `Content-Range`, start again from byte 0.
- **Sizing.** Don't use `HEAD` to size a download; it answers with the stored metadata.

### 403 on a download

If the server can't generate the watermark for a file that needs one, it **refuses the download
rather than serving the file clean**. The response is `403` with a Sabre error body, whose
message is translated into the account's language:

```xml
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\Forbidden</s:exception>
  <s:message>This file is watermarked on download, and the watermark could not be generated.</s:message>
</d:error>
```

Causes include a password-protected or damaged PDF, and a file watermarked only because of a
share that is over the server's size limit. **None of these fixes itself**: the answer stays
`403` until the file or the admin policy changes. Show `s:message`, mark the item as failed,
and keep it out of automatic retries. A later etag change is the signal to try again.

Files in the trash are downloaded the same way, watermark included.

## 4. Previews

A preview of a watermarked file comes back stamped for the requesting account, with
`Cache-Control: private, no-store, no-cache, must-revalidate`. On a small thumbnail the
watermark is an unreadable smear, by design.

- **If the stamp fails, the server answers `404`.** Show the generic file-type icon, and don't
  retry in a loop.
- **Cache previews per account.** A preview names the account that fetched it. If you keep
  previews on disk despite `no-store`, key them by account as well as by file id and etag.
  File ids, and therefore `oc:id`, are the same for every account on one server. A cache keyed
  by file id alone can show one account's name on another account's screen.
- **Evict on status change.** When `is-watermarked` changes, drop cached previews of that file.

Previews of files that aren't watermarked are unchanged.

## 5. What a client must not do

These are the ways a client can undo the protection.

### Upload a watermarked copy back

A downloaded copy of a watermarked file **is** a watermarked rendering. If the user edits it and
the client uploads the result, the stored file carries this reader's name from then on, and
every later download stacks a second watermark on top. For a share recipient with edit rights,
that puts the recipient's name into the owner's document.

The recommended guard is to treat local copies of files with `is-watermarked=1` as
**read-only**, through the same mechanism the client already uses for files without the `W`
permission. The server still grants `W`; this guard lives on the client. An owner who wants to
edit such a file removes the watermark, edits the clean copy, and applies the watermark again.

Whether to do this is a product decision, not a server rule. Each platform file describes the
mechanism.

### Use direct-download links for these types

`POST /ocs/v2.php/apps/dav/api/v1/direct` issues a `/remote.php/direct/{token}` link. Those
links are served by a separate DAV server that the app doesn't hook into, so **they deliver the
stored file without a watermark**. The mobile apps use direct links to stream audio and video.
Keep it that way: never request one for a PDF, JPEG, PNG or WebP. The rule keeps the official
clients from leaking; it doesn't stop a determined user.

### Download old versions of a watermarked file

`GET /remote.php/dav/versions/{user}/versions/{fileId}/{version}` is **not intercepted**. It
returns the old version without a watermark. Don't offer to download or preview versions of a
file with `is-watermarked=1`. Restoring a version is fine: the mark belongs to the file id,
which a restore keeps.

### Share cached copies between accounts

See [Previews](#4-previews). The same rule applies to downloaded and offline copies.

## 6. Server-declared context menu (Nextcloud 33 and later)

Nextcloud 33 (Hub 26 Winter) lets a server app declare file context-menu entries in its
capabilities, under `client_integration` → `context-menu`. Desktop 33.0.0+, Android 3.36.0+
and iOS 7.3.0+ show those entries with no app-specific client code. They call the declared OCS
endpoint with `{fileId}` or `{filePath}` filled in and display the returned tooltip. The spec is
at <https://docs.nextcloud.com/server/latest/developer_manual/client_apis/ClientIntegration/index.html>.

That would put Apply and Remove into stock clients, with limits:

- The server needs OCS wrappers for the two actions that answer with a translated `tooltip`,
  plus the capability entry.
- Entries are filtered by MIME type only, so both actions appear on every supported file, and
  the server's answer ("already watermarked", "only the owner…") does the gating.
- It draws no badge.
- This app is pinned to Nextcloud 31 (`max-version="31"`), and the API is documented from 33.

It's worth adding when the app moves to Nextcloud 33. It doesn't replace the client work in
this folder.

## 7. Test setup and shared checks

`docker compose up` in this repository starts Nextcloud 31 with the app enabled at
`http://localhost:8080` (user `admin`, password `admin`). Point a debug build of the client at
it. Emulators and devices need the host's LAN address, which has to be a trusted domain:

```sh
docker exec -u www-data files_watermark_nc php occ config:system:set trusted_domains 1 --value=192.168.1.20
```

To tell whether a download is watermarked: a watermarked PDF contains the subset font
`/BaseFont /XXXXXX+IBMPlexSansArabic`, so `grep -a -c IBMPlexSansArabic file.pdf` prints a
non-zero count. For images, compare against the original or look at them.

```sh
NC=http://localhost:8080
AUTH='alice:APP-PASSWORD'

# Status of everything in a folder
curl -s -u "$AUTH" -X PROPFIND -H 'Depth: 1' -H 'Content-Type: application/xml' \
  --data '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns"><d:prop><d:getetag/><d:getcontentlength/><oc:owner-id/><nc:is-watermarked/><nc:watermark-locked/></d:prop></d:propfind>' \
  "$NC/remote.php/dav/files/alice/Documents/"

# Download, show the headers that matter, check for the watermark
curl -s -u "$AUTH" -D - -o out.pdf "$NC/remote.php/dav/files/alice/Documents/report.pdf" \
  | grep -i -E '^(content-length|etag|oc-checksum):'
grep -a -c IBMPlexSansArabic out.pdf
```

To see the server's side of a client's requests, turn on debug logging
(`occ log:manage --level debug`). The app then logs every length it advertises in a PROPFIND
and every download it serves (`files_watermark: advertising a reserved length`,
`files_watermark: serving a watermarked copy`, `files_watermark: refusing: the render failed`),
with the client's `User-Agent` and `Range` header.

Every client should pass these checks. The platform files add their own.

| # | Setup | Expected |
| --- | --- | --- |
| 1 | Server without the app | No badge, no actions, no errors |
| 2 | Owner, unmarked PDF | No badge; Apply offered; the download equals the stored file |
| 3 | Owner applies the watermark | The badge appears at once; Apply disappears and Remove is offered; the next download is watermarked; the local copy and preview are replaced |
| 4 | Owner removes it | The reverse of 3; the next download equals the stored file |
| 5 | A recipient of a marked file, shared with edit rights | Badge shown; neither Apply nor Remove offered |
| 6 | The recipient copies that file into their own folder | The copy shows the badge and has `watermark-locked=1`; Remove isn't offered |
| 7 | Admin ticks *Always watermark files opened through an internal share*; a recipient opens an unmarked shared PDF | Badge for the recipient, not for the owner; watermarked download for the recipient only |
| 8 | A marked file is moved to the trash | Badge in the trash view, no actions, and a download from the trash is watermarked |
| 9 | A marked password-protected PDF (`qpdf --encrypt user owner 256 -- in.pdf out.pdf`) | The download fails with the server's message and isn't retried in a loop |
| 10 | A download of a marked file is interrupted, then resumed | It completes, the file opens, and the checksum check passes |
| 11 | 121 apply calls within a minute, from a script | The `429` is handled without looping |
| 12 | Two accounts on one device open the same shared marked file | Each sees only their own name, in previews and in downloads |
