const zlib = require('zlib')

/**
 * The source files the suite uploads.
 *
 * Generated rather than committed, for one reason that matters: an assertion like
 * "the delivered copy has ink on it" is only meaningful against a source that has
 * none, and a fixture built here is *known* to be a flat field and to carry no
 * embedded font. A binary checked into the tree would have to be trusted to still
 * be that.
 *
 * They are deliberately small. Delivery-time triggers render per fetch, so a large
 * fixture buys nothing but a slow suite — the skeleton PDFs are used where a real
 * document is the point (see `cy.wmSkeleton`).
 */

const A4 = [595.28, 841.89]

/**
 * A minimal, uncompressed, classic-xref PDF with one text line per page.
 *
 * @param {{pages?: number, text?: string}} args how many pages, and what to draw
 * @return {string} the file, base64
 */
function makePdf({ pages = 1, text = 'files_watermark e2e fixture' } = {}) {
	const objects = []
	const pageCount = Math.max(1, pages)
	const fontId = 3 + pageCount * 2
	const pageIds = []

	for (let index = 0; index < pageCount; index++) {
		pageIds.push(3 + index * 2)
	}

	objects[1] = '<< /Type /Catalog /Pages 2 0 R >>'
	objects[2] = `<< /Type /Pages /Kids [${pageIds.map((id) => `${id} 0 R`).join(' ')}] /Count ${pageCount} >>`

	pageIds.forEach((id, index) => {
		const body = `BT /F1 18 Tf 72 720 Td (${text} - page ${index + 1}) Tj ET`
		objects[id] = `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${A4[0]} ${A4[1]}] `
			+ `/Resources << /Font << /F1 ${fontId} 0 R >> >> /Contents ${id + 1} 0 R >>`
		objects[id + 1] = `<< /Length ${body.length} >>\nstream\n${body}\nendstream`
	})

	objects[fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'

	let out = '%PDF-1.4\n'
	const offsets = []

	for (let id = 1; id < objects.length; id++) {
		offsets[id] = out.length
		out += `${id} 0 obj\n${objects[id]}\nendobj\n`
	}

	const xref = out.length
	out += `xref\n0 ${objects.length}\n0000000000 65535 f \n`
	for (let id = 1; id < objects.length; id++) {
		out += `${String(offsets[id]).padStart(10, '0')} 00000 n \n`
	}
	out += `trailer\n<< /Size ${objects.length} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`

	return Buffer.from(out, 'latin1').toString('base64')
}

const CRC_TABLE = (() => {
	const table = new Int32Array(256)
	for (let n = 0; n < 256; n++) {
		let c = n
		for (let k = 0; k < 8; k++) {
			c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
		}
		table[n] = c
	}
	return table
})()

/** PNG's chunk checksum (the standard CRC-32, polynomial 0xEDB88320). */
function crc32(buffer) {
	let crc = -1
	for (const byte of buffer) {
		crc = CRC_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8)
	}
	return (crc ^ -1) >>> 0
}

/** One length-type-payload-CRC PNG chunk. */
function chunk(type, data) {
	const head = Buffer.alloc(8)
	head.writeUInt32BE(data.length, 0)
	head.write(type, 4, 'latin1')
	const tail = Buffer.alloc(4)
	tail.writeUInt32BE(crc32(Buffer.concat([head.subarray(4), data])), 0)
	return Buffer.concat([head, data, tail])
}

/**
 * A flat single-colour 8-bit RGB PNG — a field with no ink on it.
 *
 * @param {{width?: number, height?: number, color?: number[]}} args canvas size and fill
 * @return {string} the file, base64
 */
function makePng({ width = 900, height = 600, color = [255, 255, 255] } = {}) {
	const stride = width * 3
	const raw = Buffer.alloc(height * (stride + 1))

	for (let y = 0; y < height; y++) {
		raw[y * (stride + 1)] = 0 // filter: none
		for (let x = 0; x < width; x++) {
			raw.set(color, y * (stride + 1) + 1 + x * 3)
		}
	}

	const header = Buffer.alloc(13)
	header.writeUInt32BE(width, 0)
	header.writeUInt32BE(height, 4)
	header[8] = 8 // bit depth
	header[9] = 2 // colour type: truecolour

	return Buffer.concat([
		Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
		chunk('IHDR', header),
		chunk('IDAT', zlib.deflateSync(raw)),
		chunk('IEND', Buffer.alloc(0)),
	]).toString('base64')
}

module.exports = { makePdf, makePng }
