/**
 * On upload — and specifically, **promptly**.
 *
 * The listener alone cannot burn the watermark (the write still holds a lock on the
 * node, so `putContent()` from there throws `LockedException`), so it enqueues a job;
 * a job is only as prompt as cron, and on a default AJAX-cron instance that reads as
 * "on-upload is broken". `UploadWatermarkPlugin` closes that gap in-request.
 *
 * So every assertion here is made **immediately after the upload response**, with no
 * cron run in between. A suite that ran `occ background:job:worker` first would pass
 * against the bug this covers.
 *
 * The MOVE case is not a variation on the PUT case, it is the other half of the
 * feature: chunked uploads — every large file from the web UI and the desktop client —
 * assemble with a MOVE and never PUT their final path, so a PUT-only hook skips them
 * all silently.
 */

const folder = 'e2e-on-upload'

describe('On-upload watermarking', () => {
	before(() => {
		cy.ncLogin()
		cy.wmSetPolicy({ trigger: 'on_upload' })
		cy.wmFolder(folder)
	})

	after(() => {
		cy.wmSetPolicy({ trigger: 'on_demand' })
		cy.task('nc:delete', { user: Cypress.env('ncUser'), password: Cypress.env('ncPassword'), path: folder })
	})

	it('watermarks a plain PUT before the upload response returns', () => {
		const file = `${folder}/put.pdf`

		cy.task('fixture:pdf', { text: 'plain put' }).then((base64) => cy.wmUpload(file, base64))

		cy.wmDownload(file).then((base64) => {
			cy.task('probe:pdf', { base64 }).then((pdf) => {
				expect(pdf.watermarked, 'the upload was left for cron').to.be.true
			})
		})
	})

	it('watermarks a chunked upload, which lands as a MOVE and never a PUT', () => {
		const file = `${folder}/chunked.pdf`

		cy.task('fixture:pdf', { pages: 3, text: 'chunked' }).then((base64) => {
			cy.task('nc:chunkedUpload', {
				user: Cypress.env('ncUser'),
				password: Cypress.env('ncPassword'),
				path: file,
				base64,
				parts: 3,
			}).then((result) => {
				expect(result.stage, 'the chunked upload did not reach its MOVE').to.eq('move')
				expect(result.status, 'the assembling MOVE failed').to.be.oneOf([201, 204])
			})
		})

		cy.wmDownload(file).then((base64) => {
			cy.task('probe:pdf', { base64 }).then((pdf) => {
				expect(pdf.watermarked, 'a chunked upload was not watermarked').to.be.true
				expect(pdf.pages, 'the assembled file is not the one that was uploaded').to.eq(3)
			})
		})
	})

	it('marks the uploaded file as watermarked for the Files list', () => {
		cy.wmIsWatermarkedProp(`${folder}/put.pdf`).should('eq', '1')
	})

	it('leaves an unsupported type alone rather than failing the upload', () => {
		const file = `${folder}/notes.md`
		const text = Cypress.Buffer.from('# not a watermarkable type\n').toString('base64')

		cy.wmUpload(file, text, { contentType: 'text/markdown' })
		cy.wmDownload(file).should('eq', text)
		cy.wmIsWatermarkedProp(file).should('not.eq', '1')
	})
})
