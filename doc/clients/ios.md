# iOS app

For developers of the Nextcloud iOS app (Swift) and NextcloudKit. Read [README.md](README.md)
first: it defines the properties, endpoints and download behaviour this file builds on.

Type names below refer to upstream `nextcloud/ios` and `nextcloud/NextcloudKit` at the time of
writing. Check them against your branch.

## What already works, and what breaks

With no app changes, downloads, the viewer, previews, the Files app (through the File Provider
extension), *Open in*, the share sheet and *Save to Photos* all get watermarked copies: the
server does it.

What goes wrong without changes:

- **No badge and no actions.**
- **Edits made in another app are uploaded with the watermark in them.** A file opened from
  the Files app and saved back through the File Provider replaces the clean original with a
  rendering that names this reader.
- **A download the server refuses (`403`) can be retried again and again** by any automatic
  download (offline folders, favourites).
- **Cached copies and previews can cross accounts.** `ocId` is the same for every account on
  one server, so a cache keyed by it alone can show one account's watermark to another.
- **After a Remove, the local copy stays watermarked**, because the server changes no etag.

## 1. Listing: request and store the properties

NextcloudKit keeps the PROPFIND and SEARCH request bodies in `NKDataFileXML`, and parses the
responses into `NKFile` there. Add `<nc:is-watermarked/>` and `<nc:watermark-locked/>` to every
listing body: the folder listing, the SEARCH bodies (media, recent, favourites) and the trash
listing. Then parse them:

```swift
// Inside the per-<d:response> parsing, reading only the propstat whose status is 200.
// A server without the app returns these as empty elements in a separate 404 propstat;
// reading that one would report the feature as present.
let watermarked = prop["nc:is-watermarked"].element?.text
file.watermarkSupported = watermarked != nil
file.isWatermarked = watermarked == "1"
file.isWatermarkLocked = prop["nc:watermark-locked"].element?.text == "1"
```

In the app, add the fields to `tableMetadata`, bump the Realm schema version in
`NCManageDatabase`, and copy them over wherever an `NKFile` becomes a `tableMetadata`. Store
per account whether any listing has returned `is-watermarked` from a `200` propstat, and use
that to decide whether to show the actions at all.

## 2. Badge

- Show it in list and grid cells, next to the existing indicators for shared, favourite,
  offline and locked files, and in the viewer and the details view.
- Show it in the trash list, with no actions.
- Convert `img/app.svg` into a template image in the asset catalog (vector data preserved).
  Set its accessibility label to *Downloads and previews of this file are watermarked*.
- **The Files app can't show it.** File Provider item decorations
  (`NSFileProviderItemDecorating`) are macOS-only. Don't try to signal the status through the
  file name.

## 3. File actions

Add *Apply watermark* and *Remove watermark* to the file's action menu (built from
`NCMenuAction` upstream), for a single file only, with the gating from README section 2. The
iOS-specific inputs:

| Check | Source |
| --- | --- |
| Supported type | `tableMetadata.contentType` |
| Write permission (Apply) | `W` in `tableMetadata.permissions` |
| Owner (Remove) | `tableMetadata.ownerId` equals the account's `userId` (the id in its DAV URL), not its login name |
| Status | The two new `tableMetadata` fields |
| Not the trash, not a version, not a public link | Which screen the menu is built for |

Confirm with a `UIAlertController`, using the dialog text in README section 2. Add the strings
to the app's `Localizable.strings`; the Arabic translations can be copied from `l10n/ar.json` in
this repository.

`path` is relative to the account's files root, with a leading `/` and no percent-encoding. The
app already derives it for other calls (`NCUtilityFileSystem.getFileNamePath` upstream). The
call:

```swift
enum WatermarkAction: String {
    case apply
    case remove
}

struct WatermarkError: Error {
    let status: Int
    let message: String?   // already translated into the account's language
}

func watermark(_ action: WatermarkAction,
               path: String,
               serverURL: URL,
               user: String,          // the login name used for Basic auth
               appPassword: String,
               userAgent: String) async throws -> String {
    let url = serverURL.appendingPathComponent("index.php/apps/files_watermark/api/v1/\(action.rawValue)")
    var request = URLRequest(url: url)
    request.httpMethod = "POST"
    let credentials = Data("\(user):\(appPassword)".utf8).base64EncodedString()
    request.setValue("Basic \(credentials)", forHTTPHeaderField: "Authorization")
    request.setValue("true", forHTTPHeaderField: "OCS-APIRequest")   // without it: 412, CSRF check failed
    request.setValue("application/json", forHTTPHeaderField: "Accept")
    request.setValue("application/json", forHTTPHeaderField: "Content-Type")
    request.setValue(userAgent, forHTTPHeaderField: "User-Agent")    // the app's own, so server logs can tell it apart
    request.httpBody = try JSONSerialization.data(withJSONObject: ["path": path])

    let (data, response) = try await URLSession.shared.data(for: request)
    let status = (response as? HTTPURLResponse)?.statusCode ?? 0
    let json = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any]
    guard status == 200 else {
        throw WatermarkError(status: status, message: json?["error"] as? String)
    }
    // "watermarked", "already_watermarked", "removed" or "not_watermarked"
    return json?["status"] as? String ?? ""
}
```

After a successful call:

1. Update the `tableMetadata` fields and reload the cell, so the badge and the menu change at
   once.
2. Re-read the file with a `Depth: 0` PROPFIND (`readFileOrFolder`).
3. Delete the local copy and the cached previews of the file. If the file is kept offline,
   download it again. **After a Remove this is the only thing that replaces the watermarked
   copy**, because the server changes no etag.
4. Signal the File Provider enumerator for the file's parent, so the Files app re-reads the
   item and its capabilities (next section).

For a `429`, show the README wording and don't retry automatically.

## 4. File Provider extension

- **Capabilities.** For an item with `is-watermarked=1`, leave `.allowsWriting` out of
  `capabilities`, and keep reading, renaming, moving and deleting. The Files app then opens the
  file read-only, and other apps can't save back through the provider.
- **Upload path.** Also refuse, in the extension's upload path (`itemChanged(at:)`), to upload a
  change to a watermarked item, in case something writes to it anyway. Leave the local file as
  it is.
- **Size.** Take `documentSize` from `d:getcontentlength`, which the server corrects for
  watermarked files. If a fetched file's real size differs (an account's first download of a
  marked file), update the item rather than failing.
- **Version.** Take the version from the etag. For a watermarked file it belongs to the
  account, which is what you want.
- **Signal on change.** When a listing shows `is-watermarked` changed, signal the enumerator,
  so the system picks up the new capabilities.

## 5. Downloads and caches

- **Separate accounts.** Downloaded files and previews live in a per-file folder of the shared
  app-group container. If yours is keyed by `ocId` alone (upstream's provider storage is), two
  accounts on the same server share it. For watermarked files, include the account in the key,
  or delete the cached copy when another account opens the file.
- **`403` isn't transient.** Show the `s:message` from the response body. Keep automatic
  downloads (offline folders, favourites) from retrying the file until its etag changes.
- **Resuming.** `URLSession` resume data handles a `200` reply by downloading the whole file
  again. If you resume by hand with a `Range` header, treat a `200` without `Content-Range` as
  a full body.
- **Checksums and hashing.** Never compare a watermarked download against `oc:checksums`, and
  never hash content to deduplicate or detect changes. Every render is different.

## 6. Previews

The app's preview requests (`/index.php/core/preview?fileId=…`) come back stamped for the
account, with `Cache-Control: no-store`.

- The same per-account rule applies to previews saved on disk.
- **`404` means "use the icon".** Show the file-type icon, and don't request the preview again
  every time the cell appears.
- **Evict on status change.** When `is-watermarked` changes, drop the file's cached previews.
- The large preview the viewer shows before a file is downloaded is watermarked too.

## 7. Direct links and versions

- Direct links (NextcloudKit's `getDirectDownload`) serve the stored file without a watermark.
  Restrict them to audio and video streaming, and never request one for a PDF, JPEG, PNG or
  WebP file.
- Restoring a version is fine. If your branch can download or preview old versions, hide that
  for watermarked files: versions are served without a watermark.

## 8. iOS checks

On top of the shared checks in README section 7:

| # | Setup | Expected |
| --- | --- | --- |
| I1 | Open a marked PDF from the Files app in another app, annotate, save | It can't be saved over the original; nothing is uploaded |
| I2 | Browse the same folder in the Files app | The marked file is listed and opens read-only; there is no badge (expected) |
| I3 | Apply the watermark in the app | Badge at once; the local copy and previews are replaced; the Files app item becomes read-only |
| I4 | Remove the watermark in the app | The local copy is the clean original at once, and the Files app item is writable again |
| I5 | Two accounts on the same server, both able to see the same marked image | Each account's preview and downloaded copy carry its own name |
| I6 | A marked password-protected PDF in an offline folder | One failure with the server's message; no repeated attempts |
| I7 | The media tab with marked images | Badges shown; previews watermarked |
| I8 | Play a video in a folder full of marked files | Streaming still works; no direct link is requested for the marked files |
