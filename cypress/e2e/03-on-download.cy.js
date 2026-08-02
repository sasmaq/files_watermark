/**
 * On download: the first of the two delivery triggers, where the watermark is
 * rendered per fetch and the stored file is never touched.
 *
 * "The original is untouched" is asserted the only way that means anything: by
 * putting the policy back to an in-place trigger and fetching the same file again,
 * which must return the uploaded bytes exactly. Reading a size or an mtime would pass
 * against a file that had been rewritten with the same length.
 *
 * `on_download` applies to *everyone*, the owner included — that is what separates it
 * from `on_share`, and it is the cell of the matrix that a suite run only as the owner
 * would otherwise never distinguish.
 */

const folder = 'e2e-on-download'
const file = `${folder}/report.pdf`

describe('On-download watermarking', () => {
	let original

	before(() => {
		cy.ncLogin()
		cy.wmSetPolicy({ trigger: 'on_download' })
		cy.wmFolder(folder)
		cy.task('fixture:pdf', { pages: 2, text: 'on download' }).then((base64) => {
			original = base64
			cy.wmUpload(file, base64)
		})
	})

	after(() => {
		cy.wmSetPolicy({ trigger: 'on_demand' })
		cy.task('nc:delete', { user: Cypress.env('ncUser'), password: Cypress.env('ncPassword'), path: folder })
	})

	it("watermarks the owner's own fetch", () => {
		cy.wmDownload(file).then((base64) => {
			expect(base64, 'the owner got the stored bytes back unchanged').to.not.eq(original)
			cy.task('probe:pdf', { base64 }).then((pdf) => {
				expect(pdf.watermarked, 'the delivered copy carries no watermark').to.be.true
				expect(pdf.pages, 'the delivered copy lost a page').to.eq(2)
			})
		})
	})

	it('renders per fetch rather than burning the file', () => {
		// Two fetches, both watermarked: a burn would have been caught by the
		// already-watermarked guard and the second copy would come back clean.
		cy.wmDownload(file).then((base64) => {
			cy.task('probe:pdf', { base64 }).its('watermarked').should('be.true')
		})

		cy.wmIsWatermarkedProp(file)
			.should('not.eq', '1')
	})

	it('leaves the stored bytes byte-identical', () => {
		cy.wmSetPolicy({ trigger: 'on_demand' })
		cy.wmDownload(file).should('eq', original)
	})

	it('streams a watermarked copy from /api/v1/download without touching the file', () => {
		// The endpoint watermarks on request whatever the policy's trigger is; the
		// policy is on_demand here, so a watermarked copy back from it *and* clean
		// stored bytes afterwards is the whole contract in one test.
		cy.wmApiDownload(file).then((base64) => {
			cy.task('probe:pdf', { base64 }).its('watermarked').should('be.true')
		})

		cy.wmDownload(file).should('eq', original)
	})

	/**
	 * One download, one row — the regression that reported itself as "the log adds two
	 * entries per download".
	 *
	 * The Files app's download sends **HEAD then GET**, and Sabre serves a HEAD by
	 * cloning the request as a GET and re-dispatching it, so both arrived at the
	 * interceptor as downloads: the whole watermarked file was rendered twice and
	 * recorded twice for one click. The HEAD is asserted on its own as well, because the
	 * expensive half is the render, and a fix that only skipped the *logging* would leave
	 * that in place and pass a "one row" test.
	 */
	it('renders and records once per download, not once per HEAD as well', () => {
		cy.wmSetPolicy({ trigger: 'on_download', logDelivery: true })

		const head = () => cy.task('nc:get', {
			url: `/remote.php/dav/files/${Cypress.env('ncUser')}/${file}`,
			method: 'HEAD',
			user: Cypress.env('ncUser'),
			password: Cypress.env('ncPassword'),
		}).its('status').should('eq', 200)

		const rowsAddedBy = (action) =>
			cy.wmApi('GET', '/api/v1/log?limit=500').then((before) => {
				const seen = new Set(before.body.map((entry) => entry.id))
				action()
				return cy.wmApi('GET', '/api/v1/log?limit=500')
					.then((after) => after.body.filter((entry) => !seen.has(entry.id)))
			})

		rowsAddedBy(head).should('be.empty')
		rowsAddedBy(() => {
			head()
			cy.wmDownload(file)
		}).should('have.length', 1)

		cy.wmSetPolicy({ trigger: 'on_download', logDelivery: false })
	})

	it('answers a folder path with an error rather than an archive', () => {
		cy.wmToken().then((token) => {
			cy.request({
				url: `/apps/files_watermark/api/v1/download?path=${encodeURIComponent(folder)}`,
				headers: { requesttoken: token },
				failOnStatusCode: false,
			}).then((response) => {
				expect(response.status).to.eq(400)
				expect(response.body.error).to.contain('not a file')
			})
		})
	})
})
