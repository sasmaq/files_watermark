# files_watermark

A Nextcloud 31 app that applies configurable watermarks to PDF and image files. Watermarks
embed user identity information (display name, account name, date, email) to deter
unauthorized distribution and provide traceability.

**The stored file is never modified.** Marking a file records a policy against it; the
watermark itself is drawn on a temporary copy each time the file is downloaded or
previewed, and it names **whoever is fetching it** - the owner, a share recipient, or the
owner of the link an anonymous visitor came in through. Two people downloading the same
file get two different documents, which is the one thing a watermark burned into the
content can never do: it can only name the person who triggered it, which for a shared
document is whoever uploaded it rather than whoever walked out with it.

## Features

- **Text watermarks** with customizable templates - `{displayname}`, `{username}`, `{email}`,
  `{date}`, `{datetime}`, `{filename}`
  - `{displayname}` is the name shown in Nextcloud (*John Doe*); `{username}` is the account
    name used to sign in (*john.doe*). Display names are neither unique nor permanent, so use
    the account name when the watermark has to identify exactly one account
  - `{date}` and `{datetime}` are rendered in the instance's timezone - `default_timezone`
    in `config.php`, the same setting the rest of Nextcloud dates by. Set it, or the
    watermark reads UTC: Nextcloud pins PHP's own timezone to UTC while it boots, whatever
    the server is set to. The format is `YYYY-MM-DD` and `YYYY-MM-DD HH:MM:SS`
- **Image watermarks** - overlay a logo or image on files
- **Combined** text + image watermarks
- Diagonal tiled placement at 45° rotation, mid grey (`#808080`) and 40% opacity by default
- Two trigger modes, deciding **which files are marked**: **on demand** (the file action
  menu) and **on upload** (every supported file, as it arrives). Under both, a marked file
  is watermarked on every download *and* every preview
- Two **share** switches, deciding which *fetches* are watermarked whether or not the file
  is marked: **internal shares** and **public links** - see
  [Watermarking shared files](#watermarking-shared-files)
- Global policy configurable by admins under **Settings → Additional → Watermark Settings**
- Full audit log of every watermark event
- Supports PDF, JPEG, PNG, and WEBP files
- PDF rendering via tc-lib-pdf, which reads PDF 1.5+ documents natively; image rendering
  via GD by default, with Imagick used for anything GD cannot decode

## Requirements

| Dependency | Version |
| --- | --- |
| Nextcloud | 31.x |
| PHP | 8.2 or 8.3 |
| PHP extension | `gd` (required; the image renderer - `imagick` optional, see below) |
| PHP extension | `bcmath` (required by the PDF renderer) |
| Composer | 2.x |
| Node.js | >= 20 |
| npm | >= 10 |

### Image rendering: GD by default, Imagick where GD cannot go

JPEG, PNG and WEBP are watermarked with **GD**, which ships with essentially every PHP
build and which Nextcloud server already requires. It is a declared requirement of this app
(`composer.json` and `appinfo/info.xml`), so a host without it is refused at install rather
than at the first image. **Imagick is used when GD cannot decode the file** - in practice
that means WebP on a GD compiled without libwebp. Nothing to configure either way.

The preference used to run the other way round, and it was flipped for the same reason the
app spawns no external processes: two servers running the same configuration should produce
the same file. Imagick is optional on every platform and EPEL-only on RHEL 9, so preferring
it meant the output depended on how the host happened to be packaged.

**No host fonts are needed.** Both engines draw with the font bundled in `resources/fonts`,
so a minimal container renders exactly what a full one does. This used to walk a list of
system fonts and fall back to GD's built-in bitmap font when it found none.

### No external binaries

The app runs entirely inside PHP. It spawns no processes - no `exec()`, no shelling out
to `qpdf`, `pdftoppm`, Ghostscript or anything else - so there is nothing to install
beyond the PHP extensions above, and nothing that behaves differently because a host is
missing a package.

Two consequences worth knowing:

- **PDF 1.5+ documents with a compressed cross-reference table are watermarked normally**,
  with no configuration. Most modern producers emit these, including whatever wrote two of
  the three sample PDFs Nextcloud places in every new account. The renderer reads them
  natively.
- **Encrypted PDFs are skipped.** The renderer declines every encrypted document, and that
  includes files "encrypted" with an empty password purely to set permission flags, which
  are not really protected and open without prompting. Such a file is left exactly as it
  was, an entry is written to the audit log, and an on-demand apply returns an error naming
  it. Nothing is corrupted and no unwatermarked copy is served in place of a watermarked
  one - the watermark simply does not get applied.

The watermark is a real content stream, so **the text layer survives**: selection, copy,
search and screen-reader access all keep working. The user's file is never modified.

A marked file whose watermark cannot be generated is **not served at all** - the download
answers 403 rather than handing back the stored bytes. That is the point of a mark: the
alternative gives the clean file to precisely the reader the watermark exists to name, and
does it silently.

### Fonts and Arabic text

**One font draws every watermark** - IBM Plex Sans Arabic Bold (SIL Open Font License),
committed in `resources/fonts`. Latin and Arabic render in the same face, so a watermark
looks the same whatever the text contains.

**Arabic is shaped and reordered** before it is drawn: letters take their contextual forms,
lam-alef becomes a single ligature, and the text runs right to left. That happens for PDFs
and images alike, in PHP rather than in the image backend, so output does not depend on
whether the host's ImageMagick was built with Raqm/HarfBuzz - or on which fonts the host
has installed at all.

The font is embedded **subsetted**: only the glyphs actually drawn. A watermarked PDF is
about 31 KB rather than the 125 KB a whole embedded face would cost, and `/ToUnicode` is
still written so the watermark stays searchable and selectable.

If the bundled font cannot be read, the file is **skipped with an audit-log entry** rather
than watermarked with substitute glyphs - an unreadable watermark is worse than a missing
one, because it looks like it worked.

See `resources/fonts/README.md` for why this face and not another (the constraint is Arabic
Presentation Forms-B coverage, which rules out most modern Arabic fonts including Cairo),
and before moving anything in that directory - the path reaches the renderer through the
global `K_PATH_FONTS`.

## Installation

### 1. PHP dependencies

```bash
composer install
```

### 2. Frontend dependencies

The app targets **Vue 3** with **`@nextcloud/vue` v9** (its Vue 3 line), so the
dependency tree resolves cleanly:

```bash
npm install
```

> **Note:** avoid `--legacy-peer-deps` - it skips auto-installing the peer
> dependencies that `@nextcloud/eslint-config` needs (e.g. `eslint-plugin-import`)
> and will break `npm run lint`.

### 3. Build frontend assets

```bash
npm run build
```

### 4. Enable the app in Nextcloud

```bash
occ app:enable files_watermark
```

Or via the web UI: **Admin → Apps → search "files_watermark" → Enable**.

> The Docker development stacks (`docker-compose.yml` and `docker-compose.s3.yml`) run this
> step for you on every container start, via `tools/enable-app.sh` mounted into the image's
> `before-starting` hook. It is a convenience for local work only - see
> [the development guide](doc/development.md#docker-dev-instance).

## Watermarking shared files

Two switches under **Settings → Administration → Watermark → Shared files**:

| Switch | What it watermarks | Whose name is on it |
| --- | --- | --- |
| *Always watermark files opened through an internal share* | Every fetch of a file mounted from somebody else's storage - a user, group or team share | The recipient reading it |
| *Always watermark files opened through a public link* | Every fetch through `public.php`, and every fetch with no signed-in user at all | The file's owner, who has no other name available |

They are **not a third trigger.** A trigger decides which files carry a mark, and a mark is
durable: placed once, it follows the file until somebody removes it, and it watermarks the
file for *everyone*, its owner included. These two decide something narrower and entirely
reversible - that a copy *leaving through a share* carries a watermark - and they are
evaluated per fetch, against no stored state. Ticking one starts watermarking shared files
the moment you save; unticking it stops, with nothing left behind to undo. The owner's own
downloads are unaffected either way.

Both are off by default, including on upgrade, and they can be combined with either trigger:
`on_demand` marking for the documents somebody deliberately protects, plus a blanket
watermark on everything that goes out through a link.

**A shared file that cannot be watermarked is refused, not handed over clean.** That is the
same rule a marked file follows, and these switches bring files nobody marked under it: a
file past `apply_max_bytes`, or a PDF the renderer cannot parse, answers 403 rather than
serving the original to the one reader the policy exists to name. The same applies to a
shared folder downloaded as an archive, which is additionally bound by `archive_max_members`
and `archive_max_bytes`. Raise those limits (see [Server settings](#server-settings-occ)) if
your shares are larger than the defaults expect.

## The activity log

Every file **marked or unmarked** is recorded, always. There is one entry per policy
decision, so the volume is bounded by how often people change their minds.

The log sits at the bottom of the settings page, **collapsed** - the page is there for the
policy, and the log is history. *"Show activity log"* opens it, and nothing is fetched until
you do.

**Times are shown in the instance's timezone** - the same `default_timezone` the watermark
dates use - and the table says which zone under it, so an hour can be reconciled with a mail
timestamp or a server log. The stored column stays a fixed instant, so changing
`default_timezone` re-reads the whole history in the new zone rather than leaving a seam in
it, and retention arithmetic never depends on that setting.

**Downloads are recorded by default**, because *"who received a copy of this file"* is the
question a watermark exists to answer, and an install that records only the policy cannot
answer it. One entry per file per download, including every file inside a downloaded folder.

What that costs is worth knowing before you leave it on: a marked file is rendered on every
fetch, so a folder downloaded twice writes its rows twice, and **nothing expires on its
own** - [pruning](#pruning-old-entries) is what keeps it in hand. The bound on a single
request is `archive_max_members`. **Previews are not recorded**: they render per viewer, so
rows there would be per thumbnail per person.

The switch is **Settings → Administration → Watermark → When to apply**, *"Record every
download in the activity log"*.

> **Upgrading:** an instance that already has this setting keeps whatever is stored in it,
> off included. Only new installs get the new default - a default decides what happens when
> nobody has chosen, and rewriting the value would overwrite admins who turned it off on
> purpose. Tick the box if you want the new behaviour.

The log is history and nothing else. It used to double as the app's record of which files
were watermarked, which is why the pruning command below could not reach most of it; that
record lives in its own table now, so retention deletes exactly what it says.

### Pruning old entries

```bash
occ files_watermark:prune-log                  # older than 90 days
occ files_watermark:prune-log --days 30        # older than 30 days
occ files_watermark:prune-log --all            # every entry, any age
occ files_watermark:prune-log --all --dry-run  # what would go, and nothing else
```

It reaches **every entry**, which it deliberately did not before: the mark/unmark rows used
to be the app's record of which files were watermarked, so deleting one un-badged its file.
That record has its own table now, and nothing here is load-bearing - retention shortens the
history and changes no file's status.

## Server settings (`occ`)

The watermark itself is configured in the admin UI. These are host tuning rather than
policy - they bound what one request may cost, and they change nothing about the watermark
that comes out, so they live in the app config instead of on the form:

| Key | Default | What it bounds |
| --- | --- | --- |
| `archive_max_members` | `200` | Files rendered for a single folder / multi-select download |
| `archive_max_bytes` | `268435456` (256 MiB) | Source bytes rendered for one such download |
| `apply_max_bytes` | `67108864` (64 MiB) | Size of a single file accepted for an on-demand apply |
| `image_max_pixels` | `40000000` (40 MP) | Decoded size of an image, on every trigger |

```bash
occ config:app:set files_watermark archive_max_members --value 500
occ config:app:set files_watermark archive_max_bytes   --value 1073741824
occ config:app:set files_watermark apply_max_bytes     --value 134217728
occ config:app:set files_watermark image_max_pixels    --value 80000000
occ config:app:delete files_watermark archive_max_members   # back to the default
```

An archive is rendered member by member **before** any bytes are sent - that is what lets a
share that must be watermarked fail with a clean 403 instead of a truncated download - so
these bound the temp disk and CPU one request can use. **Past the cap the archive is
denied**, for every reader. Falling back to a plain unwatermarked archive was the old
behaviour for one of the four triggers, and for a marked file it is a bulk leak - it ships
precisely the clean originals the marks exist to prevent, at the moment the download is big
enough that nobody checks. An archive with no marked member is unaffected whatever its size:
there is nothing to render, so there is nothing to bound.

Raise them if large folder downloads are being denied and the server has the temp space;
lower them on a small host. There is no unlimited setting: a value below
`1` is refused and the default used, with a warning in the log.

`apply_max_bytes` bounds something different, and the difference is why its default is so
much smaller. It is checked when a file is **marked**, under both triggers - a file this app
will not render is a file it must not promise a watermark for, and a ceiling discovered at
download time would deny a file nobody was ever warned about. What a render spends is a PHP
worker's memory rather than temp disk, and it holds several times the file's own size at
peak, against a `memory_limit` that is 512M on a stock Nextcloud. A file over the cap is
refused a mark with a 413 naming both its size and the limit, read from the file cache
before any content is touched; on upload it is simply left unmarked, with a line in the log.
**Raise it and PHP's `memory_limit` together, or not at all**: on its own it only moves the
failure from a clean refusal that costs nothing to a fatal error part-way through a
download.

`image_max_pixels` bounds something `apply_max_bytes` cannot see. Both GD and Imagick work
on an uncompressed bitmap at roughly **4 bytes per pixel**, and the ratio between a file's
size on disk and its decoded size is unbounded - a PNG of one flat colour is kilobytes on
disk and gigabytes in memory. The default of 40 MP is about 160 MB decoded, and is set
above ordinary photography rather than below it: a 24 MP camera frame and a full 8K image
both pass, while the 50 MP-and-up end does not. Dimensions are read from the file header,
so an image over the limit is refused without ever being decoded.

It is checked twice, and neither check is redundant. At mark time it reads the image's
header - the first few kilobytes, never the whole file - so a bomb is refused a mark rather
than refused a download. At render time it is checked again against the bytes that actually
arrived, because a marked file can be overwritten and the mark stands.

### Arabic that arrives already shaped

| Key | Default | What it does |
| --- | --- | --- |
| `arabic_shaping` | `auto` | Whether the image renderers shape Arabic text before drawing it |

```bash
occ config:app:set files_watermark arabic_shaping --value always
occ config:app:delete files_watermark arabic_shaping   # back to auto
```

This is the one `occ` setting that **does** change what the watermark looks like, which is
why it is described here rather than added to the table above. It exists because Arabic can
reach the server in two different shapes and only one of them needs work.

A modern keyboard produces Arabic letters in logical order, which the renderer has to join
into their contextual forms and reverse into visual order before drawing - neither GD nor a
stock ImageMagick will do it. But a display name typed on Windows, pasted out of a PDF, or
read from a directory populated from either can already hold **Arabic presentation forms**,
already in visual order. Running the shaper over those reverses them, and the watermark
draws the name backwards while every other view of it in Nextcloud reads correctly.

`auto` looks at the text and shapes it only when it has not been shaped already. That is
right for both shapes of input and needs no configuration. The other two values are for the
case the bytes cannot settle - text holding presentation forms in *logical* order, which
neither reading fixes automatically:

- `always` shapes unconditionally. This is what the renderer did before `auto` existed.
- `never` draws the text exactly as configured, for a directory that is pre-shaped
  throughout.

It applies to **image watermarks only**. PDF watermarks go through a different text engine
that has always handled this correctly, and this setting does not reach it.

Marking and unmarking are also rate limited to **120 per user per minute** by Nextcloud's
own middleware, which answers `429`. Each one is a database write rather than a render, so
the limit is well above anything the file action can produce by hand - but it is the only
bound on marking from the UI, which is why it is still there. It is not configurable.

## API Endpoints

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/apps/files_watermark/api/v1/config` | Get watermark config(s) |
| `POST` | `/apps/files_watermark/api/v1/config` | Create or update a config |
| `DELETE` | `/apps/files_watermark/api/v1/config/{id}` | Delete a config |
| `POST` | `/apps/files_watermark/api/v1/apply` | Mark a file, so every fetch of it is watermarked |
| `POST` | `/apps/files_watermark/api/v1/remove` | Unmark a file |
| `GET` | `/apps/files_watermark/api/v1/log` | Retrieve audit log (admin only) |
| `GET` | `/apps/files_watermark/api/v1/download` | Download a file, watermarked if it is marked |

## Usage

- **On demand:** right-click any supported file in the Files app → **Apply Watermark**. The
  file is not modified; from then on every download and preview of it carries a watermark
- **On upload:** set the global trigger to *On upload*, and every supported file is marked
  as it arrives. The manual actions are hidden in this mode, since the policy already covers
  everything
- **Removing a watermark:** right-click → **Remove watermark**. Instant, and complete -
  there is nothing to restore, because nothing was overwritten. **Only the file's owner can
  do this**, even on a share with edit permission - see below
- **Admin settings:** configure the global policy under **Settings → Additional → Watermark Settings**

## Previews

**A marked file's preview carries a watermark too**, naming whoever is looking at it. That
matters because a thumbnail of a document's first page is a readable copy of it - blocking
downloads while serving previews protects nothing.

Getting there needs one thing worth knowing about, because it constrains the design.
Nextcloud caches previews by file id and dimensions and **never by viewer**, so a
watermarked thumbnail written into that cache would be handed to the next person to open
the folder with the first person's name on it. So the cache keeps doing what it is good at

- holding the *clean* preview, which no client can reach directly - and the watermark is
applied to the response, per request, after it. The response is marked `no-store`, so
nothing downstream can hold on to an image that names a person.

Two consequences:

- **the watermark on a small thumbnail is not legible**, and is not meant to be. It is
  scaled to the image it is drawn on, so at 64px it is a smear rather than a name. A
  readable watermark on a 64px tile would have to cover it entirely
- **a preview that cannot be watermarked is not served**, exactly like a download that
  cannot be. The file shows its generic type icon

Previews of unmarked files are untouched and uncached-by-us; nothing changes for them.

## Removing a watermark

There is nothing to restore, because nothing was ever overwritten. **Remove watermark**
deletes the mark and the next download is the file as it was uploaded, byte for byte.

**Only the owner may remove a watermark**, and that is not the same rule as applying one.
Applying asks for write permission, because it is a change to the file's policy and the
people who can change the file are the people who can change that. Applying the same rule
to removal would hand the off switch to a share recipient with edit permission - and
whoever the shared copy would have named is exactly whoever has an interest in it naming
nobody. So removal asks who *owns* the file rather than who may write it. For a file that
is not shared the two are the same person and nothing changes; the rule only ever bites on
a share.

The reverse is deliberately not restricted: a recipient may still *apply* a watermark to a
file they can write. That direction only ever adds protection, and it cannot lock the owner
out, since the owner can remove a watermark from anything they own.

In the Files app the action is simply not offered on a file somebody else owns. The server
refuses it either way - the hidden button is so the refusal is not something a user has to
discover by clicking.

Earlier versions burned the watermark into the stored file and kept a copy of the original
in the owner's storage to undo it with. That whole apparatus is gone - no copies against
the owner's quota, no folder to hide from clients, no restore that can fail. If you are
upgrading from such a version, see the note below.

## Upgrading from a version that modified files

**This release does not migrate anything, and that is deliberate.** Files that a previous
version watermarked in place still carry that watermark in their bytes; they are not marked
and they are not restored. Marking them would draw a second watermark over the first on
every fetch, and this app does not rewrite user content to tidy up after itself.

Three things to know:

- files already watermarked in place stay as they are. To put one on the new scheme,
  restore it by hand (the app's own copies are under `.files_watermark/originals/` in the
  owner's storage) and apply the watermark again
- those preserved-original folders are no longer read or written by anything. They are left
  on disk rather than deleted during an upgrade; remove them when you are satisfied nothing
  needs them
- **a policy set to the old `on_download` or `on_share` stops working.** Those triggers no
  longer exist, and an unrecognised one marks nothing at all rather than being mapped onto
  something approximate - the two candidates differ in whether every upload on the instance
  gets marked, and guessing silently is worse than stopping. Pick one of the two remaining
  triggers in the admin settings. Note that existing files are **not** retroactively
  marked: `on_upload` covers files written from then on

## Storage backends

Nothing here is storage-specific. The app reads and writes content through the Nextcloud
Files API and touches the local filesystem only for short-lived temp copies, so watermarking
works unchanged on local disk, on S3 as primary object storage, and on an S3 external mount.
All three are verified rather than assumed - the stacks that do it are in
[doc/development.md](doc/development.md#docker-s3-instance).

## License

AGPL-3.0-or-later
