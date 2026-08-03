# Fonts

**One face draws every watermark**, Latin and Arabic alike.

| File | What it is | Used by |
| --- | --- | --- |
| `ibmplexsansarabicb.json`, `.z`, `.ctg.z` | IBM Plex Sans Arabic Bold - metrics **and** the font program | Every watermark, both renderers |
| `IBMPlexSansArabic-OFL.txt` | Its licence (SIL OFL 1.1) | - |
| `helvetica.json`, `helveticab.json` | Helvetica *metrics only* - no glyphs | **Test fixtures only.** `PdfFixtures` draws the source PDFs that tests then watermark |

The renderers no longer use Helvetica. It is kept because the test fixtures build their
source documents with it, and removing it would break them for no gain.

## Why one face

The previous arrangement kept Latin on standard-14 Helvetica - embedding no font at all,
so Latin files stayed tiny - and embedded an Arabic face only when the text needed it. It
worked, but it meant **a watermark changed typeface depending on whether someone's display
name happened to be Arabic**, which is not a property anyone asked for. One face renders
the same configuration the same way, always.

The cost is real and bounded: a Latin watermarked PDF goes from ~9 KB to ~31 KB. Subsetting
is what keeps it to that - see below.

## Why IBM Plex Sans Arabic

Chosen by measurement across ten OFL candidates, not by looks. **The binding constraint is
Arabic Presentation Forms-B** (U+FE70–FEFF): the shaper substitutes into that block, so a
font that omits it draws nothing for shaped Arabic however complete its `U+0600` coverage
looks.

Most modern faces omit it, because they shape through OpenType `GSUB` instead. Measured
against a set of ordinary Arabic words and names:

| Font | Arabic forms | Latin | Font program |
| --- | --- | --- | --- |
| **IBM Plex Sans Arabic Bold** | **complete** | **complete** | **103 KB** |
| Noto Kufi Arabic | complete | complete | 210 KB |
| Noto Sans Arabic | complete | complete | 445 KB |
| Amiri Bold | complete | complete | 199 KB |
| Almarai Bold | 13 missing | complete | 66 KB |
| Tajawal Bold | 14 missing | complete | 28 KB |
| Markazi Text | 15 missing | complete | 110 KB |
| Scheherazade New / Lateef / Harmattan | 64 missing each | complete | 123–228 KB |
| Readex Pro | 65 missing | complete | 149 KB |
| **Cairo** | **14 missing** | complete | - |
| Noto Naskh Arabic | complete | **15 ASCII, no Latin letters, no hyphen** | 108 KB |

Cairo was ruled out on evidence: it lacks U+FEAD (final reh), U+FE8D (isolated alef),
U+FEED (isolated waw) and eleven more forms that everyday Arabic produces. Noto Naskh
Arabic has the Arabic but would drop the `-` from `{displayname} - {date}` along with any
Latin name.

Of the three that cover both scripts completely, IBM Plex Sans Arabic has the smallest font
program and is a sans, which is the register a watermark wants.

## Subsetting

`PdfWatermarker` constructs the document with `subsetfont: true`, so only the glyphs
actually drawn are embedded. A watermark repeats one short string, so the subset is tiny:

| | Latin | Arabic |
| --- | --- | --- |
| whole face embedded | 125 KB | 125 KB |
| **subsetted** | **31 KB** | **33 KB** |

It is also *faster* - 0.025s against 0.071s on the same fixture - because there is far less
to write, despite the library's docblock warning that the option is "computational and
memory intensive". The documented trade-off (a subsetted font cannot be used to re-edit the
text without the same font installed) does not apply: nobody edits a watermark.

A subset font name carries a six-letter tag, `AAAAAB+IBMPlexSansArabic-Bold`. That tag is
what the tests assert on, being definitive where a file-size bound is only suggestive.

`/ToUnicode` is still written, so watermark text stays searchable, selectable and reachable
by a screen reader - the app's "the text layer survives" promise depends on it.

## The image renderers use the same bytes

`ibmplexsansarabicb.z` is the font program the PDF embeds, and it is the original TTF under
zlib - verified byte for byte against upstream. `ShapedText::bundledFontPath()` inflates it
to a file cached in the system temp directory for GD and ImageMagick, which draw through
FreeType and need bytes on disk.

The font is therefore **not committed twice**. That is not only about size: it means the
glyphs drawn into a JPEG are provably the ones stamped into a PDF, which two copies of a
file could not guarantee for long. The cache is keyed by a hash of the archive, so changing
the font invalidates it.

This also removed the old system-font list - DejaVu, Liberation, macOS Arial - which chose
a font by *name*, and a name cannot express "has the glyphs this string needs". Two of
those three carry no Arabic at all, so image output used to depend on what the host had
installed. GD's bitmap-font fallback went with it: a missing bundled font is a broken
install and is refused, because mojibake in a valid image file is worse than a failure.

## Shaping

Arabic is written right to left, its letters change shape according to their neighbours,
and some pairs fuse into a single glyph. `tc-lib-pdf` does all of that itself inside
`getTextCell()` - verified by reading the bytes it emits - so **the PDF path must not
pre-shape**. GD does none of it, and ImageMagick only does it when built against
Raqm/HarfBuzz, so the **image path shapes explicitly** via `ShapedText::shape()`.

Both end up drawing the same glyphs. The probe used throughout the tests is `الاختبار`:
8 code points in, 7 glyphs out, every one in Arabic Presentation Forms-B, including one
lam-alef ligature - which is where the eighth went.

## Provenance and licence

- **IBM Plex Sans Arabic** - SIL Open Font License 1.1, `IBMPlexSansArabic-OFL.txt`,
  © 2017 IBM Corp. A clean, stated licence.
- **Helvetica metrics** - derived from Adobe's Core-14 AFM metrics, redistributed by
  `tecnickcom/tc-font-mirror`, whose `core/LICENSE` is a **0-byte file**: upstream states
  no terms. Metrics only - no Helvetica glyphs are redistributed or embedded, and nothing
  the app ships to users is drawn with it any more. Recorded rather than assumed away;
  worth a look if this app is ever formally licence-audited.

## How the renderer finds them

Through the global constant `K_PATH_FONTS`, claimed by `PdfFontPath` during app bootstrap.
That is the only mechanism which survives a real deployment: the library's other lookup
walks up from its own package directory looking for a `fonts` directory and requires that
directory to be **writable**, which a hardened Nextcloud install will not be.

`PdfWatermarker` also names this directory in its `allowedPaths`, since tc-lib-pdf refuses
local reads outside an allowlist and supplying one *replaces* the defaults instead of
extending them.

## To regenerate

```php
// Writes <name>.json, <name>.z and <name>.ctg.z into the output directory.
// The trailing separator is required - the library concatenates rather than joins.
new Com\Tecnick\Pdf\Font\Import('/path/to/IBMPlexSansArabic-Bold.ttf', '/path/to/resources/fonts/', 'TrueTypeUnicode');
```

`php-cs-fixer` is configured to leave this directory alone, so nothing here is reformatted.
