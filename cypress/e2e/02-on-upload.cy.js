/**
 * On upload - and specifically, **promptly**.
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
 * feature: chunked uploads - every large file from the web UI and the desktop client -
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

	/**
	 * The second upload to a path, which used to land **clean and still badged**.
	 *
	 * The double-burn guard asks whether this *file id* is watermarked, and a file id
	 * survives having its content replaced - so the guard meant to stop a file being
	 * stamped twice suppressed the first stamp of entirely new bytes. Two uploads was all
	 * it took to store an unwatermarked file under the policy that exists to prevent that.
	 *
	 * Three things have to hold, and the last one is the one that bites: the overwrite is
	 * watermarked, it is watermarked **in-request** rather than left to cron, and undoing
	 * it gives back the file that was actually uploaded second. The preserved original is
	 * taken before the burn and never overwritten, so a stale copy of the *first* upload
	 * meant "remove watermark" restored a file the user had already replaced.
	 */
	it('watermarks an overwrite, and keeps the right original for it', () => {
		const file = `${folder}/overwrite.pdf`
		let second

		cy.task('fixture:pdf', { pages: 1, text: 'first upload' })
			.then((base64) => cy.wmUpload(file, base64))
		cy.wmDownload(file).then((base64) => {
			cy.task('probe:pdf', { base64 }).its('watermarked').should('be.true')
		})

		// A different document at the same path.
		cy.task('fixture:pdf', { pages: 3, text: 'second upload' }).then((base64) => {
			second = base64
			cy.wmUpload(file, base64)
		})

		cy.wmDownload(file).then((base64) => {
			expect(base64, 'the overwrite was served back as uploaded').to.not.eq(second)
			cy.task('probe:pdf', { base64 }).then((pdf) => {
				expect(pdf.watermarked, 'the second upload was stored clean').to.be.true
				expect(pdf.pages, 'the wrong document was watermarked').to.eq(3)
			})
		})

		cy.wmRemove(file)
		cy.wmDownload(file).then((base64) => {
			expect(base64, 'undoing the watermark restored the file the upload replaced')
				.to.eq(second)
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
