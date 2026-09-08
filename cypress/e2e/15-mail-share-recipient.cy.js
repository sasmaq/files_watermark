/**
 * **A copy sent by email names the address it was sent to.**
 *
 * Nextcloud's "share by email" is a link share addressed to one recipient: the link goes
 * to a single address, and the share row records it. That makes it the one anonymous fetch
 * this app can name precisely - not "somebody with the link", but the person the owner
 * mailed it to - and `{email}` resolves to that address instead of falling back to whoever
 * published the file.
 *
 * The assertions read the **drawn text** out of the delivered PDF rather than checking that
 * a watermark exists. "A watermark was drawn" passes just as well when it names the wrong
 * person, which is the entire failure this feature exists to fix; only the decoded string
 * separates the two.
 *
 * Two negatives carry as much weight as the positive:
 *
 *  - an **ordinary public link** was sent to nobody in particular, so it keeps naming the
 *    publisher. A recipient invented for it would be a watermark claiming a reader that
 *    does not exist;
 *  - an **internal share** is a share too, and its "shared with" is an account name rather
 *    than an address. A copy stamped `[e2e-mail-reader]` where the template asked for an
 *    email is the shape of the bug a storage-type check alone would ship.
 *
 * The fixture is the suite's own generated PDF, deliberately. A real-world document brings
 * its own embedded fonts and `/ToUnicode` tables, and `probe:pdf` merges every CMap in the
 * file into one map - so the watermark's subset glyph ids collide with the document's and
 * the decoded text comes back as replacement characters. The generated fixture draws its
 * page with base Helvetica and embeds nothing, which leaves the watermark's own table the
 * only one in the delivered file.
 */

const folder = 'e2e-mail-share'
const file = `${folder}/report.pdf`

const OWNER_EMAIL = 'owner@example.org'
const FIRST_RECIPIENT = 'first@example.org'
const SECOND_RECIPIENT = 'second@example.org'

const readerUid = 'e2e-mail-reader'
const READER_EMAIL = 'reader@example.org'

/** Brackets so a rendered token is distinguishable from an empty one. */
const TEMPLATE = '[{email}]'

describe('Watermarking a share by email', () => {
	let reader
	let firstShare
	let secondShare
	let link
	let ownerEmailBefore

	before(() => {
		cy.ncLogin()

		// **Before the upload, and that ordering is load-bearing.** The spec that runs
		// before this one leaves `on_upload` behind, and `testIsolation` is off, so a file
		// uploaded under the inherited policy is *marked on the way in* - and a marked file
		// is watermarked for every reader whatever the share switches say. The negative
		// assertions below would then pass for the wrong reason, and the last one, which
		// asks for a copy with no watermark at all, would fail outright.
		cy.wmSetPolicy({ trigger: 'on_demand' })
		cy.wmFolder(folder)

		// Whatever the instance's admin had, so the run leaves the account as it found it.
		cy.task('nc:ocs', {
			user: Cypress.env('ncUser'),
			password: Cypress.env('ncPassword'),
			path: `/cloud/users/${Cypress.env('ncUser')}`,
		}).then((response) => {
			ownerEmailBefore = response.json?.ocs?.data?.email ?? ''
		})
		cy.wmSetEmail(Cypress.env('ncUser'), OWNER_EMAIL)

		cy.wmUser(readerUid).then((credentials) => {
			reader = credentials
		})
		cy.wmSetEmail(readerUid, READER_EMAIL)

		cy.task('fixture:pdf', { pages: 2, text: 'mail share' })
			.then((base64) => cy.wmUpload(file, base64))

		cy.wmUnshareAll(`/${file}`)
		// Two addresses on one file: the copy each fetches has to name its own recipient,
		// which is the property a per-file lookup could not have.
		cy.wmShare({ path: `/${file}`, shareType: 4, shareWith: FIRST_RECIPIENT, permissions: 17 })
			.then((share) => {
				firstShare = share
			})
		cy.wmShare({ path: `/${file}`, shareType: 4, shareWith: SECOND_RECIPIENT, permissions: 17 })
			.then((share) => {
				secondShare = share
			})
		cy.wmShare({ path: `/${file}`, shareType: 3, permissions: 1 }).then((share) => {
			link = share
		})
		cy.wmShare({ path: `/${file}`, shareWith: readerUid, permissions: 17 })
	})

	after(() => {
		cy.wmUnshareAll(`/${file}`)
		cy.wmSetEmail(Cypress.env('ncUser'), ownerEmailBefore)
		cy.task('nc:delete', {
			user: Cypress.env('ncUser'),
			password: Cypress.env('ncPassword'),
			path: folder,
		})
	})

	beforeEach(() => {
		cy.ncLogin()
		// Nothing is marked in this spec: the watermark comes from the fetch, which is what
		// puts a share - and therefore a recipient - in the picture at all.
		cy.wmSetPolicy({
			trigger: 'on_demand',
			textTemplate: TEMPLATE,
			watermarkExternalShares: true,
			watermarkInternalShares: true,
		})
	})

	/**
	 * The watermark as a reader would copy it out of the delivered file.
	 *
	 * Every tile draws the same string, so the first run is the whole assertion; joining
	 * them all would only repeat it.
	 */
	const drawnText = (base64) =>
		cy.task('probe:pdf', { base64 }).then((pdf) => {
			expect(pdf.watermarked, 'the copy carries no watermark at all').to.be.true
			expect(pdf.pages, 'the delivered copy lost pages').to.eq(2)
			expect(pdf.textRuns.length, 'nothing was drawn on the page').to.be.greaterThan(0)

			return pdf.textRuns[0].map((code) => String.fromCharCode(code)).join('')
		})

	/** A public-link fetch over the DAV server the share page's download goes through. */
	const fetchByToken = (token) =>
		cy.task('nc:get', { url: `/public.php/dav/files/${token}` }).then((response) => {
			expect(response.status, 'the link did not serve the file').to.eq(200)
			return response.base64
		})

	it('stamps the address the share was sent to', () => {
		fetchByToken(firstShare.token)
			.then(drawnText)
			.should('eq', `[${FIRST_RECIPIENT}]`)
	})

	/**
	 * **The assertion that makes this a per-share answer rather than a per-file one.** One
	 * file, two addresses: a lookup that found "the share on this file" would name whichever
	 * came back first and would be right half the time.
	 */
	it('names each recipient on their own copy of the same file', () => {
		fetchByToken(firstShare.token)
			.then(drawnText)
			.should('eq', `[${FIRST_RECIPIENT}]`)

		fetchByToken(secondShare.token)
			.then(drawnText)
			.should('eq', `[${SECOND_RECIPIENT}]`)
	})

	it('carries the recipient through the share page download link too', () => {
		cy.task('nc:get', { url: `/s/${firstShare.token}/download`, follow: true })
			.then((response) => {
				expect(response.status).to.eq(200)
				return response.base64
			})
			.then(drawnText)
			.should('eq', `[${FIRST_RECIPIENT}]`)
	})

	/**
	 * An ordinary link was sent to nobody, so there is no recipient to name and the copy
	 * keeps naming the person accountable for having published it.
	 */
	it('leaves an ordinary public link naming the publisher', () => {
		fetchByToken(link.token).then(drawnText).should('eq', `[${OWNER_EMAIL}]`)
	})

	/**
	 * An internal share is an `ISharedStorage` exactly as a mail share is, and its "shared
	 * with" is a **uid**. The reader has an account, so the copy names the address on that
	 * account - never `[e2e-mail-reader]`, which is what reading any share's recipient as an
	 * address would produce.
	 */
	it("names an internal reader's own address, not their account name", () => {
		cy.wmDownload('report.pdf', { as: reader })
			.then(drawnText)
			.should('eq', `[${READER_EMAIL}]`)
	})

	/**
	 * The switch still decides *whether* a copy is watermarked; the recipient only decides
	 * what it says. With external shares off, the mail share hands back the stored bytes
	 * like any other link.
	 */
	it('does not watermark a mail share when the policy does not ask it to', () => {
		cy.wmSetPolicy({ trigger: 'on_demand', textTemplate: TEMPLATE })

		fetchByToken(firstShare.token).then((base64) => {
			cy.task('probe:pdf', { base64 }).its('watermarked').should('be.false')
		})
	})
})
