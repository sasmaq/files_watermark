# Clients and Client APIs

> https://docs.nextcloud.com/server/31/developer_manual/client_apis/

APIs consumed by desktop/mobile/web clients and external integrations: OCS, WebDAV, Files
events, Login Flow, Activity, and Remote Wipe.

## General

- Most APIs authenticate with **Basic Auth** using username + **app password**
  (never the login password) over HTTPS, or via an OAuth2 bearer token.
- OCS endpoints require the header `OCS-APIRequest: true`.
- Add `?format=json` (or `Accept: application/json`) to OCS calls for JSON responses
  (default is XML).
- Error handling: OCS wraps status in a `<meta>` block; HTTP status codes also apply on
  the `/ocs/v2.php` path.

## OCS API

Open Collaboration Services — REST-ish endpoints under
`/ocs/v1.php/...` (legacy) or `/ocs/v2.php/...` (preferred). Apps expose their own OCS
endpoints via `OCSController` + an `ocs` block in `routes.php`
(see [02-basic-concepts.md](02-basic-concepts.md#routing)).

Built-in OCS surfaces include:

- **Sharing** — `/ocs/v2.php/apps/files_sharing/api/v1/shares` (create/list/update/delete
  shares; public links, user/group shares, permissions, expiration).
- **User preferences / provisioning** — `/ocs/v2.php/cloud/users`, `/cloud/user`.
- **Capabilities** — `/ocs/v2.php/cloud/capabilities` (feature/version discovery).
- **Text processing, translation, search, task processing** — AI and unified-search
  endpoints.

Example (create a public share):

```bash
curl -u user:app-password \
  -H 'OCS-APIRequest: true' \
  -X POST 'https://cloud.example.com/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json' \
  -d path=/Photos/img.png -d shareType=3   # 3 = public link
```

## WebDAV

File access lives under `/remote.php/dav/`:

- **Files:** `/remote.php/dav/files/<userid>/<path>` — GET/PUT/DELETE/MKCOL/MOVE/COPY,
  PROPFIND/PROPPATCH for properties (including `nc:metadata-*`, see
  [05-digging-deeper.md](05-digging-deeper.md#files-metadata)).
- **Chunked / bulk upload** for large files.
- **Versions:** `/remote.php/dav/versions/<userid>/...`.
- **Trash bin:** `/remote.php/dav/trashbin/<userid>/...` (list/restore/purge).
- Also: calendars (CalDAV) and contacts (CardDAV) under `/remote.php/dav/`.

```bash
# download a file
curl -u user:app-password \
  'https://cloud.example.com/remote.php/dav/files/user/Documents/report.pdf' -o report.pdf
```

## Files (events & routing)

Client-side Files app integration uses the `@nextcloud/files` npm package:

- `registerFileAction(new FileAction({ id, displayName, iconSvgInline, enabled, exec }))`
  to add context-menu / row actions (used by the watermark app's `main-files.js`).
- `enabled(nodes)` should return true only for supported MIME types and a single
  selection (`nodes.length === 1`).
- Subscribe to file events / trigger a list reload after an action via the Files router /
  `emit('files:node:updated', node)` so timestamps refresh.

## Login Flow

Obtain an app password without exposing the user's real credentials:

- **Login Flow v2** (recommended): `POST /index.php/login/v2` returns a `login` URL (open
  in browser/webview) and a `poll` token; poll `POST /index.php/login/v2/poll` until the
  user authorizes, then receive `server`, `loginName`, and `appPassword`.
- Convert/manage app passwords via OCS; supports webview-based flows for mobile.

## Activity

The Activity app exposes a feed via OCS
(`/ocs/v2.php/apps/activity/api/v2/activity`) — list recent events (filters, since/limit),
useful for clients showing a timeline.

## Remote Wipe

For lost/stolen devices: a client checks wipe status and, when flagged, erases local data:

- `POST /index.php/core/wipe/check` with the device token → `{ "wipe": true }` when a wipe
  is requested.
- `POST /index.php/core/wipe/success` to acknowledge completion (revokes the token).

---

See [03-app-development.md](03-app-development.md#navigation--initial-state) for registering
in-app UI, and [05-digging-deeper.md](05-digging-deeper.md#rest-apis--cors) for exposing
CORS-enabled REST endpoints to external clients.
