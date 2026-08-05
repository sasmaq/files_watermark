/**
 * What happens when an archive is too big to render.
 *
 * Members are rendered to temp files *before* any bytes are sent, which is what lets
 * a failed render answer with a real 403 instead of a truncated archive - and it is
 * why the work is capped at 200 members / 256 MiB.
 *
 * **Over the cap the archive is denied**, for everyone. It used to depend on the trigger:
 * `on_share` denied and `on_download` fell back to core's plain archive as a documented
 * best effort. That fallback is gone, because for a marked file it is a bulk leak - it
 * ships precisely the clean originals the marks were placed to prevent, silently, at the
 * moment the download is big enough that nobody checks.
 *
 * The under-cap control matters as much as the denial: "always deny" would break folder
 * downloads for everyone and still pass a test that only looks at the over-cap case.
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

		// The cap counts *marked* members, so marking them is what makes this folder
		// over-cap in the sense the plugin measures.
		cy.wmSetPolicy({ trigger: 'on_demand' })
		Cypress._.range(members).forEach((index) => {
			cy.wmApply(`${folder}/member-${String(index).padStart(3, '0')}.pdf`, { failOnStatusCode: false })
		})
	})

	after(() => {
		cy.wmSetPolicy({ trigger: 'on_demand' })
		cy.wmUnshareAll(`/${folder}`)
		cy.task('nc:delete', { user: Cypress.env('ncUser'), password: Cypress.env('ncPassword'), path: folder })
	})

	it('denies an over-cap archive rather than serving originals', () => {
		cy.task('nc:get', {
			url: `/remote.php/dav/files/${recipientUid}/${folder}?accept=zip`,
			user: recipient.user,
			password: recipient.password,
			headers: zipHeaders,
		}).then((response) => {
			expect(response.status, 'an over-cap archive was served').to.eq(403)
		})
	})

	/**
	 * The owner is denied too. There is no reader the caps exempt, for the same reason
	 * there is no reader the watermark exempts.
	 */
	it("denies the owner's over-cap archive as well", () => {
		cy.task('nc:get', {
			url: `/remote.php/dav/files/${Cypress.env('ncUser')}/${folder}?accept=zip`,
			user: Cypress.env('ncUser'),
			password: Cypress.env('ncPassword'),
			headers: zipHeaders,
		}).its('status').should('eq', 403)
	})

	/**
	 * The caps are `occ` settings, and this is the only test in either suite that proves
	 * the key an admin types is the key the code reads. The unit tests stub `IAppConfig`,
	 * so a rename on either side would leave them green and the setting inert.
	 *
	 * It runs against a folder of **three** members - far under the default of 200 - so
	 * a denial here can only come from the configured value.
	 */
	it('honours a member cap lowered with occ', () => {
		const small = `${folder}-configured`

		cy.wmFolder(small)
		cy.task('fixture:pdf', { text: 'configured cap' }).then((base64) => {
			cy.task('nc:putMany', {
				user: Cypress.env('ncUser'),
				password: Cypress.env('ncPassword'),
				paths: [1, 2, 3].map((index) => `${small}/member-${index}.pdf`),
				base64,
			}).its('failures').should('be.empty')
		})
		cy.wmUnshareAll(`/${small}`)
		cy.wmShare({ path: `/${small}`, shareWith: recipientUid })
		Cypress._.range(1, 4).forEach((index) => cy.wmApply(`${small}/member-${index}.pdf`))

		const fetchArchive = () =>
			cy.task('nc:get', {
				url: `/remote.php/dav/files/${recipientUid}/${small}?accept=zip`,
				user: recipient.user,
				password: recipient.password,
				headers: zipHeaders,
			})

		// The control first: three members are served fine at the shipped default.
		fetchArchive().its('status').should('eq', 200)

		cy.task('nc:occ', {
			args: ['config:app:set', 'files_watermark', 'archive_max_members', '--value', '2'],
		}).its('code').should('eq', 0)

		fetchArchive().its('status').should('eq', 403)

		cy.task('nc:occ', {
			args: ['config:app:delete', 'files_watermark', 'archive_max_members'],
		}).its('code').should('eq', 0)

		// And back: the setting is what changed, not the folder.
		fetchArchive().its('status').should('eq', 200)

		cy.wmUnshareAll(`/${small}`)
		cy.task('nc:delete', {
			user: Cypress.env('ncUser'),
			password: Cypress.env('ncPassword'),
			path: small,
		})
	})

	/**
	 * An archive with no marked member is core's to serve, whatever its size.
	 *
	 * The caps bound the *rendering*, and an archive with nothing to render costs nothing -
	 * so a folder past the member cap that carries no marks must still download. Without
	 * this the denial above would be indistinguishable from "large folders are broken".
	 */
	it('leaves an unmarked over-cap folder to core', () => {
		const clean = `${folder}-unmarked`

		cy.wmFolder(clean)
		cy.task('fixture:pdf', { text: 'unmarked' }).then((base64) => {
			cy.task('nc:putMany', {
				user: Cypress.env('ncUser'),
				password: Cypress.env('ncPassword'),
				paths: Cypress._.range(members)
					.map((index) => `${clean}/member-${String(index).padStart(3, '0')}.pdf`),
				base64,
			}, { timeout: 180000 }).its('failures').should('be.empty')
		})

		cy.task('nc:get', {
			url: `/remote.php/dav/files/${Cypress.env('ncUser')}/${clean}?accept=zip`,
			user: Cypress.env('ncUser'),
			password: Cypress.env('ncPassword'),
			headers: zipHeaders,
		}).then((response) => {
			expect(response.status, 'an unmarked folder was denied for being large').to.eq(200)

			cy.task('probe:zip', { base64: response.base64 }).then((entries) => {
				expect(entries.length, 'core did not stream the whole folder').to.eq(members)

				// Spot-checked rather than probed member by member: 201 inflate-and-scan
				// passes cost more than they prove, and one clean member is enough to show
				// the archive was not rendered.
				cy.task('probe:pdf', { base64: entries[0].base64 }).then((pdf) => {
					expect(pdf.watermarked, 'an unmarked archive was rendered after all').to.be.false
				})
			})
		})

		cy.task('nc:delete', {
			user: Cypress.env('ncUser'),
			password: Cypress.env('ncPassword'),
			path: clean,
		})
	})
})
