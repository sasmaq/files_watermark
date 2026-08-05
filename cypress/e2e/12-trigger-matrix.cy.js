/**
 * The trigger × access matrix: both triggers, fetched every way.
 *
 * Two triggers against six access paths - owner direct, owner ZIP, recipient direct,
 * recipient ZIP, public-link direct, public-link ZIP - because the app's behaviour is a
 * function of both together, and every delivery bug found so far has been one cell of this
 * table disagreeing with its neighbours:
 *
 * - the archive gate keyed off the container, so a received single-file share served the
 *   clean original while the same file fetched directly came back watermarked;
 * - public links are a second Sabre server, so a plugin registered once covered the
 *   authenticated cells only;
 * - public links are served off the *owner's* storage, so an owner/recipient test based
 *   on the storage backend waved them through.
 *
 * None of those is visible from a single cell.
 *
 * **The table has become uniform, and that is the finding rather than a simplification.**
 * It used to have rows that disagreed on purpose: `on_share` watermarked for everyone
 * except the owner, `on_download` for everyone including them, and the two in-place
 * triggers watermarked through every path for an entirely different reason - the bytes on
 * disk already carried it. All six cells now say the same thing under both triggers,
 * because the trigger decides *which files are marked* and nothing else. A cell that
 * disagrees with its neighbours is a bug with nowhere left to hide.
 *
 * Every cell is asserted twice over: the delivered file is watermarked, **and** the stored
 * file is unchanged at the end. The second half is what the old in-place rows had to assert
 * by byte-identity, and it is still the assertion that catches the most dangerous failure -
 * a render that writes its result back would pass every "is it watermarked" check and
 * quietly destroy the original.
 *
 * Deeper per-mode assertions live with their own specs - previews and the share page's
 * download link in `03-per-reader`, archive membership and audit granularity in
 * `05-archives`. This file answers one question only: is any cell of the matrix wrong.
 */

const folder = 'e2e-matrix'
const recipientUid = 'e2e-matrix-recipient'
const zipHeaders = { Accept: 'application/zip' }

/** One file per trigger, so neither row can change what the other measures. */
const files = {
	on_demand: 'on-demand.pdf',
	on_upload: 'on-upload.pdf',
}

const admin = () => ({
	user: Cypress.env('ncUser'),
	password: Cypress.env('ncPassword'),
})

const fetchBytes = (options) =>
	cy.task('nc:get', options).then((response) => {
		expect(response.status, `${options.url} did not serve the file`).to.eq(200)
		return response.base64
	})

/** The named member's own bytes, out of an archive. */
const memberOf = (name) => (base64) =>
	cy.task('probe:zip', { base64 }).then((members) => {
		const member = members.find((entry) => entry.name.endsWith(name))
		expect(member, `${name} is missing from the archive`).to.not.be.undefined
		return member.base64
	})

describe('Trigger × access matrix', () => {
	let recipient
	let link

	/**
	 * The six ways the same file can arrive. Each returns the delivered bytes of
	 * `name` - unpacked from the archive for the ZIP cells, so all six are directly
	 * comparable with each other and with the stored file.
	 */
	const access = [
		{
			label: 'owner, direct fetch',
			owner: true,
			fetch: (name) => fetchBytes({
				url: `/remote.php/dav/files/${admin().user}/${folder}/${name}`,
				...admin(),
			}),
		},
		{
			label: 'owner, folder ZIP',
			owner: true,
			fetch: (name) => fetchBytes({
				url: `/remote.php/dav/files/${admin().user}/${folder}?accept=zip`,
				...admin(),
				headers: zipHeaders,
			}).then(memberOf(name)),
		},
		{
			label: 'recipient, direct fetch',
			owner: false,
			fetch: (name) => fetchBytes({
				url: `/remote.php/dav/files/${recipientUid}/${folder}/${name}`,
				user: recipient.user,
				password: recipient.password,
			}),
		},
		{
			label: 'recipient, folder ZIP',
			owner: false,
			fetch: (name) => fetchBytes({
				url: `/remote.php/dav/files/${recipientUid}/${folder}?accept=zip`,
				user: recipient.user,
				password: recipient.password,
				headers: zipHeaders,
			}).then(memberOf(name)),
		},
		{
			label: 'public link, direct fetch',
			owner: false,
			fetch: (name) => fetchBytes({
				url: `/public.php/dav/files/${link.token}/${name}`,
			}),
		},
		{
			label: 'public link, folder ZIP',
			owner: false,
			// The trailing slash is required: the public server's base URI *is*
			// `/public.php/dav/files/<token>/`, and a request without it is rejected as
			// out of base before any plugin sees it.
			fetch: (name) => fetchBytes({
				url: `/public.php/dav/files/${link.token}/?accept=zip`,
				headers: zipHeaders,
			}).then(memberOf(name)),
		},
	]

	before(() => {
		cy.ncLogin()

		// A known-neutral policy *before* anything is uploaded. The policy is server-wide
		// and survives between specs and runs, so a leftover `on_upload` would mark every
		// fixture as it arrives, and the on_demand row would then be measuring a file that
		// was already marked before its own trigger was set - passing for the wrong reason,
		// which is worse than failing.
		cy.wmSetPolicy({ trigger: 'on_demand' })
		cy.wmFolder(folder)
		cy.wmUser(recipientUid).then((credentials) => {
			recipient = credentials
		})

		// on_upload's file is deliberately *not* uploaded here: it has to be created
		// once, under its own policy, so that the upload being measured is the file's
		// first. See the note on that row below.
		Object.entries(files)
			.filter(([trigger]) => trigger !== 'on_upload')
			.forEach(([trigger, name]) => {
				cy.task('fixture:pdf', { pages: 2, text: trigger }).then((base64) => {
					cy.wmUpload(`${folder}/${name}`, base64)
				})
			})

		// One folder share covers four of the six cells for every trigger, and the
		// policy is switched on afterwards - nothing is copied or rewritten when a
		// share is created.
		cy.wmUnshareAll(`/${folder}`)
		cy.wmShare({ path: `/${folder}`, shareWith: recipientUid })
		cy.wmShare({ path: `/${folder}`, shareType: 3, permissions: 1 }).then((share) => {
			link = share
		})
	})

	after(() => {
		cy.wmSetPolicy({ trigger: 'on_demand' })
		cy.wmUnshareAll(`/${folder}`)
		cy.task('nc:delete', { ...admin(), path: folder })
	})

	/**
	 * One row per trigger. The trigger only decides how the file comes to be marked; every
	 * cell below expects the same thing of it afterwards.
	 */
	const row = (trigger, mark) => {
		describe(`${trigger} - watermarked on every fetch, for every reader`, () => {
			before(() => {
				cy.wmSetPolicy({ trigger })
				mark()
			})

			access.forEach(({ label, fetch }) => {
				it(`watermarks: ${label}`, () => {
					fetch(files[trigger]).then((base64) => {
						cy.task('probe:pdf', { base64 }).then((pdf) => {
							expect(pdf.watermarked, `${label} served the clean original`).to.be.true
							expect(pdf.pages, `${label} lost a page`).to.eq(2)
						})
					})
				})
			})

			/**
			 * **Six deliveries later, the file on disk is still the one that was uploaded.**
			 *
			 * Rendering per fetch is only true if nothing was written back, and a renderer
			 * that wrote its result back would pass every assertion above while destroying
			 * the original. Unmarking is what exposes the stored bytes to look at: it
			 * restores nothing, so what comes back is whatever has been there all along.
			 */
			it('leaves the stored bytes alone through all of it', () => {
				cy.wmRemove(`${folder}/${files[trigger]}`).its('status').should('eq', 200)
				cy.wmDownload(`${folder}/${files[trigger]}`).then((base64) => {
					cy.task('probe:pdf', { base64 }).then((pdf) => {
						expect(pdf.watermarked, 'a delivery wrote its render back to storage').to.be.false
						expect(pdf.pages).to.eq(2)
					})
				})
			})
		})
	}

	row('on_demand', () => {
		cy.wmApply(`${folder}/${files.on_demand}`)
	})

	/**
	 * The on_upload file is created here rather than in the outer `before`, so this row
	 * measures a first upload and nothing else.
	 *
	 * An overwrite is a different question with its own answer - it used to land clean and
	 * still badged, and closing that took three fixes - so it is asserted on its own in
	 * `02-on-upload` rather than folded in here, where a failure would read as a broken
	 * matrix cell.
	 */
	row('on_upload', () => {
		cy.task('fixture:pdf', { pages: 2, text: 'on_upload' }).then((base64) => {
			cy.wmUpload(`${folder}/${files.on_upload}`, base64)
		})
	})
})
