/**
 * On demand: the trigger that burns the watermark into the stored bytes, and the
 * only one with an undo.
 *
 * The round trip is the point. "Apply produced a different file" is not evidence of
 * a watermark, and "remove produced a file" is not evidence the original came back -
 * so apply is judged by the embedded face appearing in the delivered bytes, and
 * remove by the restored file being **byte-identical** to what was uploaded.
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

	it('leaves the stored file alone until the action is invoked', () => {
		cy.wmDownload(file).then((base64) => {
			cy.task('probe:pdf', { base64 }).then((pdf) => {
				expect(pdf.isPdf, 'the fixture uploaded intact').to.be.true
				expect(pdf.watermarked, 'nothing was stamped before the apply').to.be.false
			})
		})

		cy.wmIsWatermarkedProp(file).should('not.eq', '1')
	})

	it('stamps the stored bytes, keeping the page count and the text layer', () => {
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

	it('reports the file as watermarked over DAV, which is what the Files app reads', () => {
		cy.wmIsWatermarkedProp(file).should('eq', '1')
	})

	it('skips a second apply instead of stamping twice', () => {
		cy.wmApply(file).its('body.status').should('eq', 'already_watermarked')
	})

	it('restores a byte-identical original, and clears the badge', () => {
		cy.wmRemove(file).its('status').should('eq', 200)

		cy.wmDownload(file).then((base64) => {
			expect(base64, 'the restored file is not the original').to.eq(original)
		})

		cy.wmIsWatermarkedProp(file).should('not.eq', '1')
	})

	it('has no original left to restore a second time', () => {
		cy.wmRemove(file, { failOnStatusCode: false }).its('status').should('eq', 422)
	})

	it('keeps both the apply and the undo in the audit log', () => {
		// The log is an audit trail, so removing a watermark adds a row rather than
		// deleting the one the apply wrote.
		cy.wmApi('GET', '/api/v1/log?limit=100').then((response) => {
			const rows = response.body
				.filter((entry) => String(entry.filePath).includes('on-demand.pdf'))
				.map((entry) => entry.trigger)

			expect(rows, 'the apply is missing from the log').to.include('on_demand')
			expect(rows, 'the undo is missing from the log').to.include('removed')
		})
	})
})
