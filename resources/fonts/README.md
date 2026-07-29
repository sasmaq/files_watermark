# Font metrics

Font *metrics* — widths, bounding box, ascent/descent. No glyph outlines, and nothing
here is embedded in the PDFs the app produces: Helvetica is one of the PDF standard 14
fonts, which every conforming reader supplies itself.

They are committed rather than installed because **the renderer does not ship them**.
The Composer package `tecnickcom/tc-lib-pdf-font` deliberately contains no font data;
its `make fonts` target downloads a 117 MB mirror and converts. These two files were
generated once from the canonical Adobe Core-14 AFMs (`Helvetica.afm`,
`Helvetica-Bold.afm`, via `tecnickcom/tc-font-mirror` 2.2.0) using the library's own
`Com\Tecnick\Pdf\Font\Import`.

Without them *any* text call throws `unable to read file: helveticab.json`, which reads
like a packaging bug and is not one.

## How the renderer finds them

Through the global constant `K_PATH_FONTS`, claimed by `PdfFontPath` during app
bootstrap. That is the only mechanism which survives a real deployment: the library's
other lookup walks up from its own package directory looking for a `fonts` directory and
requires it to be **writable**, which a hardened Nextcloud install will not be.

`PdfWatermarker` and `PdfFlattener` also name this directory in their `allowedPaths`,
since tc-lib-pdf refuses local reads outside an allowlist and supplying one *replaces*
the defaults instead of extending them.

Until the migration off FPDI + TCPDF completed, this directory also held `helvetica*.php`
— the same metrics in TCPDF's format — because TCPDF read the same constant and would
otherwise have stopped finding its own. Those are gone, along with TCPDF.

## Provenance and licence

The files derive from Adobe's Core-14 AFM metrics, redistributed by
`tecnickcom/tc-font-mirror`, whose `core/LICENSE` is a **0-byte file** — upstream states
no terms. The same metrics ship in TCPDF (LGPL-3.0) and in most PDF libraries, so
redistributing them is well-trodden rather than novel, but the provenance is recorded
here rather than assumed. Worth a look if this app is ever formally licence-audited.

To regenerate:

```php
new Com\Tecnick\Pdf\Font\Import('/path/to/Helvetica-Bold.afm', '/output/dir', 'Core');
```
