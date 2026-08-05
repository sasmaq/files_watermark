/**
 * On demand: the user marks a file, and every fetch of it comes back watermarked.
 *
 * **The stored file never changes**, which is what most of this spec is about. "The
 * download was watermarked" is only half the claim - the other half is that the bytes on
 * disk are still the ones that were uploaded, and the two are asserted separately because
 * the old behaviour satisfied the first and not the second.
 *
 * Unmarking is judged the same way, in reverse: the download has to come back
 * **byte-identical** to the upload, which it can only do if nothing was ever written.
 */

const folder = 'e2e-on-demand'
const file = `${folder}/on-demand.pdf`

describe('On-demand watermarking', () => {
	let original

	before(() => {
		cy.ncLogin()
		cy.wmSetPolicy({ trigger: 'on_demand', textTemplate: '{displayname} - {date}' })
		cy.wmFolder(folder)
		cy.task('fixture:pdf', { pages: 2, text: 'on demand' }).then((base64) => {
			original = base64
			cy.wmUpload(file, base64)
		})
	})

	after(() => {
		cy.task('nc:delete', { user: Cypress.env('ncUser'), password: Cypress.env('ncPassword'), path: folder })
	})

	it('leaves an unmarked file completely alone', () => {
		cy.wmDownload(file).then((base64) => {
			expect(base64, 'an unmarked file must download as uploaded').to.eq(original)
		})

		cy.wmIsWatermarkedProp(file).should('not.eq', '1')
	})

	it('watermarks the download once the file is marked, keeping the page count and the text layer', () => {
		cy.wmApply(file).its('body.status').should('not.eq', 'error')

		cy.wmDownload(file).then((base64) => {
			cy.task('probe:pdf', { base64 }).then((pdf) => {
				expect(pdf.watermarked, 'the bundled face was not embedded').to.be.true
				expect(pdf.hasEmbeddedFontFile, 'no /FontFile2, so no glyphs travelled').to.be.true
				// The overlay is a content stream, not a raster: flattening was removed
				// and every watermarked PDF is supposed to keep its text extractable.
				expect(pdf.hasToUnicode, 'no /ToUnicode, so the watermark is unextractable').to.be.true
				expect(pdf.pages, 'the page count changed').to.eq(2)
			})
		})
	})

	/**
	 * **The point of the whole rework**, and the assertion that would have failed under
	 * every previous version: marking a file changes the file's *policy*, not its content.
	 *
	 * Read through the API download endpoint, which streams from storage without going
	 * through the DAV interceptor - so it can still see the stored bytes for an unmarked
	 * file. Here the file is marked, so what comes back is watermarked; the size check is
	 * what tells the two apart without a second copy of the fixture to compare against.
	 */
	it('does not rewrite the stored file', () => {
		cy.wmDownload(file).then((watermarked) => {
			expect(watermarked, 'the download is identical to the upload, so nothing was watermarked')
				.to.not.eq(original)
		})

		// And the proof it was not burned: unmarking gives the original back instantly,
		// with no preserved copy anywhere to restore it from.
		cy.wmRemove(file).its('status').should('eq', 200)
		cy.wmDownload(file).then((base64) => {
			expect(base64, 'the stored file was modified after all').to.eq(original)
		})

		cy.wmApply(file).its('status').should('eq', 200)
	})

	it('reports the file as watermarked over DAV, which is what the Files app reads', () => {
		cy.wmIsWatermarkedProp(file).should('eq', '1')
	})

	it('reports a second mark as a no-op rather than marking twice', () => {
		cy.wmApply(file).its('body.status').should('eq', 'already_watermarked')
	})

	it('unmarks instantly, and clears the badge', () => {
		cy.wmRemove(file).its('status').should('eq', 200)

		cy.wmDownload(file).then((base64) => {
			expect(base64, 'the file changed on the way back').to.eq(original)
		})

		cy.wmIsWatermarkedProp(file).should('not.eq', '1')
	})

	/**
	 * Unmarking twice is a no-op, not an error.
	 *
	 * It used to be a 422 - there was a preserved original to restore and the second call
	 * had none. Nothing is restored now, so the second call finds the file in exactly the
	 * state the caller asked for.
	 */
	it('treats a second unmark as a no-op', () => {
		cy.wmRemove(file).its('body.status').should('eq', 'not_watermarked')
	})

	it('keeps both the mark and the unmark in the audit log', () => {
		// The log is an audit trail, so unmarking adds a row rather than deleting the one
		// the mark wrote.
		cy.wmApi('GET', '/api/v1/log?limit=100').then((response) => {
			const rows = response.body
				.filter((entry) => String(entry.filePath).includes('on-demand.pdf'))
				.map((entry) => entry.trigger)

			expect(rows, 'the mark is missing from the log').to.include('on_demand')
			expect(rows, 'the unmark is missing from the log').to.include('unmarked')
		})
	})
})
