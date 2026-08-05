/**
 * On upload: every supported file is marked as it is written, and every fetch of it is
 * watermarked from then on.
 *
 * The promptness this spec used to be built around is gone with the reason for it. The
 * watermark was burned into the file, the listener could not write from inside the write
 * event (the node is still locked, so `putContent()` threw), and a background job is only
 * as prompt as cron - so a DAV plugin existed to close the gap in-request. Marking is one
 * insert and takes no lock, so it happens in the event and there is no gap to close.
 *
 * What has *not* changed is that the assertions are made immediately after the upload
 * response, with no cron run in between. If marking ever regresses into something deferred,
 * this is what notices.
 *
 * The MOVE case is still here and is still not a variation on the PUT case: chunked uploads
 * - every large file from the web UI and the desktop client - assemble with a MOVE and never
 * PUT their final path. `NodeWrittenEvent` fires for both, which is precisely why the DAV
 * plugin that had to hook each method separately is no longer needed.
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

	it('marks a plain PUT before the upload response returns', () => {
		const file = `${folder}/put.pdf`

		cy.task('fixture:pdf', { text: 'plain put' }).then((base64) => cy.wmUpload(file, base64))

		cy.wmIsWatermarkedProp(file).should('eq', '1')
		cy.wmDownload(file).then((base64) => {
			cy.task('probe:pdf', { base64 }).then((pdf) => {
				expect(pdf.watermarked, 'the upload was not marked').to.be.true
			})
		})
	})

	/**
	 * **An overwrite keeps the mark, and the download follows the new bytes.**
	 *
	 * This is the case that broke under the burn, and it broke in the worst available
	 * direction: the double-burn guard asked whether this *file id* was watermarked, a file
	 * id survives having its content replaced, and so the guard that existed to stop a
	 * second stamp suppressed the *first* stamp of entirely new bytes. Two uploads was all
	 * it took to store an unwatermarked file under the policy that exists to prevent that.
	 *
	 * There is nothing to get wrong now. The mark describes the file id; the watermark is
	 * drawn from whatever bytes are there at fetch time. The assertion is that the *second*
	 * document is what comes back watermarked - three pages, not one.
	 */
	it('keeps the mark across an overwrite, and watermarks the new content', () => {
		const file = `${folder}/overwrite.pdf`
		let second

		cy.task('fixture:pdf', { pages: 1, text: 'first upload' })
			.then((base64) => cy.wmUpload(file, base64))
		cy.wmIsWatermarkedProp(file).should('eq', '1')

		// A different document at the same path.
		cy.task('fixture:pdf', { pages: 3, text: 'second upload' }).then((base64) => {
			second = base64
			cy.wmUpload(file, base64)
		})

		cy.wmIsWatermarkedProp(file).should('eq', '1')
		cy.wmDownload(file).then((base64) => {
			expect(base64, 'the overwrite was served back untouched').to.not.eq(second)
			cy.task('probe:pdf', { base64 }).then((pdf) => {
				expect(pdf.watermarked, 'the overwrite lost its watermark').to.be.true
				expect(pdf.pages, 'the wrong document was watermarked').to.eq(3)
			})
		})

		// And the stored file is still the second upload, untouched.
		cy.wmRemove(file)
		cy.wmDownload(file).then((base64) => {
			expect(base64, 'the stored file is not the one that was uploaded').to.eq(second)
		})
	})

	it('marks a chunked upload, which lands as a MOVE and never a PUT', () => {
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

		cy.wmIsWatermarkedProp(file).should('eq', '1')
		cy.wmDownload(file).then((base64) => {
			cy.task('probe:pdf', { base64 }).then((pdf) => {
				expect(pdf.watermarked, 'a chunked upload was not marked').to.be.true
				expect(pdf.pages, 'the assembled file is not the one that was uploaded').to.eq(3)
			})
		})
	})

	it('leaves an unsupported type alone rather than failing the upload', () => {
		const file = `${folder}/notes.md`
		const text = Cypress.Buffer.from('# not a watermarkable type\n').toString('base64')

		cy.wmUpload(file, text, { contentType: 'text/markdown' })
		cy.wmDownload(file).should('eq', text)
		cy.wmIsWatermarkedProp(file).should('not.eq', '1')
	})
})
