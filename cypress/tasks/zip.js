const zlib = require('zlib')

/**
 * A minimal ZIP reader, so the archive assertions can look at the *members* rather
 * than at the archive's size.
 *
 * `ZipInterceptorPlugin` rebuilds an archive member by member, and the bug that
 * shipped once — the gate keyed off the container rather than the member — produced
 * a perfectly valid archive of perfectly clean originals. Nothing short of unpacking
 * it and probing each member can see that.
 *
 * Members are read through the central directory rather than by walking local
 * headers: `\OC\Streamer` writes streamed entries whose local header carries zeroes
 * and defers the real sizes to a data descriptor, so the central directory is the
 * only place the sizes are reliable.
 *
 */

/**
 * The 64-bit values carried by a central-directory entry's ZIP64 extra field
 * (header id 0x0001), in the order the spec writes them: uncompressed size,
 * compressed size, local-header offset, disk number.
 */
function zip64Extra(buffer, start, length) {
	let at = start
	const end = start + length

	while (at + 4 <= end) {
		const id = buffer.readUInt16LE(at)
		const size = buffer.readUInt16LE(at + 2)

		if (id === 0x0001) {
			const values = []
			for (let field = 0; field + 8 <= size; field += 8) {
				values.push(Number(buffer.readBigUInt64LE(at + 4 + field)))
			}
			return values
		}

		at += 4 + size
	}

	return []
}

/**
 * @param {{base64: string}} args the archive bytes
 * @return {{name: string, size: number, base64: string}[]} one entry per file member
 */
function list({ base64 }) {
	const buffer = Buffer.from(base64, 'base64')

	// End of central directory: fixed 22-byte record, possibly followed by a comment,
	// so scan back for its signature.
	let end = buffer.length - 22
	while (end >= 0 && buffer.readUInt32LE(end) !== 0x06054b50) {
		end--
	}
	if (end < 0) {
		throw new Error('not a ZIP archive (no end-of-central-directory record)')
	}

	let count = buffer.readUInt16LE(end + 10)
	let offset = buffer.readUInt32LE(end + 16)

	// `ZipStreamer` writes ZIP64 whenever it cannot know the sizes up front, which
	// for a streamed archive is always — so the 32-bit fields above are the 0xFFFF…
	// sentinels and the real values live in the ZIP64 records. Reading the sentinel
	// as an offset is how this first showed up: a seek to 4294967295.
	if (count === 0xffff || offset === 0xffffffff) {
		let locator = end - 20
		while (locator >= 0 && buffer.readUInt32LE(locator) !== 0x07064b50) {
			locator--
		}
		if (locator < 0) {
			throw new Error('ZIP64 sentinel with no end-of-central-directory locator')
		}

		const zip64End = Number(buffer.readBigUInt64LE(locator + 8))
		count = Number(buffer.readBigUInt64LE(zip64End + 32))
		offset = Number(buffer.readBigUInt64LE(zip64End + 48))
	}

	const members = []

	for (let index = 0; index < count; index++) {
		if (buffer.readUInt32LE(offset) !== 0x02014b50) {
			throw new Error(`central directory entry ${index} has a bad signature`)
		}

		const method = buffer.readUInt16LE(offset + 10)
		const nameLength = buffer.readUInt16LE(offset + 28)
		const extraLength = buffer.readUInt16LE(offset + 30)
		const commentLength = buffer.readUInt16LE(offset + 32)
		const name = buffer.toString('utf8', offset + 46, offset + 46 + nameLength)

		// Each 32-bit field that reads as the sentinel is carried, in this order, by
		// the ZIP64 extended-information extra field.
		const zip64 = zip64Extra(buffer, offset + 46 + nameLength, extraLength)
		let next = 0
		const wide = (value) => (value === 0xffffffff ? zip64[next++] : value)

		const size = wide(buffer.readUInt32LE(offset + 24))
		const compressedSize = wide(buffer.readUInt32LE(offset + 20))
		const localOffset = wide(buffer.readUInt32LE(offset + 42))

		offset += 46 + nameLength + extraLength + commentLength

		if (name.endsWith('/')) {
			continue
		}

		// The local header's own name and extra lengths locate the data; they are not
		// required to match the central directory's.
		const localName = buffer.readUInt16LE(localOffset + 26)
		const localExtra = buffer.readUInt16LE(localOffset + 28)
		const start = localOffset + 30 + localName + localExtra
		const stored = buffer.subarray(start, start + compressedSize)
		const content = method === 0 ? stored : zlib.inflateRawSync(stored)

		members.push({ name, size, method, base64: content.toString('base64') })
	}

	return members
}

module.exports = { list }
