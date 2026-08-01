/**
 * Images, judged by their pixels.
 *
 * There is no marker in an image the way an embedded subset font marks a watermarked
 * PDF, so "the file changed" is all a byte comparison can say — and it says it just as
 * loudly when the renderer re-encodes the file and draws nothing. The fixture is
 * therefore a flat white field, `inkRatio` is the fraction of pixels that are no
 * longer that colour, and a clean control upload has to measure zero.
 *
 * The JPEG case is here for the *other* reason: it is the format the delivery path
 * meets most often on a real instance, and the one where a mis-detected type shows up
 * as a corrupt file rather than as a missing watermark.
 */

const folder = 'e2e-images'
const png = `${folder}/canvas.png`
const jpeg = `${folder}/photo.jpg`

describe('Image watermarking', () => {
	let original

	before(() => {
		cy.ncLogin()
		cy.wmFolder(folder)

		cy.task('fixture:png', { width: 800, height: 500 }).then((base64) => {
			original = base64
			cy.wmUpload(png, base64, { contentType: 'image/png' })
		})

		// A real photograph rather than a generated field: the skeleton ships one, and
		// JPEG is not worth synthesising badly.
		cy.task('nc:copy', {
			user: Cypress.env('ncUser'),
			password: Cypress.env('ncPassword'),
			from: 'Photos/Birdie.jpg',
			to: jpeg,
		}).its('status').should('be.oneOf', [201, 204])
	})

	after(() => {
		cy.wmSetPolicy({ trigger: 'on_demand' })
		cy.task('nc:delete', { user: Cypress.env('ncUser'), password: Cypress.env('ncPassword'), path: folder })
	})

	it('starts from a field with no ink on it', () => {
		cy.wmDownload(png).then((base64) => {
			cy.task('probe:image', { base64 }).then((image) => {
				expect(image.format).to.eq('png')
				expect(image.width).to.eq(800)
				expect(image.height).to.eq(500)
				expect(image.inkRatio, 'the fixture is not blank').to.eq(0)
			})
		})
	})

	it('draws a tiled watermark on demand without resizing the image', () => {
		cy.wmSetPolicy({ trigger: 'on_demand' })
		cy.wmApply(png).its('body.status').should('eq', 'watermarked')

		cy.wmDownload(png).then((base64) => {
			cy.task('probe:image', { base64 }).then((image) => {
				expect(image.width, 'the image was resized').to.eq(800)
				expect(image.height, 'the image was resized').to.eq(500)
				expect(image.inkRatio, 'nothing was drawn').to.be.greaterThan(0.001)
				// A tiled watermark covers the canvas rather than sitting in one
				// corner; a single mis-placed tile would land well under this.
				expect(image.inkRatio, 'the watermark is not tiled across the canvas')
					.to.be.greaterThan(0.01)
			})
		})
	})

	it('restores the blank original byte for byte', () => {
		cy.wmRemove(png).its('status').should('eq', 200)
		cy.wmDownload(png).should('eq', original)
	})

	it('delivers a watermarked JPEG that is still a JPEG of the same size', () => {
		let clean

		cy.wmSetPolicy({ trigger: 'on_demand' })
		cy.wmDownload(jpeg).then((base64) => {
			clean = base64
			return cy.task('probe:image', { base64 })
		}).then((before) => {
			cy.wmSetPolicy({ trigger: 'on_download' })

			cy.wmDownload(jpeg).then((base64) => {
				expect(base64, 'the delivered JPEG is the stored one').to.not.eq(clean)

				cy.task('probe:image', { base64 }).then((after) => {
					expect(after.format, 'the delivered file is no longer a JPEG').to.eq('jpeg')
					expect(after.width, 'the photo was resized').to.eq(before.width)
					expect(after.height, 'the photo was resized').to.eq(before.height)
				})
			})
		})
	})

	it('leaves the stored JPEG untouched, since on_download renders per fetch', () => {
		cy.wmSetPolicy({ trigger: 'on_demand' })
		cy.wmDownload(jpeg).then((base64) => {
			cy.task('probe:image', { base64 }).its('format').should('eq', 'jpeg')
		})
		cy.wmIsWatermarkedProp(jpeg).should('not.eq', '1')
	})
})
