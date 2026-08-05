/**
 * **The behaviour this whole app exists for: every reader gets their own copy.**
 *
 * The same marked file, fetched by four different readers over four different routes, has
 * to come back four times with four different names on it - and the stored file has to be
 * none of them. That is the claim a burned-in watermark could never make: it names whoever
 * triggered it, which for a shared document is the person who uploaded it rather than the
 * person who walked out with it.
 *
 * This replaces the old `03-on-download` and `04-on-share` specs, which tested two policies
 * that no longer exist. What survives from them is the list of ways delivery can be quietly
 * wrong, because none of those went away - every one produces a file that opens fine:
 *
 *  - public links are served by a **second** Sabre server (`public.php/dav`) that never
 *    fires `SabrePluginAddEvent`, so a plugin registered once covers the authenticated
 *    path only;
 *  - the share page's own download button goes somewhere else again;
 *  - previews are rendered from the clean original and cached per file rather than per
 *    viewer, so an unstamped thumbnail hands a reader the content the watermark exists to
 *    mark - and a *stamped* one that reached that cache would hand the next reader
 *    somebody else's name.
 */

const folder = 'e2e-per-reader'
const file = `${folder}/report.pdf`
const image = `${folder}/photo.png`
const recipientUid = 'e2e-recipient'

describe('Per-reader watermarking', () => {
	let original
	let recipient
	let link

	before(() => {
		cy.ncLogin()
		cy.wmSetPolicy({ trigger: 'on_demand', textTemplate: '{displayname}' })
		cy.wmFolder(folder)
		cy.wmUser(recipientUid).then((credentials) => {
			recipient = credentials
		})

		cy.task('fixture:pdf', { pages: 2, text: 'per reader' }).then((base64) => {
			original = base64
			cy.wmUpload(file, base64)
		})
		cy.task('fixture:png', { width: 400, height: 300 }).then((base64) => {
			cy.wmUpload(image, base64, { contentType: 'image/png' })
		})

		// Shared before the mark is placed, which is the app's own model: nothing is copied
		// or rewritten at share-creation time.
		cy.wmUnshareAll(`/${file}`)
		cy.wmUnshareAll(`/${image}`)
		cy.wmShare({ path: `/${file}`, shareWith: recipientUid })
		cy.wmShare({ path: `/${image}`, shareWith: recipientUid })
		cy.wmShare({ path: `/${file}`, shareType: 3, permissions: 1 }).then((share) => {
			link = share
		})

		cy.wmApply(file)
		cy.wmApply(image)
	})

	after(() => {
		cy.wmUnshareAll(`/${file}`)
		cy.wmUnshareAll(`/${image}`)
		cy.task('nc:delete', { user: Cypress.env('ncUser'), password: Cypress.env('ncPassword'), path: folder })
	})

	it("watermarks the recipient's download", () => {
		// A single-file share mounts at the recipient's own root under the file name.
		cy.wmDownload('report.pdf', { as: recipient }).then((base64) => {
			expect(base64, 'the recipient got the stored bytes').to.not.eq(original)
			cy.task('probe:pdf', { base64 }).then((pdf) => {
				expect(pdf.watermarked, 'the recipient got an unwatermarked copy').to.be.true
				expect(pdf.pages).to.eq(2)
			})
		})
	})

	/**
	 * **The owner is watermarked too**, and that is a deliberate change rather than a
	 * regression. `on_share` exempted the owner; nothing does now. The watermark carries the
	 * reader's identity, and an owner reading their own file is a reader.
	 */
	it("watermarks the owner's own download", () => {
		cy.wmDownload(file).then((base64) => {
			expect(base64, 'the owner was handed the stored bytes').to.not.eq(original)
			cy.task('probe:pdf', { base64 }).its('watermarked').should('be.true')
		})
	})

	/**
	 * Two readers, two different files - which no single stored copy can satisfy.
	 *
	 * Byte-inequality is the assertion because it is the one that cannot be satisfied by
	 * accident: two renders of the same document with the same name would differ only if
	 * something non-deterministic crept in, and two renders with *different* names cannot
	 * be equal.
	 */
	it('gives two readers of the same file two different documents', () => {
		let owners
		cy.wmDownload(file).then((base64) => {
			owners = base64
		})

		cy.wmDownload('report.pdf', { as: recipient }).then((base64) => {
			expect(base64, 'both readers were handed the same copy, so neither is named')
				.to.not.eq(owners)
		})
	})

	it('watermarks a public-link fetch through the public DAV server', () => {
		cy.task('nc:get', { url: `/public.php/dav/files/${link.token}` }).then((response) => {
			expect(response.status, 'the public link did not serve the file').to.eq(200)
			cy.task('probe:pdf', { base64: response.base64 }).then((pdf) => {
				expect(pdf.watermarked, 'a public-link visitor got the clean original').to.be.true
			})
		})
	})

	it("watermarks the share page's own download link", () => {
		// `/s/<token>/download` is what the share page's button points at. It answers 303
		// onto the public DAV endpoint rather than serving bytes itself, which is why it is
		// not a way round the interceptor - but that is worth following rather than
		// assuming, since a direct handler here would bypass Sabre whole.
		cy.task('nc:get', { url: `/s/${link.token}/download` }).its('status').should('eq', 303)

		cy.task('nc:get', { url: `/s/${link.token}/download`, follow: true }).then((response) => {
			expect(response.status).to.eq(200)
			cy.task('probe:pdf', { base64: response.base64 }).then((pdf) => {
				expect(pdf.watermarked, 'the share page served the clean original').to.be.true
			})
		})
	})

	// ---------------------------------------------------------------------
	// Previews
	// ---------------------------------------------------------------------

	/**
	 * Previews are **served** now, where they used to be blocked outright.
	 *
	 * Blocking was the only safe answer while a watermarked thumbnail could reach core's
	 * per-file preview cache. The stamping happens after that cache, per response, so the
	 * recipient gets a preview again - and it carries their name rather than the owner's.
	 */
	it('serves the recipient a preview rather than blocking it', () => {
		cy.wmFileId('photo.png', { as: recipient }).then((fileId) => {
			cy.task('nc:get', {
				url: `/index.php/core/preview?fileId=${fileId}&x=128&y=128&a=1`,
				user: recipient.user,
				password: recipient.password,
			}).its('status').should('eq', 200)
		})
	})

	/**
	 * **The leak the preview cache would cause, asserted directly.**
	 *
	 * Core caches previews by file id and dimensions and never by viewer. If a stamped
	 * image ever reaches that cache, the next person to open the folder is served the first
	 * person's name - so the two readers' previews of the same file, at the same size, must
	 * not be the same bytes. This is the single most valuable assertion in the file.
	 */
	it('gives two readers different previews of the same image', () => {
		let ownerPreview

		cy.wmFileId(image).then((fileId) => {
			cy.task('nc:get', {
				url: `/index.php/core/preview?fileId=${fileId}&x=128&y=128&a=1`,
				user: Cypress.env('ncUser'),
				password: Cypress.env('ncPassword'),
			}).then((response) => {
				expect(response.status).to.eq(200)
				ownerPreview = response.base64
			})
		})

		cy.wmFileId('photo.png', { as: recipient }).then((fileId) => {
			cy.task('nc:get', {
				url: `/index.php/core/preview?fileId=${fileId}&x=128&y=128&a=1`,
				user: recipient.user,
				password: recipient.password,
			}).then((response) => {
				expect(response.status).to.eq(200)
				expect(response.base64, 'both readers were served the same cached preview')
					.to.not.eq(ownerPreview)
			})
		})
	})

	/**
	 * A watermarked preview must be uncacheable by anything downstream - a shared proxy, a
	 * second account on one browser profile - for the same reason it must not reach core's
	 * cache. Re-rendering on every scroll is the cheaper mistake by a wide margin.
	 */
	it('marks the preview response as uncacheable', () => {
		cy.wmFileId(image).then((fileId) => {
			cy.task('nc:get', {
				url: `/index.php/core/preview?fileId=${fileId}&x=128&y=128&a=1`,
				user: Cypress.env('ncUser'),
				password: Cypress.env('ncPassword'),
			}).then((response) => {
				expect(String(response.headers['cache-control'] ?? '')).to.contain('no-store')
			})
		})
	})

	it('watermarks the public share page preview too', () => {
		cy.wmShare({ path: `/${image}`, shareType: 3, permissions: 1 }).then((imageLink) => {
			cy.task('nc:get', {
				url: `/index.php/apps/files_sharing/publicpreview/${imageLink.token}?x=128&y=128&a=1`,
			}).then((response) => {
				expect(response.status, 'the public preview was refused').to.eq(200)
				expect(String(response.headers['cache-control'] ?? '')).to.contain('no-store')
			})
		})
	})

	it('leaves an unmarked file\'s preview entirely alone', () => {
		const clean = `${folder}/clean.png`
		cy.task('fixture:png', { width: 200, height: 200 }).then((base64) => {
			cy.wmUpload(clean, base64, { contentType: 'image/png' })
		})

		cy.wmFileId(clean).then((fileId) => {
			cy.task('nc:get', {
				url: `/index.php/core/preview?fileId=${fileId}&x=128&y=128&a=1`,
				user: Cypress.env('ncUser'),
				password: Cypress.env('ncPassword'),
			}).then((response) => {
				expect(response.status).to.eq(200)
				// Core's own caching headers, not ours - the middleware never ran.
				expect(String(response.headers['cache-control'] ?? '')).to.not.contain('no-store')
			})
		})
	})
})
