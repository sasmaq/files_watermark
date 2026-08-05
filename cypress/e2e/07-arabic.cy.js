/**
 * Arabic, read off the delivered file.
 *
 * This is the one part of the Arabic work worth automating rather than eyeballing,
 * and the reason is that the failure is invisible to every other kind of assertion:
 * Arabic drawn as disconnected left-to-right letters is still a valid PDF with a
 * valid overlay, of the right size, with the right page count. Only the glyph codes
 * say whether the text was shaped.
 *
 * The app shapes once, itself, through `tc-lib-unicode`, and writes the embedded face
 * with two-byte code units - so the operand of the text-showing operator *is* the
 * shaped string. Two facts follow, and both are asserted:
 *
 *  - every Arabic code unit drawn is in **Arabic Presentation Forms-B** (U+FE70–FEFF).
 *    An unshaped watermark would carry U+0600-block code points instead;
 *  - `الاختبار` - eight letters - comes out as **seven** glyphs, because lam+alef
 *    ligates. A renderer that drew the letters one by one would emit eight.
 */

const folder = 'e2e-arabic'
const CONFIDENTIAL = 'سري'
const PROBE = 'الاختبار'

const isPresentationForm = (code) => code >= 0xfe70 && code <= 0xfeff

describe('Arabic watermarks', () => {
	before(() => {
		cy.ncLogin()
		cy.wmFolder(folder)
	})

	after(() => {
		cy.wmSetPolicy({ trigger: 'on_demand', textTemplate: '{displayname} - {date}' })
		cy.task('nc:delete', { user: Cypress.env('ncUser'), password: Cypress.env('ncPassword'), path: folder })
	})

	it('shapes and reorders Arabic in a delivered PDF', () => {
		const file = `${folder}/arabic.pdf`

		cy.wmSetPolicy({ trigger: 'on_demand', textTemplate: PROBE })
		cy.task('fixture:pdf', { text: 'arabic template' })
			.then((base64) => cy.wmUpload(file, base64))
		// The policy decides which files are marked; the render happens on this fetch.
		cy.wmApply(file)

		cy.wmDownload(file).then((base64) => {
			cy.task('probe:pdf', { base64 }).then((pdf) => {
				expect(pdf.watermarked, 'no watermark was drawn at all').to.be.true
				expect(pdf.unshapedArabicCodepoints,
					'raw Arabic code points were drawn, so nothing shaped the text')
					.to.eq(0)
				expect(pdf.shapedArabicGlyphs, 'no presentation forms in the output')
					.to.be.greaterThan(0)

				const shaped = pdf.textRuns.filter((run) => run.every(isPresentationForm))
				expect(shaped, 'no run consists purely of shaped Arabic').to.not.be.empty
				expect(shaped[0].length, `${PROBE} did not ligate lam-alef`).to.eq(7)
			})
		})
	})

	it('keeps a mixed Arabic and token template renderable', () => {
		const file = `${folder}/mixed.pdf`

		cy.wmSetPolicy({ trigger: 'on_demand', textTemplate: `${CONFIDENTIAL} - {date}` })
		cy.task('fixture:pdf', { text: 'mixed template' })
			.then((base64) => cy.wmUpload(file, base64))
		// The policy decides which files are marked; the render happens on this fetch.
		cy.wmApply(file)

		cy.wmDownload(file).then((base64) => {
			cy.task('probe:pdf', { base64 }).then((pdf) => {
				expect(pdf.watermarked).to.be.true
				expect(pdf.shapedArabicGlyphs, 'the Arabic half of the template vanished')
					.to.be.greaterThan(0)
				expect(pdf.unshapedArabicCodepoints).to.eq(0)
			})
		})
	})

	/**
	 * The open bidi bug, recorded rather than asserted.
	 *
	 * `سري - John Doe` is drawn `Doe John - سري`: the Latin word order is reversed
	 * inside an RTL run, which is a UAX #9 rule N1 violation in `tc-lib-unicode`. It
	 * names the wrong person, which is the one thing a watermark exists not to do.
	 *
	 * Asserting the *current* output would cement the bug into the suite, so this
	 * stays pending until the shaper is fixed - at which point deleting `.skip` is
	 * the whole change.
	 */
	it.skip('keeps Latin words in reading order inside an RTL watermark', () => {
		const file = `${folder}/bidi.pdf`

		cy.wmSetPolicy({ trigger: 'on_demand', textTemplate: `${CONFIDENTIAL} - John Doe` })
		cy.task('fixture:pdf', { text: 'bidi' }).then((base64) => cy.wmUpload(file, base64))
		// The policy decides which files are marked; the render happens on this fetch.
		cy.wmApply(file)

		cy.wmDownload(file).then((base64) => {
			cy.task('probe:pdf', { base64 }).then((pdf) => {
				const latin = pdf.textRuns
					.flat()
					.filter((code) => code >= 0x41 && code <= 0x7a)
					.map((code) => String.fromCharCode(code))
					.join('')

				expect(latin).to.contain('John')
			})
		})
	})

	it('draws an Arabic watermark on an image too', () => {
		const file = `${folder}/arabic.png`

		cy.wmSetPolicy({ trigger: 'on_demand', textTemplate: CONFIDENTIAL })
		cy.task('fixture:png', { width: 600, height: 400 })
			.then((base64) => cy.wmUpload(file, base64, { contentType: 'image/png' }))
		// The policy decides which files are marked; the render happens on this fetch.
		cy.wmApply(file)

		cy.wmDownload(file).then((base64) => {
			cy.task('probe:image', { base64 }).then((image) => {
				expect(image.width, 'the renderer resized the image').to.eq(600)
				expect(image.height).to.eq(400)
				// Pixels cannot say whether the glyphs joined - that is the PDF's job
				// above. What they can say is that the image path did not throw, skip,
				// or draw an empty string when handed Arabic.
				expect(image.inkRatio, 'nothing was drawn on the image').to.be.greaterThan(0)
			})
		})
	})
})
