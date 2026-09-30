# Windows desktop client

For developers of the Nextcloud desktop client (C++/Qt), Windows build. Read
[README.md](README.md) first: it defines the properties, endpoints and download behaviour this
file builds on.

Class and file names below refer to upstream `nextcloud/desktop` at the time of writing. Check
them against your branch. Discovery, read-only copies and the menu apply to the macOS and Linux
builds as well; the virtual-files and Explorer sections are Windows-only.

## What already works, and what breaks

With no client changes, sync and hydration already download watermarked copies. Marking a file
changes its etag on the server, so the next sync picks up the watermarked version (a download
in classic mode, new placeholder metadata with virtual files), and the server pads each
download to the length the listing promised, so hydration fits.

What goes wrong without changes:

- **Local edits are uploaded with the watermark in them.** The local copy is a watermarked
  rendering; saving it uploads that rendering over the clean original. See
  [section 2](#2-keep-local-copies-read-only).
- **No badge and no menu entries.**
- **After a Remove, the local copy stays watermarked.** The server doesn't change any etag when
  a mark is removed, so discovery never looks at the file again.
- **With virtual files, some first opens fail once** with *The cloud operation is invalid*:
  a share recipient's first open of a marked file, and the first open after the admin saves the
  policy. The next sync repairs it. See [section 3](#3-virtual-files-cfapi).

## 1. Discovery: request and keep the properties

Add three properties to the listing request in `LsColJob::defaultProperties()`:

```cpp
props << QByteArrayLiteral("http://nextcloud.org/ns:is-watermarked")
      << QByteArrayLiteral("http://nextcloud.org/ns:watermark-locked")
      << QByteArrayLiteral("http://owncloud.org/ns:owner-id"); // if your branch doesn't already ask for it
```

Parse them in `LsColJob::propertyMapToRemoteInfo()`. Upstream's parser only keeps properties
from a `200` propstat, so a missing key means the server doesn't have the app:

```cpp
if (map.contains(QStringLiteral("is-watermarked"))) {
    result.watermarkSupported = true;
    result.isWatermarked = map.value(QStringLiteral("is-watermarked")) == QLatin1String("1");
    result.watermarkLocked = map.value(QStringLiteral("watermark-locked")) == QLatin1String("1");
}
result.ownerId = map.value(QStringLiteral("owner-id"));
```

Then carry the fields from `RemoteInfo` on `SyncFileItem`, and persist them on
`SyncJournalFileRecord` as new columns in the journal's `metadata` table (a schema update in
`SyncJournalDb`, the way the lock and end-to-end encryption columns were added). The Explorer
menu and the badge are answered from the journal, not from a live PROPFIND.

Record at account level whether any listing has returned `is-watermarked` with a `200`. Use
that to decide whether to show the menu entries at all.

Things discovery must not do with a watermarked file:

- **Compare `oc:checksums` with the local file.** That checksum describes the stored file, and
  a local watermarked copy never matches it. If your branch uses this comparison to avoid
  conflict copies when the journal has no record (a new sync connection onto a folder that
  already holds files, or a journal reset), skip it when `isWatermarked` is set. Otherwise every
  watermarked file in the folder becomes a conflict.
- **Treat a changed `d:getetag` as a content edit worth warning about.** For a marked file it
  also changes when the server re-measures the download. Download it like any other remote
  change.

## 2. Keep local copies read-only

This is the recommended guard against uploading a watermarked rendering. It's a product
decision; README section 5 explains the trade-off.

Upstream already makes a local file read-only when the server withholds write permission
(`RemotePermissions::CanWrite`, applied through `FileSystem::setFileReadOnlyWeak`). Treat
`isWatermarked` like a missing `CanWrite` for files, and nothing else: the server still grants
write permission for renames, moves and deletes, and those are safe.

On Windows that sets the read-only attribute, so editors open the file read-only and offer
*Save As*.

If the user clears the attribute and edits the file anyway, **don't upload the change**. Handle
it like a local edit under a read-only share (upstream: the permission checks in
`ProcessDirectoryJob::checkPermissions`): keep the user's file, don't overwrite it, and report a
sync error that says what to do, for example *"This file is watermarked. Changes to the
downloaded copy are not uploaded. Remove the watermark first, or save your changes as a new
file."*

The owner's workflow for editing a marked file is then: *Remove watermark* in Explorer, wait for
the clean copy, edit, *Apply watermark*.

## 3. Virtual files (CfAPI)

The client sizes a placeholder from `d:getcontentlength`, and hydration has to deliver exactly
that many bytes. The server keeps its side of this: for a marked file, the listing reports the
length the download will have, and each render is padded to it.

It can't do that for a download it has no measurement for:

- an account's first download of a marked file, usually a share recipient's (the owner's is
  measured when the file is marked);
- the first download after the admin saves the policy, which invalidates every measurement;
- suspected, not reproduced: the first download after *another* account downloaded the same
  file for the first time.

In those cases the body's length differs from the placeholder and Windows fails the open with
*The cloud operation is invalid* (`ERROR_CLOUD_FILE_INVALID_REQUEST`). The server has already
measured the file and changed its etag by the time the response headers arrive, so the next
sync repairs the placeholder.

Make the repair immediate instead of waiting for the next sync. In the hydration path
(`src/libsync/vfs/cfapi/hydrationjob.cpp` upstream):

1. When the `GET` response arrives, compare its `Content-Length` with the placeholder's size
   **before** transferring any data.
2. If they differ, discard the body and fail the fetch cleanly.
3. Send a `Depth: 0` PROPFIND for the file. It now reports the measured length and the new
   etag.
4. Update the placeholder (size and etag) and its journal record from that answer, then
   hydrate again. Either start the hydration yourself or tell the user to open the file again;
   the next attempt fits.

Also:

- **Never size a placeholder from `HEAD`.** It reports the stored size.
- **Don't hydrate by byte range.** A fetch callback can ask for part of a file, but the server
  ignores `Range` for watermarked files and always sends the whole file. Upstream downloads the
  whole file for every hydration; keep it that way.
- **Hydrated and pinned files are re-downloaded when their etag changes**, the same as in
  classic sync.

## 4. Badge in Explorer

**With virtual files**, use Cloud Files custom states. They appear in Explorer's *Status*
column next to the sync state, and they are the only badge mechanism that doesn't compete with
other sync apps:

```cpp
using namespace winrt::Windows::Storage::Provider;

// Once, while building the StorageProviderSyncRootInfo that registers the sync root
// (cfapiwrapper.cpp upstream):
StorageProviderItemPropertyDefinition watermarkDefinition;
watermarkDefinition.Id(kWatermarkStateId);            // any id unique within this sync root
watermarkDefinition.DisplayNameResource(L"Watermarked");
info.StorageProviderItemPropertyDefinitions().Append(watermarkDefinition);

// For one file, whenever its flag changes. An empty list clears the state.
winrt::Windows::Foundation::IAsyncAction setWatermarkState(std::wstring path, bool watermarked)
{
    const auto file = co_await winrt::Windows::Storage::StorageFile::GetFileFromPathAsync(path);
    std::vector<StorageProviderItemProperty> properties;
    if (watermarked) {
        StorageProviderItemProperty state;
        state.Id(kWatermarkStateId);
        state.Value(L"Downloads and previews of this file are watermarked");
        state.IconResource(kWatermarkIconResource);   // e.g. L"<install dir>\\nextcloud.exe,-<icon id>"
        properties.push_back(state);
    }
    co_await StorageProviderItemProperties::SetAsync(file, std::move(properties));
}
```

This is a sketch: it hasn't been built against the client. Microsoft's *CloudMirror* Cloud
Files sample shows the same calls in working code. Translate the value string, and build the
icon from `img/app.svg`.

Set the state after discovery writes a changed flag to the journal, and right after the
client's own Apply or Remove.

**In classic sync mode**, don't add an overlay icon. Windows has 15 overlay slots shared by
every installed app, and the sync clients already compete for them. Show the status as a
disabled line in the context menu instead (next section), the way upstream shows *Locked by …*.

## 5. Explorer context menu

The shell extension asks the running client for menu entries over the local socket, and
`SocketApi::command_GET_MENU_ITEMS` answers with lines in the form
`MENU_ITEM:<ACTION>:<flags>:<label>` (flag `d` means disabled). When the user picks an entry,
the extension sends `<ACTION>:<path>` back, and `SocketApi` dispatches it by name to
`command_<ACTION>(const QString &, SocketListener *)`.

Add the entries only when exactly one file is selected, and gate them from its journal record,
following README section 2:

```cpp
// In SocketApi::command_GET_MENU_ITEMS, for a single selected file
const auto record = fileData.journalRecord();
const auto account = fileData.folder->accountState()->account();
if (record.isValid() && record.isFile() && account->watermarkSupported()
    && hasWatermarkableExtension(fileData.localPath)) {

    if (record._isWatermarked) {
        listener->sendMessage(QStringLiteral("MENU_ITEM:WATERMARK_STATUS:d:")
            + tr("Downloads and previews of this file are watermarked"));
    }
    if (!record._isWatermarked && record._remotePerm.hasPermission(RemotePermissions::CanWrite)) {
        listener->sendMessage(QStringLiteral("MENU_ITEM:APPLY_WATERMARK::") + tr("Apply watermark"));
    }
    if (record._isWatermarked && !record._watermarkLocked && record._ownerId == account->davUser()) {
        listener->sendMessage(QStringLiteral("MENU_ITEM:REMOVE_WATERMARK::") + tr("Remove watermark"));
    }
}
```

The journal doesn't store content types, so `hasWatermarkableExtension` matches `.pdf`, `.jpg`,
`.jpeg`, `.png` and `.webp`, case-insensitively. That is also how the server derives the type.

The handlers confirm with the user (README has the dialog strings), then call the API. A sketch
using the account's authenticated network access:

```cpp
void SocketApi::command_APPLY_WATERMARK(const QString &localFile, SocketListener *)
{
    const auto fileData = FileData::get(localFile);
    if (!fileData.folder || !confirmWatermarkAction(fileData, WatermarkAction::Apply)) {
        return;
    }
    sendWatermarkRequest(fileData, QStringLiteral("apply"));
}

static void sendWatermarkRequest(const FileData &fileData, const QString &action)
{
    const auto account = fileData.folder->accountState()->account();
    const auto url = Utility::concatUrlPath(account->url(),
        QStringLiteral("index.php/apps/files_watermark/api/v1/") + action);

    // Relative to the account's files root, with exactly one leading slash.
    auto path = fileData.serverRelativePath;
    if (!path.startsWith(QLatin1Char('/'))) {
        path.prepend(QLatin1Char('/'));
    }

    QNetworkRequest request;
    request.setRawHeader("OCS-APIRequest", "true");   // without it: 412, CSRF check failed
    request.setRawHeader("Accept", "application/json");
    request.setHeader(QNetworkRequest::ContentTypeHeader, "application/json");

    auto body = new QBuffer;
    body->setData(QJsonDocument(QJsonObject{{QStringLiteral("path"), path}}).toJson(QJsonDocument::Compact));

    const auto reply = account->sendRawRequest("POST", url, request, body);
    body->setParent(reply);
    QObject::connect(reply, &QNetworkReply::finished, reply, [reply, fileData, action] {
        reply->deleteLater();
        const auto status = reply->attribute(QNetworkRequest::HttpStatusCodeAttribute).toInt();
        const auto json = QJsonDocument::fromJson(reply->readAll()).object();
        if (status != 200) {
            showWatermarkError(json.value(QStringLiteral("error")).toString(), status); // 429: README wording
            return;
        }
        onWatermarkActionSucceeded(fileData, action);
    });
}
```

`FileData::serverRelativePath` should be the sync folder's remote path joined with the file's
path inside the folder. Confirm that on your branch, including for a sync folder connected to a
remote subfolder.

After a successful call:

- **Apply:** set the journal flags and the Explorer state, then schedule a sync of the folder
  (`FolderMan::scheduleFolder`). The server has changed the file's etag, so the sync downloads
  the watermarked copy and section 2 makes it read-only.
- **Remove:** set the flags, clear the state and the read-only attribute, then **force the
  re-download yourself**. The server changes no etag on removal, so an ordinary sync skips the
  file. Schedule the parent path for remote discovery
  (`SyncJournalDb::schedulePathForRemoteDiscovery`), invalidate the file record's etag so
  discovery sees a difference, and schedule the sync. With a dehydrated placeholder, that only
  updates its metadata.

Handle status codes as in README section 2. For `403`, also refresh the file's properties: the
record was probably stale.

## 6. Other download paths

The sync engine fetches files with WebDAV `GET` only, and those are covered. Keep it that way:

- Don't fetch files through direct-download links.
- If your branch adds a version browser, don't offer version downloads for watermarked files;
  they are served clean (README section 5).

## 7. Troubleshooting

| Symptom | Likely cause | What to do |
| --- | --- | --- |
| *The cloud operation is invalid*, once, then fine | A download the server had no measurement for (section 3) | Expected. Section 3's handling repairs it at once |
| The same error on every open | PROPFIND and `GET` disagree on the server, typically because the web server still runs cached code from before an upgrade | Restart PHP-FPM or the web server. With debug logging on, the server log should show `files_watermark: advertising a reserved length` for the file |
| A shared marked file re-downloads on every sync for two users | Suspected server issue | Collect the server debug log for both users and report it |
| Conflict copies of watermarked files after a journal reset | Checksum comparison with `oc:checksums` (section 1) | Skip that comparison for watermarked files |
| The local copy stays watermarked after a Remove | The server changes no etag on removal | Force the re-download (section 5) |
| Download refused with `403` | The server couldn't generate the watermark | Show the server's message and don't retry (README section 3) |

Server-side debug logging is described in README section 7. Each entry records the client's
`User-Agent`, which tells desktop requests apart from the rest.

## 8. Windows checks

On top of the shared checks in README section 7:

| # | Setup | Expected |
| --- | --- | --- |
| W1 | Virtual files; the owner marks a dehydrated file in the web UI | After the next sync the placeholder has the new size, the badge shows in *Status*, and the first open succeeds |
| W2 | Virtual files; a recipient opens a shared marked file for the first time | At most one failed open, and the second open works without waiting for a sync |
| W3 | Virtual files; the admin saves the policy; open a marked file | Same as W2 |
| W4 | Classic sync; the owner marks a synced file | The local file is replaced with the watermarked copy and becomes read-only |
| W5 | The user clears the read-only attribute and edits a watermarked file | Nothing is uploaded; a clear sync error; the edit is kept |
| W6 | Right-click a marked file you own | *Remove watermark* and the status line offered; *Apply watermark* not |
| W7 | *Remove watermark* from Explorer | Within one sync, and with no other change in the folder, the local copy is the clean original and is writable again |
| W8 | Right-click your copy of someone else's marked file | Status line only; no Remove |
| W9 | Add a sync connection onto a local folder that already holds watermarked copies | No conflict copies |
| W10 | A multi-file selection in Explorer | No watermark entries |
