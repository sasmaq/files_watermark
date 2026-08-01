/**
 * Every request that carries binary bytes goes through here rather than through
 * `cy.request`.
 *
 * `cy.request` serialises its body as a UTF-8 string, which mangles any byte above
 * 0x7F — so a PDF uploaded through it arrives corrupt and the assertion that comes
 * back is about the corruption, not about the watermark. Node's `fetch` gives us
 * `ArrayBuffer` in both directions, so fixtures upload byte-exact and downloads can
 * be probed byte-exact.
 *
 * DAV, OCS and the public-link endpoints authenticate with basic auth, which they
 * accept and which needs no CSRF token. The app's own `/api/v1/*` endpoints do *not*
 * (they have no `#[NoCSRFRequired]`, so a basic-auth call gets HTTP 412), and are
 * driven from the browser session in `cypress/support/commands.js` instead.
 */

const BASE = process.env.NC_URL || 'http://localhost:8080'

const basic = (user, password) =>
	'Basic ' + Buffer.from(`${user}:${password}`).toString('base64')

const davRoot = (user) => `${BASE}/remote.php/dav/files/${encodeURIComponent(user)}`

/**
 * Percent-encode each segment, so spaces and unicode in names survive.
 */
const encodePath = (path) =>
	String(path)
		.split('/')
		.filter((segment) => segment !== '')
		.map(encodeURIComponent)
		.join('/')

/**
 * One request, with the body and the response both handled as bytes.
 *
 * Redirects are *not* followed by default. A 303 is information — the share page's
 * download button being a redirect onto the public DAV endpoint is the reason it is
 * covered at all — and a task that quietly followed it would report the destination's
 * status as if it were the origin's.
 */
async function raw({ url, method = 'GET', auth, headers = {}, body, follow = false }) {
	const response = await fetch(url, {
		method,
		redirect: follow ? 'follow' : 'manual',
		headers: {
			...(auth ? { Authorization: basic(auth.user, auth.password) } : {}),
			...headers,
		},
		body,
	})

	const bytes = Buffer.from(await response.arrayBuffer())

	return {
		status: response.status,
		headers: Object.fromEntries(response.headers.entries()),
		base64: bytes.toString('base64'),
		// Only meaningful for textual responses; binary callers use `base64`.
		text: bytes.toString('utf8'),
		length: bytes.length,
	}
}

/**
 * GET any absolute or root-relative URL. Used for public links and archive URLs.
 */
const httpGet = ({ url, user, password, headers, follow }) =>
	raw({
		url: url.startsWith('http') ? url : BASE + url,
		auth: user ? { user, password } : undefined,
		headers,
		follow,
	})

const davPut = ({ user, password, path, base64, contentType }) =>
	raw({
		url: `${davRoot(user)}/${encodePath(path)}`,
		method: 'PUT',
		auth: { user, password },
		headers: contentType ? { 'Content-Type': contentType } : {},
		body: Buffer.from(base64, 'base64'),
	})

/**
 * The same bytes onto many paths, a few requests at a time.
 *
 * The archive-cap spec needs 201 members before it can test anything, and 201
 * round-trips driven one `cy.task` at a time is over a minute of the suite's runtime
 * spent on setup.
 */
async function davPutMany({ user, password, paths, base64, concurrency = 8 }) {
	const queue = [...paths]
	const failures = []

	const worker = async () => {
		while (queue.length > 0) {
			const path = queue.shift()
			const response = await davPut({ user, password, path, base64 })
			if (response.status >= 400) {
				failures.push({ path, status: response.status })
			}
		}
	}

	await Promise.all(Array.from({ length: concurrency }, worker))

	return { uploaded: paths.length - failures.length, failures }
}

const davGet = ({ user, password, path, owner }) =>
	raw({
		url: `${davRoot(owner || user)}/${encodePath(path)}`,
		auth: { user, password },
	})

const davDelete = ({ user, password, path }) =>
	raw({
		url: `${davRoot(user)}/${encodePath(path)}`,
		method: 'DELETE',
		auth: { user, password },
	})

/** Server-side copy, for pulling a skeleton file into a spec's own folder. */
const davCopy = ({ user, password, from, to }) =>
	raw({
		url: `${davRoot(user)}/${encodePath(from)}`,
		method: 'COPY',
		auth: { user, password },
		headers: { Destination: `${davRoot(user)}/${encodePath(to)}`, Overwrite: 'T' },
	})

const davMkcol = ({ user, password, path }) =>
	raw({
		url: `${davRoot(user)}/${encodePath(path)}`,
		method: 'MKCOL',
		auth: { user, password },
	})

const davPropfind = ({ user, password, path, depth = '0', body }) =>
	raw({
		url: `${davRoot(user)}/${encodePath(path)}`,
		method: 'PROPFIND',
		auth: { user, password },
		headers: { Depth: String(depth), 'Content-Type': 'application/xml' },
		body,
	})

/**
 * A chunked upload, the way the web UI and the desktop client send large files:
 * parts PUT into `uploads/<user>/<id>/`, then one MOVE onto the destination.
 *
 * The final path is never PUT, which is exactly why `UploadWatermarkPlugin` hooks
 * `afterMethod:MOVE` as well — a PUT-only hook skips every chunked upload silently.
 */
async function davChunkedUpload({ user, password, path, base64, parts = 2 }) {
	const bytes = Buffer.from(base64, 'base64')
	const id = `e2e-${Date.now()}-${Math.floor(Math.random() * 1e6)}`
	const uploadRoot = `${BASE}/remote.php/dav/uploads/${encodeURIComponent(user)}/${id}`
	const auth = { user, password }

	const created = await raw({ url: uploadRoot, method: 'MKCOL', auth })
	if (created.status >= 400) {
		return { stage: 'mkcol', ...created }
	}

	const size = Math.ceil(bytes.length / parts)
	for (let index = 0; index < parts; index++) {
		const chunk = bytes.subarray(index * size, (index + 1) * size)
		if (chunk.length === 0) {
			continue
		}
		// Chunk names are ordered by string comparison, hence the padding.
		const put = await raw({
			url: `${uploadRoot}/${String(index + 1).padStart(5, '0')}`,
			method: 'PUT',
			auth,
			body: chunk,
		})
		if (put.status >= 400) {
			return { stage: `chunk-${index}`, ...put }
		}
	}

	const move = await raw({
		url: `${uploadRoot}/.file`,
		method: 'MOVE',
		auth,
		headers: { Destination: `${davRoot(user)}/${encodePath(path)}` },
	})

	return { stage: 'move', ...move }
}

/**
 * OCS (provisioning API, shares API). Always asks for JSON.
 */
async function ocs({ user, password, method = 'GET', path, form }) {
	const response = await raw({
		url: `${BASE}/ocs/v2.php${path}${path.includes('?') ? '&' : '?'}format=json`,
		method,
		auth: { user, password },
		headers: {
			'OCS-APIRequest': 'true',
			...(form ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}),
		},
		body: form ? new URLSearchParams(form).toString() : undefined,
	})

	let json = null
	try {
		json = JSON.parse(response.text)
	} catch {
		// Left null; the caller asserts on `status` and gets the body in `text`.
	}

	return { status: response.status, json, text: response.text }
}

module.exports = {
	BASE,
	httpGet,
	davPut,
	davPutMany,
	davGet,
	davDelete,
	davCopy,
	davMkcol,
	davPropfind,
	davChunkedUpload,
	ocs,
}
