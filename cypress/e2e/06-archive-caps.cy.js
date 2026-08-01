/**
 * What happens when an archive is too big to render.
 *
 * Members are rendered to temp files *before* any bytes are sent, which is what lets
 * a failed render answer with a real 403 instead of a truncated archive — and it is
 * why the work is capped at 200 members / 256 MiB. Over the cap the two delivery
 * triggers deliberately part company:
 *
 *  - `on_share` **denies**, because serving the clean original is the one thing that
 *    mode exists to prevent;
 *  - `on_download` **degrades** to core's plain archive, matching its documented
 *    best-effort contract.
 *
 * Both halves are asserted, because either one alone is satisfied by a bug: "always
 * deny" breaks folder downloads for everyone, and "always degrade" is the leak.
 */

const folder = 'e2e-archive-caps'
const members = 201 // one past MAX_MEMBERS
const recipientUid = 'e2e-caps-recipient'
const zipHeaders = { Accept: 'application/zip' }

describe('Archives past the rendering cap', () => {
	let recipient

	before(() => {
		cy.ncLogin()
		cy.wmFolder(folder)
		cy.wmUser(recipientUid).then((credentials) => {
			recipient = credentials
		})

		// One fixture, uploaded many times: the cap is on the member *count* here, so
		// the content only has to be watermarkable.
		cy.task('fixture:pdf', { text: 'capped' }).then((base64) => {
			cy.task('nc:putMany', {
				user: Cypress.env('ncUser'),
				password: Cypress.env('ncPassword'),
				paths: Cypress._.range(members)
					.map((index) => `${folder}/member-${String(index).padStart(3, '0')}.pdf`),
				base64,
			}, { timeout: 180000 }).then((result) => {
				expect(result.failures, 'some members failed to upload').to.be.empty
				expect(result.uploaded).to.eq(members)
			})
		})

		cy.wmUnshareAll(`/${folder}`)
		cy.wmShare({ path: `/${folder}`, shareWith: recipientUid })
	})

	after(() => {
		cy.wmSetPolicy({ trigger: 'on_demand' })
		cy.wmUnshareAll(`/${folder}`)
		cy.task('nc:delete', { user: Cypress.env('ncUser'), password: Cypress.env('ncPassword'), path: folder })
	})

	it('denies an over-cap archive under on_share rather than serving originals', () => {
		cy.wmSetPolicy({ trigger: 'on_share' })

		cy.task('nc:get', {
			url: `/remote.php/dav/files/${recipientUid}/${folder}?accept=zip`,
			user: recipient.user,
			password: recipient.password,
			headers: zipHeaders,
		}).then((response) => {
			expect(response.status, 'an over-cap on_share archive was served').to.eq(403)
		})
	})

	it('degrades to a plain archive under on_download', () => {
		cy.wmSetPolicy({ trigger: 'on_download' })

		cy.task('nc:get', {
			url: `/remote.php/dav/files/${Cypress.env('ncUser')}/${folder}?accept=zip`,
			user: Cypress.env('ncUser'),
			password: Cypress.env('ncPassword'),
			headers: zipHeaders,
		}).then((response) => {
			expect(response.status, 'the download was denied rather than degraded').to.eq(200)

			cy.task('probe:zip', { base64: response.base64 }).then((entries) => {
				expect(entries.length, 'core did not stream the whole folder').to.eq(members)

				// Spot-checked rather than probed member by member: 201 inflate-and-scan
				// passes cost more than they prove, and one clean member is enough to
				// show the archive was not rendered.
				cy.task('probe:pdf', { base64: entries[0].base64 }).then((pdf) => {
					expect(pdf.watermarked, 'the degraded archive was rendered after all').to.be.false
				})
			})
		})
	})
})
