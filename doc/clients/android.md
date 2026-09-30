# Android app

For developers of the Nextcloud Android app (Kotlin and Java) and its `android-library`. Read
[README.md](README.md) first: it defines the properties, endpoints and download behaviour this
file builds on.

Class names below refer to upstream `nextcloud/android` and `nextcloud/android-library` at the
time of writing. Check them against your branch.

## What already works, and what breaks

With no app changes, downloads, files opened in other apps, the Storage Access Framework (SAF)
provider, thumbnails and image previews all come back watermarked: the server does it.

What goes wrong without changes:

- **No badge and no actions.**
- **Edits made in another app are uploaded with the watermark in them**, through the SAF
  provider or through the sync of offline files. The stored file then names this reader.
- **A download the server refuses (`403`) can be retried repeatedly** by background workers.
- **Thumbnails can show the wrong name** when two accounts on the same server are signed in on
  one device, because file ids are the same for both.
- **After a Remove, the local copy stays watermarked**, because the server changes no etag.

## 1. Listing: request and store the properties

`android-library` builds PROPFIND requests in two ways, and the properties need to be added to
both:

- **Jackrabbit**: the property set comes from `WebdavUtils` (`getAllPropSet()` and
  `getFilePropSet()`) and is parsed in `WebdavEntry`.
- **dav4jvm** (the `NextcloudClient` operations): each property is a class with a factory
  registered in `PropertyRegistry`.

Jackrabbit:

```java
// WebdavUtils: every property set used for file listings
propSet.add(EXTENDED_PROPERTY_IS_WATERMARKED, ncNamespace);    // "is-watermarked"
propSet.add(EXTENDED_PROPERTY_WATERMARK_LOCKED, ncNamespace);  // "watermark-locked"

// WebdavEntry: only 200 propstats are read, so null means the server lacks the app
DavProperty<?> prop = propSet.get(EXTENDED_PROPERTY_IS_WATERMARKED, ncNamespace);
if (prop != null) {
    watermarkSupported = true;
    watermarked = "1".equals(prop.getValue());
}
prop = propSet.get(EXTENDED_PROPERTY_WATERMARK_LOCKED, ncNamespace);
watermarkLocked = prop != null && "1".equals(prop.getValue());
```

dav4jvm, following the shape of the existing Nextcloud properties in the library (the factory
API differs between dav4jvm versions, so copy whichever one yours uses):

```kotlin
data class NCWatermarked(val watermarked: Boolean) : Property {
    class Factory : PropertyFactory {
        override fun getName() = NAME
        override fun create(parser: XmlPullParser) = NCWatermarked(XmlUtils.readText(parser) == "1")
    }

    companion object {
        @JvmField
        val NAME = Property.Name("http://nextcloud.org/ns", "is-watermarked")
    }
}

// The same for "watermark-locked", then, where the other factories are registered:
PropertyRegistry.register(listOf(NCWatermarked.Factory(), NCWatermarkLocked.Factory()))
```

Also add both properties to the `SEARCH` requests (`SearchRemoteOperation`, which gallery,
recent and favourites use) and to the trash listing, which has its own PROPFIND
(`ReadTrashbinFolderRemoteOperation`).

Then carry the values from `RemoteFile` to `OCFile`, and persist them in the files table (a new
column on the Room `FileEntity`, with a migration). Record per account, for example in
`ArbitraryDataProvider`, whether any listing has returned `is-watermarked` with a `200`, and use
that flag to decide whether to show the actions at all.

## 2. Badge

- Show it in list and grid rows, next to the existing indicators for shared, favourite,
  encrypted and locked files (bound in `OCFileListDelegate` and `OCFileListAdapter`
  upstream).
- Show it in the file details screen and in the image and PDF preview screens.
- Show it in the trash list, with no actions.
- Import `img/app.svg` as a vector drawable (*New → Vector Asset → Local file* in Android
  Studio). Set the content description to *Downloads and previews of this file are
  watermarked*.

## 3. File actions

`FileMenuFilter` decides which actions appear in a file's action sheet and in the multi-select
toolbar. Add *Apply watermark* and *Remove watermark* for single-file selections only, with the
gating from README section 2. The Android-specific inputs:

| Check | Source |
| --- | --- |
| Supported type | `OCFile.mimeType` |
| Write permission (Apply) | `W` in `OCFile.permissions` |
| Owner (Remove) | `OCFile.ownerId` equals the account's user id: the id in its DAV URL (`KEY_USER_ID` in the account's user data), not the account name |
| Status | The two new `OCFile` fields |
| Not the trash, not a version, not a public link | Which screen the menu is built for |

Add the strings to `res/values/strings.xml` and the translations to the `values-*` folders. The
Arabic ones can be copied from `l10n/ar.json` in this repository. Confirm with a
`MaterialAlertDialogBuilder`, using the dialog text in README section 2.

The call itself, off the main thread, with the account's OkHttp client (already authenticated
with its app password). If your `android-library` version has a `PostMethod` that can add the
`OCS-APIRequest` header for you, that works too.

```kotlin
suspend fun watermarkAction(
    http: OkHttpClient,
    baseUrl: String,     // the account's server URL, no trailing slash
    action: String,      // "apply" or "remove"
    remotePath: String,  // OCFile.remotePath: relative to the files root, starts with "/"
): Result<String> = withContext(Dispatchers.IO) {
    val body = JSONObject().put("path", remotePath).toString()
        .toRequestBody("application/json".toMediaType())
    val request = Request.Builder()
        .url("$baseUrl/index.php/apps/files_watermark/api/v1/$action")
        .header("OCS-APIRequest", "true") // without it: 412, CSRF check failed
        .header("Accept", "application/json")
        .post(body)
        .build()

    http.newCall(request).execute().use { response ->
        val json = runCatching { JSONObject(response.body?.string().orEmpty()) }.getOrNull()
        if (response.isSuccessful) {
            // "watermarked", "already_watermarked", "removed" or "not_watermarked"
            Result.success(json?.optString("status").orEmpty())
        } else {
            // The message is already translated into the account's language.
            Result.failure(WatermarkException(response.code, json?.optString("error")))
        }
    }
}
```

After a successful call:

1. Update the `OCFile` flags in the database and refresh the row, so the badge and the menu
   change at once.
2. Re-read the file (`ReadFileRemoteOperation`, or refresh the parent folder).
3. Evict the file's thumbnail and resized preview from `ThumbnailsCacheManager`, in memory and
   on disk.
4. Replace the local copy. Delete it (keeping the database row), and if the file is kept
   offline, queue a new download. **After a Remove this is the only thing that replaces the
   watermarked copy**, because the server changes no etag.

For a `429`, show the README wording and don't retry automatically.

## 4. Downloads

- **`403` isn't transient.** In `FileDownloadWorker`, the offline-files sync and any other
  worker that downloads, return `Result.failure()` rather than `Result.retry()` for a `403`.
  Show the `s:message` from the response body, and try again only after the file's etag
  changes.
- **Sizes can differ once.** If any code compares the downloaded size with `OCFile.fileLength`
  (from PROPFIND), expect a mismatch on an account's first download of a marked file. Refresh
  the metadata; don't report it as a corrupt download.
- **Checksums.** Validate against the `OC-Checksum` response header, never against
  `oc:checksums` from the listing.
- **Resuming.** If your download code retries with `Range`, treat a `200` without
  `Content-Range` as a full body and restart the file from byte 0.
- **No content hashing** to deduplicate or detect changes. Every render is different.

## 5. Stop edits from flowing back

This is the recommended guard from README section 5, applied to the Android paths that write to
a downloaded file.

**The SAF provider** (`DocumentsStorageProvider`):

- In the cursor row for a watermarked file, leave out `Document.FLAG_SUPPORTS_WRITE`.
- In `openDocument`, refuse any mode containing `w` for a watermarked file, and throw rather
  than returning a read-only descriptor for a write request.

**Opening in another app**: for a watermarked file, send `ACTION_VIEW` with
`FLAG_GRANT_READ_URI_PERMISSION` only. Never `ACTION_EDIT`, and never
`FLAG_GRANT_WRITE_URI_PERMISSION`.

**Offline files**: when sync finds a local change to a watermarked file (the upload branch of
`SynchronizeFileOperation` upstream), don't upload it. Keep the local file and report a
conflict that tells the user to remove the watermark first or save the change as a new file.

## 6. Thumbnails and previews

The app's preview requests (`/index.php/core/preview?fileId=…`, and the older thumbnail API)
come back stamped for the account, with `Cache-Control: no-store`.

- **Key the disk cache by account.** `ThumbnailsCacheManager` keys by the file's remote id, and
  `oc:id` is the same for every account on one server. Add the account to the key, at least for
  watermarked files, or keep their thumbnails in memory only.
- **Evict on status change.** When `is-watermarked` changes in a listing, drop the file's
  thumbnail and resized preview.
- **`404` means "use the icon".** Show the file-type icon, and don't request the preview again
  on every bind.

## 7. Direct links and versions

- Upstream uses direct links (`StreamMediaFileOperation`) only to stream audio and video. Add a
  guard so they are never requested for PDF, JPEG, PNG or WebP files: those links serve the
  stored file without a watermark.
- Restoring a version is fine. If your branch can download or preview old versions, hide that
  for watermarked files: versions are served without a watermark.

## 8. Android checks

On top of the shared checks in README section 7:

| # | Setup | Expected |
| --- | --- | --- |
| A1 | Open a marked PDF from the SAF picker in another app, edit it, save | The editor can't save over it; nothing is uploaded |
| A2 | Share a marked file to another app | That app gets read access only |
| A3 | Mark a file that is kept offline, from the web UI | After the next sync of that folder, the offline copy is the watermarked version |
| A4 | Remove the watermark from the app on an offline file | The offline copy is replaced by the clean original at once |
| A5 | A marked password-protected PDF kept offline | One failure with the server's message; no repeated attempts in the background |
| A6 | Two accounts on the same server, both able to see the same marked image | Each account's thumbnail and preview carry its own name |
| A7 | Gallery (media search) with marked images | Badges shown; previews watermarked |
| A8 | Play a video in a folder full of marked files | Streaming still works; no direct link is requested for the marked files |
