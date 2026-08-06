/**
 * **Watermarking what leaves through a share, with nothing marked.**
 *
 * Every other spec in this suite marks a file first. Nothing here is ever marked: the
 * watermark comes from the *fetch* - an admin switch saying that a copy leaving through an
 * internal share, or through a public link, carries one. That makes the interesting
 * assertions the negative ones, and they are the reason this spec exists rather than a
 * couple of extra cases in `03-per-reader`:
 *
 *  - the **owner** of the very same file still gets the stored bytes, which no mark could
 *    ever allow;
 *  - the two switches are independent, so one on and one off has to leave the other
 *    audience's copy clean;
 *  - switching both off again returns every reader to clean bytes **immediately**, because
 *    nothing was stored on the way in - which is the whole reason these are switches on the
 *    fetch rather than a third trigger.
 *
 * Byte-equality against the uploaded fixture is the clean assertion, and `probe:pdf` the
 * watermarked one. Equality is the stronger of the two: a copy that has been through the
 * renderer cannot come back byte-identical, whatever it drew.
 */

const folder = 'e2e-share-switches'
const file = `${folder}/report.pdf`
const recipientUid = 'e2e-share-recipient'

describe('Watermarking shared files', () => {
	let original
	let recipient
	let link

	before(() => {
		cy.ncLogin()
		cy.wmFolder(folder)
		cy.wmUser(recipientUid).then((credentials) => {
			recipient = credentials
		})

		cy.task('fixture:pdf', { pages: 2, text: 'share switches' }).then((base64) => {
			original = base64
			cy.wmUpload(file, base64)
		})

		cy.wmUnshareAll(`/${file}`)
		// Read-only, which is all a watermark policy has any business needing.
		cy.wmShare({ path: `/${file}`, shareWith: recipientUid, permissions: 17 })
		cy.wmShare({ path: `/${file}`, shareType: 3, permissions: 1 }).then((share) => {
			link = share
		})
	})

	after(() => {
		cy.wmUnshareAll(`/${file}`)
		cy.task('nc:delete', { user: Cypress.env('ncUser'), password: Cypress.env('ncPassword'), path: folder })
	})

	/** The recipient's copy: a single-file share mounts at their own root under the name. */
	const recipientCopy = () => cy.wmDownload('report.pdf', { as: recipient })

	const publicCopy = () =>
		cy.task('nc:get', { url: `/public.php/dav/files/${link.token}` }).then((response) => {
			expect(response.status, 'the public link did not serve the file').to.eq(200)
			return response.base64
		})

	// ---------------------------------------------------------------------
	// Off
	// ---------------------------------------------------------------------

	describe('with both switches off', () => {
		beforeEach(() => {
			cy.ncLogin()
			cy.wmSetPolicy({ trigger: 'on_demand' })
		})

		it('hands an unmarked shared file over exactly as stored', () => {
			recipientCopy().should('eq', original)
		})

		it('hands a public-link visitor the same stored bytes', () => {
			publicCopy().should('eq', original)
		})
	})

	// ---------------------------------------------------------------------
	// Internal shares
	// ---------------------------------------------------------------------

	describe('with internal shares switched on', () => {
		beforeEach(() => {
			cy.ncLogin()
			cy.wmSetPolicy({ trigger: 'on_demand', watermarkInternalShares: true })
		})

		it("watermarks the recipient's download of a file nobody marked", () => {
			recipientCopy().then((base64) => {
				expect(base64, 'the recipient got the stored bytes').to.not.eq(original)
				cy.task('probe:pdf', { base64 }).then((pdf) => {
					expect(pdf.watermarked, 'the recipient got an unwatermarked copy').to.be.true
					expect(pdf.pages).to.eq(2)
				})
			})
		})

		/**
		 * **The assertion that separates this feature from a mark.** A mark watermarks the
		 * file for everyone, its owner included. This switch is about the copy leaving, so
		 * the owner's own download must come back byte-identical to what they uploaded.
		 */
		it("leaves the owner's own download untouched", () => {
			cy.wmDownload(file).should('eq', original)
		})

		it('leaves the public link clean, because that is the other switch', () => {
			publicCopy().should('eq', original)
		})
	})

	// ---------------------------------------------------------------------
	// Public links
	// ---------------------------------------------------------------------

	describe('with public links switched on', () => {
		beforeEach(() => {
			cy.ncLogin()
			cy.wmSetPolicy({ trigger: 'on_demand', watermarkExternalShares: true })
		})

		/**
		 * The visitor has no account to name, so the copy carries the owner - the person
		 * accountable for having published it.
		 */
		it('watermarks a public-link fetch through the public DAV server', () => {
			publicCopy().then((base64) => {
				expect(base64, 'a public-link visitor got the clean original').to.not.eq(original)
				cy.task('probe:pdf', { base64 }).its('watermarked').should('be.true')
			})
		})

		it("watermarks the share page's own download link", () => {
			cy.task('nc:get', { url: `/s/${link.token}/download`, follow: true }).then((response) => {
				expect(response.status).to.eq(200)
				cy.task('probe:pdf', { base64: response.base64 }).its('watermarked').should('be.true')
			})
		})

		it('leaves the internal recipient clean, because that is the other switch', () => {
			recipientCopy().should('eq', original)
		})
	})

	// ---------------------------------------------------------------------
	// Reversibility
	// ---------------------------------------------------------------------

	/**
	 * Nothing was written on the way in, so there is nothing to undo - and this is the test
	 * that would fail first if the switches ever started placing marks. It runs after the two
	 * blocks above, which is the point: those fetches must have left no trace.
	 */
	it('returns every reader to clean bytes the moment the switches go off', () => {
		cy.ncLogin()
		cy.wmSetPolicy({
			trigger: 'on_demand',
			watermarkInternalShares: false,
			watermarkExternalShares: false,
		})

		recipientCopy().should('eq', original)
		publicCopy().should('eq', original)
		cy.wmDownload(file).should('eq', original)
	})

	// ---------------------------------------------------------------------
	// The archive path
	// ---------------------------------------------------------------------

	/**
	 * A folder share is downloaded as an archive by a different plugin, whose members core
	 * streams straight off storage - the gap `ZipInterceptorPlugin` exists for. It gates per
	 * member, so it has to ask the share question per member too.
	 */
	it('watermarks the members of a shared folder downloaded as an archive', () => {
		const sharedFolder = `${folder}/shared-out`

		cy.ncLogin()
		cy.wmFolder(sharedFolder)
		cy.task('fixture:pdf', { pages: 1, text: 'archive member' })
			.then((base64) => cy.wmUpload(`${sharedFolder}/member.pdf`, base64))

		cy.wmUnshareAll(`/${sharedFolder}`)
		cy.wmShare({ path: `/${sharedFolder}`, shareWith: recipientUid, permissions: 17 })
		cy.wmSetPolicy({ trigger: 'on_demand', watermarkInternalShares: true })

		cy.task('nc:get', {
			url: `/remote.php/dav/files/${recipientUid}/shared-out?accept=zip`,
			user: recipient.user,
			password: recipient.password,
			headers: { Accept: 'application/zip' },
		}).then((response) => {
			expect(response.status, 'the archive was not served').to.eq(200)
			cy.task('probe:zip', { base64: response.base64 }).then((members) => {
				const pdf = members.find((member) => member.name.endsWith('member.pdf'))
				expect(pdf, 'the member went missing from the archive').to.not.be.undefined
				cy.task('probe:pdf', { base64: pdf.base64 }).its('watermarked').should('be.true')
			})
		})

		cy.wmUnshareAll(`/${sharedFolder}`)
	})
})
