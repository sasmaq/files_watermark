/**
 * The vocabulary the specs are written in.
 *
 * Two transports, split for a reason that bit early on:
 *
 *  - **the app's own `/api/v1/*` endpoints run through the browser session.** None of
 *    them carries `#[NoCSRFRequired]`, so a basic-auth call gets HTTP 412 "CSRF check
 *    failed" — which is correct of the app and means every config change, apply and
 *    remove here goes through a logged-in session with a request token, exactly as
 *    the settings page does;
 *  - **everything carrying file bytes runs through Node tasks** (`cypress/tasks/`),
 *    because `cy.request` serialises bodies as UTF-8 and would corrupt a PDF on the
 *    way in and on the way out.
 */

const admin = () => ({
	user: Cypress.env('ncUser'),
	password: Cypress.env('ncPassword'),
})

/** Log in through the real login form, once per run. */
Cypress.Commands.add('ncLogin', (user, password) => {
	const credentials = user ? { user, password } : admin()

	cy.session(
		['nc-login', credentials.user],
		() => {
			cy.visit('/login')
			cy.get('input#user').type(credentials.user)
			cy.get('input#password').type(credentials.password, { log: false })
			cy.get('form[name="login"], .login-form').first().submit()
			// The dashboard is heavy; any authenticated page proves the session.
			cy.location('pathname', { timeout: 30000 }).should('not.include', '/login')
		},
		{
			validate() {
				cy.request('/csrftoken').its('status').should('eq', 200)
			},
			cacheAcrossSpecs: true,
		},
	)
})

/** A request token for the current session. */
Cypress.Commands.add('wmToken', () => {
	cy.request('/csrftoken').its('body.token')
})

/** Call one of the app's API endpoints as the logged-in user. */
Cypress.Commands.add('wmApi', (method, endpoint, body = undefined, options = {}) => {
	cy.wmToken().then((token) => {
		cy.request({
			method,
			url: `/apps/files_watermark${endpoint}`,
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			body,
			failOnStatusCode: options.failOnStatusCode !== false,
		})
	})
})

/**
 * Put the server-wide policy into a known state.
 *
 * Every spec calls this rather than assuming what the last one left behind: there is
 * exactly one config for the whole server, so specs would otherwise inherit each
 * other's trigger.
 */
Cypress.Commands.add('wmSetPolicy', (policy = {}) => {
	cy.wmApi('GET', '/api/v1/config').then((response) => {
		const existing = response.body.configs?.[0]

		return cy.wmApi('POST', '/api/v1/config', {
			id: existing?.id ?? null,
			type: 'text',
			textTemplate: '{displayname} - {date}',
			trigger: 'on_demand',
			position: 'diagonal',
			opacity: 40,
			fontSize: 24,
			color: '#808080',
			rotation: 45,
			mimeTypes: null,
			folderTag: null,
			// Sent explicitly, matching the shipped default. A spec that wants delivery
			// rows has to ask for them, exactly as an admin does.
			logDelivery: false,
			...policy,
		})
	})
})

Cypress.Commands.add('wmApply', (path, options = {}) =>
	cy.wmApi('POST', '/api/v1/apply', { path }, options))

Cypress.Commands.add('wmRemove', (path, options = {}) =>
	cy.wmApi('POST', '/api/v1/remove', { path }, options))

/**
 * `GET /api/v1/download`, which streams a watermarked copy of one file.
 *
 * It goes through `cy.request` rather than a task because it is session-authenticated
 * like the rest of the app API — and unlike an upload, a GET has no request body to
 * be mangled, so `encoding: 'base64'` brings the bytes back intact.
 */
Cypress.Commands.add('wmApiDownload', (path) => {
	cy.wmToken().then((token) =>
		cy.request({
			url: `/apps/files_watermark/api/v1/download?path=${encodeURIComponent(path)}`,
			headers: { requesttoken: token },
			encoding: 'base64',
		}).then((response) => {
			expect(response.status, `api download ${path}`).to.eq(200)
			return response.body
		}),
	)
})

/** Create a folder, replacing any left over from an earlier run. */
Cypress.Commands.add('wmFolder', (path) => {
	cy.task('nc:delete', { ...admin(), path })
	cy.task('nc:mkcol', { ...admin(), path }).its('status').should('be.oneOf', [201, 405])
})

/** Upload bytes to a path, as a plain single-request PUT. */
Cypress.Commands.add('wmUpload', (path, base64, options = {}) => {
	const auth = options.as ?? admin()

	cy.task('nc:put', { ...auth, path, base64, contentType: options.contentType })
		.then((response) => {
			expect(response.status, `upload ${path}`).to.be.oneOf([201, 204])
			return base64
		})
})

/** Download a path over WebDAV and hand back the bytes as base64. */
Cypress.Commands.add('wmDownload', (path, options = {}) => {
	const auth = options.as ?? admin()

	cy.task('nc:download', { ...auth, path, owner: options.owner })
		.then((response) => {
			if (options.expectStatus) {
				expect(response.status, `download ${path}`).to.eq(options.expectStatus)
				return response
			}
			expect(response.status, `download ${path}`).to.eq(200)
			return response.base64
		})
})

/**
 * A test user with a known password.
 *
 * Recreated rather than reused: a leftover account from an earlier run carries its
 * old password and its old received shares, and either one turns a real failure into
 * an unreadable one. Deleting first makes the suite re-runnable without that.
 */
Cypress.Commands.add('wmUser', (uid, password = 'e2e-Password-1') => {
	cy.task('nc:ocs', { ...admin(), method: 'DELETE', path: `/cloud/users/${uid}` })

	cy.task('nc:ocs', {
		...admin(),
		method: 'POST',
		path: '/cloud/users',
		form: { userid: uid, password },
	}).then((response) => {
		expect(response.json?.ocs?.meta?.statuscode, `create user ${uid}`).to.be.oneOf([100, 200])
		return { user: uid, password }
	})
})

/**
 * Share a path, either with a user (`shareType` 0) or as a public link (3).
 *
 * Returns the OCS share data, so link specs can read `token` and `url`.
 */
Cypress.Commands.add('wmShare', ({ path, shareWith, shareType = 0, permissions = 17 }) => {
	cy.task('nc:ocs', {
		...admin(),
		method: 'POST',
		path: '/apps/files_sharing/api/v1/shares',
		form: {
			path,
			shareType: String(shareType),
			...(shareWith ? { shareWith } : {}),
			permissions: String(permissions),
		},
	}).then((response) => {
		// Core rate-limits share creation at 20 per 10 minutes per user, and a suite that
		// rebuilds its fixtures every run legitimately looks like abuse: two full runs
		// inside the window exhaust the budget. The 429 body is empty, so without this
		// the failure reads "expected undefined to be one of [100, 200]" and points at
		// nothing.
		if (response.status === 429) {
			throw new Error(
				`share ${path} was rate-limited (HTTP 429). Core allows 20 shares per 10 minutes `
				+ 'per user. On a test instance, turn the limiter off:\n'
				+ '  occ config:system:set ratelimit.protection.enabled --value false --type boolean',
			)
		}

		// The HTTP status and the body go into the message for every other failure: an OCS
		// error that is not JSON says nothing on its own.
		expect(
			response.json?.ocs?.meta?.statuscode,
			`share ${path} — HTTP ${response.status}, body: ${response.text.slice(0, 300)}`,
		).to.be.oneOf([100, 200])

		return response.json.ocs.data
	})
})

/** Delete every share on a path, so a re-run does not stack them. */
Cypress.Commands.add('wmUnshareAll', (path) => {
	cy.task('nc:ocs', {
		...admin(),
		path: `/apps/files_sharing/api/v1/shares?path=${encodeURIComponent(path)}`,
	}).then((response) => {
		const shares = response.json?.ocs?.data ?? []
		shares.forEach((share) => {
			cy.task('nc:ocs', {
				...admin(),
				method: 'DELETE',
				path: `/apps/files_sharing/api/v1/shares/${share.id}`,
			})
		})
	})
})

/** The numeric file id of a path, for the endpoints that key off it (previews). */
Cypress.Commands.add('wmFileId', (path, options = {}) => {
	const auth = options.as ?? admin()

	cy.task('nc:propfind', {
		...auth,
		path,
		depth: 0,
		body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
			+ '<d:prop><oc:fileid /></d:prop></d:propfind>',
	}).then((response) => {
		const match = response.text.match(/<oc:fileid>(\d+)<\/oc:fileid>/)
		expect(match, `no file id for ${path}`).to.not.be.null
		return Number(match[1])
	})
})

/**
 * The `nc:is-watermarked` DAV property, as the Files app reads it.
 *
 * Absent is reported as `null` rather than as false, because the two mean different
 * things to the client and conflating them here would hide a plugin that stopped
 * answering.
 */
Cypress.Commands.add('wmIsWatermarkedProp', (path) => {
	cy.task('nc:propfind', {
		...admin(),
		path,
		depth: 0,
		body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">'
			+ '<d:prop><nc:is-watermarked /></d:prop></d:propfind>',
	}).then((response) => {
		expect(response.status, `propfind ${path}`).to.eq(207)
		const match = response.text.match(/<nc:is-watermarked>([^<]*)<\/nc:is-watermarked>/)
		return match === null ? null : match[1].trim()
	})
})
