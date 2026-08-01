const zlib = require('zlib')

/**
 * Reads a delivered PDF the way `PdfWatermarkerTest` reads one, because the same two
 * facts decide the question end to end:
 *
 *  - the app draws **every** watermark with a subsetted IBM Plex Sans Arabic, so
 *    `/BaseFont /XXXXXX+IBMPlexSansArabic` in the bytes means a watermark was drawn.
 *    Nothing else in a source document puts it there;
 *  - the embedded face is written with two-byte code units, so the operand of a `Tj`
 *    is the shaped text verbatim. That is what makes "was the Arabic shaped and
 *    reordered"
 *    assertable from the delivered file rather than from a screenshot.
 *
 * "Bigger than the original" is deliberately not the test. A watermarked file differs
 * from its source in a hundred ways that have nothing to do with a watermark being
 * legible, and every one of them would pass.
 */

const SUBSET_FONT = /\/BaseFont\s*\/[A-Z]{6}\+IBMPlexSansArabic/

/**
 * Every stream in the file, inflated where it is deflated.
 */
function streams(buffer) {
	const latin = buffer.toString('latin1')
	const found = []
	const pattern = /stream\r?\n([\s\S]*?)endstream/g
	let match

	while ((match = pattern.exec(latin)) !== null) {
		const bytes = Buffer.from(match[1], 'latin1')
		let inflated = null
		for (const decode of [zlib.inflateSync, zlib.inflateRawSync]) {
			try {
				inflated = decode(bytes)
				break
			} catch {
				// Not this encoding; fall through to the raw bytes.
			}
		}
		found.push(inflated || bytes)
	}

	return found
}

/**
 * The code points of every text-showing run, in the order they are drawn.
 */
function textRuns(buffer) {
	const runs = []

	for (const stream of streams(buffer)) {
		const latin = stream.toString('latin1')
		const pattern = /\((.+?)\)\s*Tj/gs
		let match

		while ((match = pattern.exec(latin)) !== null) {
			const bytes = Buffer.from(match[1], 'latin1')
			if (bytes.length < 2 || bytes.length % 2 !== 0) {
				continue
			}
			const codepoints = []
			for (let index = 0; index < bytes.length; index += 2) {
				codepoints.push((bytes[index] << 8) | bytes[index + 1])
			}
			runs.push(codepoints)
		}
	}

	return runs
}

const ARABIC_PRESENTATION = (code) => code >= 0xfe70 && code <= 0xfeff
const ARABIC_SOURCE = (code) => code >= 0x0600 && code <= 0x06ff

/**
 * @param {{base64: string}} args
 * @return {{
 *   isPdf: boolean, bytes: number, version: string,
 *   watermarked: boolean, hasEmbeddedFontFile: boolean, hasToUnicode: boolean,
 *   pages: number, textRuns: number[][],
 *   shapedArabicGlyphs: number, unshapedArabicCodepoints: number,
 * }}
 */
function probe({ base64 }) {
	const buffer = Buffer.from(base64, 'base64')
	const latin = buffer.toString('latin1')
	const runs = textRuns(buffer)
	const all = runs.flat()

	return {
		isPdf: latin.startsWith('%PDF-'),
		bytes: buffer.length,
		version: latin.slice(5, 8),
		watermarked: SUBSET_FONT.test(latin),
		hasEmbeddedFontFile: latin.includes('/FontFile2'),
		hasToUnicode: latin.includes('/ToUnicode'),
		// Counts page objects; enough to assert a delivered copy kept its page count.
		pages: (latin.match(/\/Type\s*\/Page[^s]/g) || []).length,
		textRuns: runs,
		shapedArabicGlyphs: all.filter(ARABIC_PRESENTATION).length,
		unshapedArabicCodepoints: all.filter(ARABIC_SOURCE).length,
	}
}

module.exports = { probe }
