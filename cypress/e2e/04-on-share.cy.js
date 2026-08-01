/**
 * On share: watermarked at *delivery* time, for everyone except the owner.
 *
 * This is the trigger with the most ways to be quietly wrong, and every one of them
 * produces a file that opens fine:
 *
 *  - the owner's own fetch must stay clean, so "everything is watermarked" is not a
 *    passing answer here — it is a different bug;
 *  - public links are served by a **second** Sabre server (`public.php/dav`) that
 *    never fires `SabrePluginAddEvent`, so a plugin registered once covers the
 *    authenticated path only;
 *  - public links are served off the *owner's* storage, so a recipient test based on
 *    the storage backend alone reports "owner access" and waves them through;
 *  - previews are rendered from the clean original and cached per file rather than
 *    per viewer, so an unblocked thumbnail hands a recipient the content the
 *    watermark exists to mark.
 *
 * Hence: the same file, fetched five ways.
 */

const folder = 'e2e-on-share'
const file = `${folder}/shared.pdf`
const image = `${folder}/shared.png`
const recipientUid = 'e2e-recipient'

describe('On-share watermarking', () => {
	let original
	let recipient
	let link

	before(() => {
		cy.ncLogin()
		cy.wmFolder(folder)
		cy.wmUser(recipientUid).then((credentials) => {
			recipient = credentials
		})

		cy.task('fixture:pdf', { pages: 2, text: 'on share' }).then((base64) => {
			original = base64
			cy.wmUpload(file, base64)
		})
		cy.task('fixture:png', { width: 400, height: 300 }).then((base64) => {
			cy.wmUpload(image, base64, { contentType: 'image/png' })
		})

		// Shared before the policy is switched on, which is the app's own model:
		// nothing is copied or rewritten at share-creation time.
		cy.wmUnshareAll(`/${file}`)
		cy.wmUnshareAll(`/${image}`)
		cy.wmShare({ path: `/${file}`, shareWith: recipientUid })
		cy.wmShare({ path: `/${image}`, shareWith: recipientUid })
		cy.wmShare({ path: `/${file}`, shareType: 3, permissions: 1 }).then((share) => {
			link = share
		})

		cy.wmSetPolicy({ trigger: 'on_share' })
	})

	after(() => {
		cy.wmSetPolicy({ trigger: 'on_demand' })
		cy.wmUnshareAll(`/${file}`)
		cy.wmUnshareAll(`/${image}`)
		cy.task('nc:delete', { user: Cypress.env('ncUser'), password: Cypress.env('ncPassword'), path: folder })
	})

	it("watermarks the recipient's download", () => {
		// A single-file share mounts at the recipient's own root under the file name.
		cy.wmDownload('shared.pdf', { as: recipient }).then((base64) => {
			expect(base64, 'the recipient got the stored bytes').to.not.eq(original)
			cy.task('probe:pdf', { base64 }).then((pdf) => {
				expect(pdf.watermarked, 'the recipient got an unwatermarked copy').to.be.true
				expect(pdf.pages).to.eq(2)
			})
		})
	})

	it("leaves the owner's own download alone", () => {
		cy.wmDownload(file).should('eq', original)
	})

	it('watermarks a public-link fetch through the public DAV server', () => {
		cy.task('nc:get', { url: `/public.php/dav/files/${link.token}` }).then((response) => {
			expect(response.status, 'the public link did not serve the file').to.eq(200)
			cy.task('probe:pdf', { base64: response.base64 }).then((pdf) => {
				expect(pdf.watermarked, 'a public-link visitor got the clean original').to.be.true
			})
		})
	})

	it('watermarks the share page\'s own download link', () => {
		// `/s/<token>/download` is what the share page's button points at. It answers
		// 303 onto the public DAV endpoint rather than serving bytes itself, which is
		// why it is not a way round the interceptor — but that is worth following
		// rather than assuming, since a direct handler here would bypass Sabre whole.
		cy.task('nc:get', { url: `/s/${link.token}/download` }).its('status').should('eq', 303)

		cy.task('nc:get', { url: `/s/${link.token}/download`, follow: true }).then((response) => {
			expect(response.status).to.eq(200)
			cy.task('probe:pdf', { base64: response.base64 }).then((pdf) => {
				expect(pdf.watermarked, 'the share page served the clean original').to.be.true
			})
		})
	})

	it('blocks the preview a recipient would otherwise read the content from', () => {
		cy.wmFileId('shared.png', { as: recipient }).then((fileId) => {
			cy.task('nc:get', {
				url: `/index.php/core/preview?fileId=${fileId}&x=128&y=128&a=1`,
				user: recipient.user,
				password: recipient.password,
			}).its('status').should('not.eq', 200)
		})
	})

	it('blocks the public share page preview too', () => {
		cy.wmShare({ path: `/${image}`, shareType: 3, permissions: 1 }).then((imageLink) => {
			cy.task('nc:get', {
				url: `/index.php/apps/files_sharing/publicpreview/${imageLink.token}?x=128&y=128&a=1`,
			}).its('status').should('not.eq', 200)
		})
	})

	it('keeps the owner\'s own previews working', () => {
		cy.wmFileId(image).then((fileId) => {
			cy.task('nc:get', {
				url: `/index.php/core/preview?fileId=${fileId}&x=128&y=128&a=1`,
				user: Cypress.env('ncUser'),
				password: Cypress.env('ncPassword'),
			}).its('status').should('eq', 200)
		})
	})
})
