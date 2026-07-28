# Font metrics

Font *metrics* — widths, bounding box, ascent/descent. No glyph outlines, and nothing
here is embedded in the PDFs the app produces: Helvetica is one of the PDF standard 14
fonts, which every conforming reader supplies itself.

They are committed rather than installed because **neither renderer ships them**:

- `*.json` — tc-lib-pdf format, used by `PdfWatermarker`. The Composer package
  `tecnickcom/tc-lib-pdf-font` deliberately ships no font data; its `make fonts` target
  downloads a 117 MB mirror and converts. These two files were generated once from the
  canonical Adobe Core-14 AFMs (`Helvetica.afm`, `Helvetica-Bold.afm`, via
  `tecnickcom/tc-font-mirror` 2.2.0) using the library's own `Com\Tecnick\Pdf\Font\Import`.
  Without them *any* text call throws `unable to read file: helveticab.json`.
- `*.php` — TCPDF format, copied from `vendor/tecnickcom/tcpdf/fonts/`. Needed only
  because pointing `K_PATH_FONTS` here stops TCPDF finding its own; see below. **Delete
  these when TCPDF leaves the tree** (step 7 of the migration in `doc/tasks.md`).

## Why the directory holds two formats

`K_PATH_FONTS` is the only reliable way to tell tc-lib-pdf where fonts live. The
alternative — a `fonts` directory discovered by walking up from the package — requires
that directory to be **writable**, which a hardened Nextcloud install will not be.

That constant is also read by TCPDF, which looks for `helvetica.php` where tc-lib-pdf
looks for `helvetica.json`. While both stacks coexist the two sets therefore share one
directory. TCPDF additionally requires the path to end in a separator.

## Provenance and licence

The `.json` files derive from Adobe's Core-14 AFM metrics, redistributed by
`tecnickcom/tc-font-mirror`, whose `core/LICENSE` file is **empty** — upstream states no
terms. The same metrics ship in TCPDF (LGPL-3.0, already vendored here) and in most PDF
libraries, so redistributing them is well-trodden, but the provenance is recorded here
rather than assumed. Worth a look if this app is ever formally licence-audited.

To regenerate:

```php
new Com\Tecnick\Pdf\Font\Import('/path/to/Helvetica-Bold.afm', '/output/dir', 'Core');
```
