const zlib = require('zlib')

/**
 * Image probes for the delivered-file assertions.
 *
 * A watermarked image has no marker to look for the way a PDF has its embedded
 * subset font, so the evidence has to come out of the pixels: the fixture is a flat
 * field, and a watermark is ink on it. `inkRatio` is the fraction of pixels that
 * differ from the image's own corner pixel, which is what "something was drawn"
 * means for a tiled watermark and what "nothing was drawn" means for a clean
 * original — the failure that "the file changed" would happily pass.
 *
 * Dimensions come back too: the renderer must not resize what it stamps.
 */

/**
 * Decode a non-interlaced 8-bit PNG to `{width, height, pixels}` (RGBA bytes).
 *
 * @param {Buffer} buffer the PNG file
 */
function decodePng(buffer) {
	if (buffer.readUInt32BE(0) !== 0x89504e47) {
		throw new Error('not a PNG')
	}

	let offset = 8
	let header = null
	let palette = null
	const idat = []

	while (offset < buffer.length) {
		const length = buffer.readUInt32BE(offset)
		const type = buffer.toString('latin1', offset + 4, offset + 8)
		const data = buffer.subarray(offset + 8, offset + 8 + length)

		if (type === 'IHDR') {
			header = {
				width: data.readUInt32BE(0),
				height: data.readUInt32BE(4),
				bitDepth: data[8],
				colorType: data[9],
				interlace: data[12],
			}
		} else if (type === 'PLTE') {
			palette = data
		} else if (type === 'IDAT') {
			idat.push(data)
		} else if (type === 'IEND') {
			break
		}

		offset += length + 12
	}

	if (header === null) {
		throw new Error('PNG has no IHDR')
	}
	if (header.bitDepth !== 8 || header.interlace !== 0) {
		throw new Error(`unsupported PNG: depth ${header.bitDepth}, interlace ${header.interlace}`)
	}

	const channels = { 0: 1, 2: 3, 3: 1, 4: 2, 6: 4 }[header.colorType]
	if (channels === undefined) {
		throw new Error(`unsupported PNG colour type ${header.colorType}`)
	}

	const raw = zlib.inflateSync(Buffer.concat(idat))
	const stride = header.width * channels
	const lines = Buffer.alloc(header.height * stride)

	// Undo the per-scanline filters (PNG spec §9.2). Each line's filter byte is
	// stripped here, so `lines` is contiguous raw samples afterwards.
	for (let y = 0; y < header.height; y++) {
		const filter = raw[y * (stride + 1)]
		const source = raw.subarray(y * (stride + 1) + 1, y * (stride + 1) + 1 + stride)
		const target = lines.subarray(y * stride, (y + 1) * stride)
		const previous = y === 0 ? null : lines.subarray((y - 1) * stride, y * stride)

		for (let x = 0; x < stride; x++) {
			const a = x >= channels ? target[x - channels] : 0
			const b = previous === null ? 0 : previous[x]
			const c = previous === null || x < channels ? 0 : previous[x - channels]
			let value = source[x]

			switch (filter) {
			case 0: break
			case 1: value += a; break
			case 2: value += b; break
			case 3: value += (a + b) >> 1; break
			case 4: {
				const p = a + b - c
				const pa = Math.abs(p - a)
				const pb = Math.abs(p - b)
				const pc = Math.abs(p - c)
				value += (pa <= pb && pa <= pc) ? a : (pb <= pc ? b : c)
				break
			}
			default: throw new Error(`unknown PNG filter ${filter}`)
			}

			target[x] = value & 0xff
		}
	}

	const pixels = Buffer.alloc(header.width * header.height * 4)
	for (let index = 0; index < header.width * header.height; index++) {
		const sample = index * channels
		let r = 0
		let g = 0
		let b = 0
		let a = 255

		switch (header.colorType) {
		case 0: r = g = b = lines[sample]; break
		case 4: r = g = b = lines[sample]; a = lines[sample + 1]; break
		case 2: [r, g, b] = [lines[sample], lines[sample + 1], lines[sample + 2]]; break
		case 6: [r, g, b, a] = [lines[sample], lines[sample + 1], lines[sample + 2], lines[sample + 3]]; break
		case 3: {
			const entry = lines[sample] * 3
			;[r, g, b] = [palette[entry], palette[entry + 1], palette[entry + 2]]
			break
		}
		}

		pixels.set([r, g, b, a], index * 4)
	}

	return { width: header.width, height: header.height, colorType: header.colorType, pixels }
}

/**
 * Width and height off a JPEG's frame header.
 *
 * @param {Buffer} buffer the JPEG file
 */
function jpegSize(buffer) {
	let offset = 2
	while (offset < buffer.length - 1) {
		if (buffer[offset] !== 0xff) {
			offset++
			continue
		}
		const marker = buffer[offset + 1]
		// SOF0..SOF3 and SOF5..SOF15 carry the frame dimensions; the rest are skipped
		// by their declared length. D8/D9/01 and the restart markers have no payload.
		if (marker >= 0xc0 && marker <= 0xcf && ![0xc4, 0xc8, 0xcc].includes(marker)) {
			return {
				height: buffer.readUInt16BE(offset + 5),
				width: buffer.readUInt16BE(offset + 7),
			}
		}
		if (marker === 0xd8 || marker === 0x01 || (marker >= 0xd0 && marker <= 0xd7)) {
			offset += 2
			continue
		}
		offset += 2 + buffer.readUInt16BE(offset + 2)
	}
	throw new Error('no JPEG frame header')
}

/**
 * @param {{base64: string, tolerance?: number}} args the image, and how far a pixel
 *   may drift from the background before it counts as ink
 * @return {{format: string, width: number, height: number, bytes: number, inkRatio: number|null}}
 */
function probe({ base64, tolerance = 8 }) {
	const buffer = Buffer.from(base64, 'base64')

	if (buffer.length > 3 && buffer[0] === 0xff && buffer[1] === 0xd8) {
		const { width, height } = jpegSize(buffer)
		// JPEG is not decoded here: a lossy round-trip changes every pixel a little,
		// so an ink ratio off it would measure the codec, not the watermark. PNG
		// fixtures carry the pixel assertions.
		return { format: 'jpeg', width, height, bytes: buffer.length, inkRatio: null }
	}

	const { width, height, colorType, pixels } = decodePng(buffer)
	const background = [pixels[0], pixels[1], pixels[2]]
	let ink = 0

	for (let index = 0; index < width * height; index++) {
		const at = index * 4
		if (
			Math.abs(pixels[at] - background[0]) > tolerance
			|| Math.abs(pixels[at + 1] - background[1]) > tolerance
			|| Math.abs(pixels[at + 2] - background[2]) > tolerance
		) {
			ink++
		}
	}

	return {
		format: 'png',
		width,
		height,
		colorType,
		bytes: buffer.length,
		inkRatio: ink / (width * height),
	}
}

module.exports = { probe, decodePng }
