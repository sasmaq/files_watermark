const { execFile } = require('child_process')
const http = require('http')

/**
 * Runs `occ` against the instance under test.
 *
 * This exists for one assertion the unit tests cannot make: they stub `IAppConfig`, so
 * they prove the *plugin* honours whatever `ArchiveLimits` returns and prove nothing
 * about the key an admin actually types. `occ config:app:set files_watermark
 * archive_max_members` is that key - a rename on either side leaves both suites green
 * and the setting inert, which is the exact failure the removed group/user overrides
 * were.
 *
 * ---------------------------------------------------------------------------
 * TWO WAYS TO REACH IT, BECAUSE THE RUNNER CANNOT SPAWN THE FIRST ONE.
 *
 * The default spawns `docker compose exec`, which works when Cypress runs on the host.
 * It does not work in the arrangement `cypress/README.md` recommends for macOS - the
 * official `cypress/included` image - because that container has **no Docker CLI**: the
 * spawn fails `ENOENT`, `code` comes back as a string, and the two specs that configure
 * the app (`06-archive-caps`, `11-prune-log`) fail on their first `occ` call. Seven
 * assertions that looked like coverage were reporting nothing but the absence of a binary.
 *
 * So when `NC_OCC_CONTAINER` names a container and the Docker socket is mounted, this
 * talks to the **Engine API** over that socket instead. No CLI needed - the runner image
 * already has Node, and `/var/run/docker.sock` is a bind mount away.
 * ---------------------------------------------------------------------------
 *
 * `NC_OCC` still overrides everything for a real host
 * (e.g. `sudo -u www-data php /var/www/nextcloud/occ`).
 */
const COMMAND = process.env.NC_OCC
	|| 'docker compose exec -T -u www-data nextcloud php occ'

/** The container to exec into, and the socket to reach it through. */
const CONTAINER = process.env.NC_OCC_CONTAINER || ''
const SOCKET = process.env.DOCKER_SOCKET || '/var/run/docker.sock'

/**
 * One request to the Docker Engine API over the unix socket.
 *
 * @param {string} method HTTP method
 * @param {string} path API path
 * @param {object|null} body JSON body, or null
 * @param {boolean} raw resolve the raw stream buffer rather than parsed JSON
 * @return {Promise<any>} the parsed response, or the buffer when `raw`
 */
function engine(method, path, body = null, raw = false) {
	return new Promise((resolve, reject) => {
		const payload = body === null ? null : JSON.stringify(body)
		const req = http.request({
			socketPath: SOCKET,
			method,
			path,
			headers: payload === null
				? {}
				: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload) },
		}, (res) => {
			const chunks = []
			res.on('data', (chunk) => chunks.push(chunk))
			res.on('end', () => {
				const buffer = Buffer.concat(chunks)
				if (raw) {
					resolve(buffer)
					return
				}
				try {
					resolve(buffer.length ? JSON.parse(buffer.toString('utf8')) : {})
				} catch (e) {
					reject(new Error(`docker api: ${buffer.toString('utf8').slice(0, 200)}`))
				}
			})
		})
		req.on('error', reject)
		if (payload !== null) {
			req.write(payload)
		}
		req.end()
	})
}

/**
 * Split Docker's multiplexed exec stream into stdout and stderr.
 *
 * Each frame is an 8-byte header - stream type in byte 0, big-endian length in bytes
 * 4-7 - followed by that many payload bytes. Without this the two streams arrive
 * interleaved with their headers still in them, and an assertion on `stdout` matches
 * against binary noise.
 *
 * @param {Buffer} buffer the raw exec stream
 * @return {{stdout: string, stderr: string}} the two streams, separated
 */
function demultiplex(buffer) {
	let stdout = ''
	let stderr = ''
	let offset = 0

	while (offset + 8 <= buffer.length) {
		const type = buffer[offset]
		const length = buffer.readUInt32BE(offset + 4)
		const payload = buffer.subarray(offset + 8, offset + 8 + length).toString('utf8')
		if (type === 2) {
			stderr += payload
		} else {
			stdout += payload
		}
		offset += 8 + length
	}

	// A stream with no frame headers at all (an old daemon, or a TTY exec) is stdout.
	return offset === 0 ? { stdout: buffer.toString('utf8'), stderr: '' } : { stdout, stderr }
}

/**
 * Run occ by exec-ing into the container through the Docker Engine API.
 *
 * @param {string[]} args the `occ` arguments
 * @return {Promise<{code: number, stdout: string, stderr: string}>} the result
 */
async function occViaEngine(args) {
	const created = await engine('POST', `/containers/${CONTAINER}/exec`, {
		AttachStdout: true,
		AttachStderr: true,
		User: 'www-data',
		Cmd: ['php', 'occ', ...args],
	})

	if (!created.Id) {
		return { code: 1, stdout: '', stderr: `docker exec create failed: ${JSON.stringify(created)}` }
	}

	const stream = await engine('POST', `/exec/${created.Id}/start`, { Detach: false, Tty: false }, true)
	const { stdout, stderr } = demultiplex(stream)
	const inspected = await engine('GET', `/exec/${created.Id}/json`)

	return { code: inspected.ExitCode ?? 0, stdout, stderr }
}

/**
 * @param {{args: string[], timeout?: number}} options the `occ` arguments to run
 * @return {Promise<{code: number, stdout: string, stderr: string}>} never rejects -
 *   a non-zero exit is a result the spec asserts on, not a harness error
 */
function occ({ args = [], timeout = 120000 }) {
	if (CONTAINER !== '') {
		return occViaEngine(args).catch((e) => ({
			code: 1,
			stdout: '',
			stderr: `docker engine api (${SOCKET}): ${e.message}`,
		}))
	}

	const [file, ...prefix] = COMMAND.split(' ')

	return new Promise((resolve) => {
		execFile(file, [...prefix, ...args], { timeout }, (error, stdout, stderr) => {
			resolve({
				code: error?.code ?? 0,
				stdout: String(stdout),
				stderr: String(stderr),
			})
		})
	})
}

module.exports = { occ, COMMAND }
