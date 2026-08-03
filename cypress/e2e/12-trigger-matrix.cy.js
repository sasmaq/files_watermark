/**
 * The trigger × access matrix: every trigger, fetched every way.
 *
 * Four triggers against six access paths — owner direct, owner ZIP, recipient direct,
 * recipient ZIP, public-link direct, public-link ZIP — because the app's behaviour is a
 * function of both together, and every delivery bug found so far has been one cell of
 * this table disagreeing with its neighbours:
 *
 * - the archive gate keyed off the container, so a received single-file share served the
 *   clean original while the same file fetched directly came back watermarked;
 * - public links are a second Sabre server, so a plugin registered once covered the
 *   authenticated cells only;
 * - public links are served off the *owner's* storage, so an owner/recipient test based
 *   on the storage backend waved them through.
 *
 * None of those is visible from a single cell. What makes the table worth running as a
 * table is the disagreements: `on_share` must watermark for everyone **except** the owner,
 * `on_download` for everyone **including** the owner, and the two in-place triggers must
 * watermark through every path for a different reason entirely — the bytes on disk already
 * carry it.
 *
 * That last row is asserted as a **negative**, which is the whole point of including it.
 * "Watermarked" is not interesting for `on_demand` and `on_upload`: the burn put it there
 * and every path would report it. What must be true is that **no interceptor engaged** —
 * so each cell is compared byte-for-byte against the stored file. A delivery renderer that
 * woke up on an already-burned file would produce a valid, watermarked, *different* PDF,
 * pass every "is it watermarked" check, and stamp the document twice.
 *
 * Deeper per-mode assertions live with their own specs — preview blocking and the share
 * page's download link in `04-on-share`, archive membership and audit granularity in
 * `05-archives`, per-fetch rendering and the HEAD regression in `03-on-download`. This
 * file answers one question only: is any cell of the matrix wrong.
 */

const folder = 'e2e-matrix'
const recipientUid = 'e2e-matrix-recipient'
const zipHeaders = { Accept: 'application/zip' }

/** One file per trigger, so a burn in one row cannot change what another row measures. */
const files = {
	on_download: 'on-download.pdf',
	on_share: 'on-share.pdf',
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
	 * `name` — unpacked from the archive for the ZIP cells, so all six are directly
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

		// A known-neutral policy *before* anything is uploaded. The policy is
		// server-wide and survives between specs and runs, so a leftover `on_upload`
		// would burn every fixture as it arrives and each row below would then measure
		// a file that was already watermarked before its trigger was set — the
		// `on_share` owner cells fail, and the `on_download` cells pass for the wrong
		// reason, which is worse.
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
		// policy is switched on afterwards — nothing is copied or rewritten when a
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

	describe('on_download — rendered per fetch, for everyone including the owner', () => {
		before(() => {
			cy.wmSetPolicy({ trigger: 'on_download' })
		})

		access.forEach(({ label, fetch }) => {
			it(`watermarks: ${label}`, () => {
				fetch(files.on_download).then((base64) => {
					cy.task('probe:pdf', { base64 }).then((pdf) => {
						expect(pdf.watermarked, `${label} served the clean original`).to.be.true
						expect(pdf.pages, `${label} lost a page`).to.eq(2)
					})
				})
			})
		})

		it('leaves the stored bytes alone through all of it', () => {
			// Six deliveries later, the file on disk must still be the one uploaded.
			// Rendering per fetch is only true if nothing was written back.
			cy.wmSetPolicy({ trigger: 'on_demand' })
			cy.wmDownload(`${folder}/${files.on_download}`).then((base64) => {
				cy.task('probe:pdf', { base64 }).its('watermarked').should('be.false')
			})
		})
	})

	describe('on_share — for everyone except the owner', () => {
		before(() => {
			cy.wmSetPolicy({ trigger: 'on_share' })
		})

		access.forEach(({ label, owner, fetch }) => {
			const expectation = owner ? 'leaves alone' : 'watermarks'

			it(`${expectation}: ${label}`, () => {
				fetch(files.on_share).then((base64) => {
					cy.task('probe:pdf', { base64 }).then((pdf) => {
						expect(pdf.watermarked, owner
							? `${label} stamped the owner's own copy`
							: `${label} served the clean original`).to.eq(!owner)
					})
				})
			})
		})
	})

	/**
	 * The in-place rows. Both burn the watermark into the stored bytes, so what every
	 * cell has to show is that it delivered *those* bytes and nothing re-rendered them.
	 */
	const inPlace = (trigger, burn) => {
		describe(`${trigger} — burned into the stored bytes, no interceptor engages`, () => {
			let stored

			before(() => {
				cy.wmSetPolicy({ trigger })
				burn()

				// The owner's own fetch under an in-place policy is the stored file:
				// no delivery trigger is configured, so nothing intercepts it.
				cy.wmDownload(`${folder}/${files[trigger]}`).then((base64) => {
					stored = base64
					cy.task('probe:pdf', { base64 }).then((pdf) => {
						expect(pdf.watermarked, `${trigger} did not burn the watermark in`).to.be.true
						expect(pdf.pages, `${trigger} lost a page`).to.eq(2)
					})
				})
			})

			access.forEach(({ label, fetch }) => {
				it(`serves the stored bytes unrendered: ${label}`, () => {
					fetch(files[trigger]).then((base64) => {
						// Byte-identity, not "is it watermarked". A delivery renderer that
						// woke up here would return a valid, watermarked, *different* file
						// — a second stamp on an already-stamped document, which every
						// looser assertion accepts.
						expect(base64, `${label} re-rendered a file that was already burned`)
							.to.eq(stored)
					})
				})
			})
		})
	}

	inPlace('on_demand', () => {
		cy.wmApply(`${folder}/${files.on_demand}`)
	})

	/**
	 * Created here rather than in the outer `before`, so this row measures a first upload
	 * and nothing else.
	 *
	 * An overwrite is a different question with its own answer — it used to land clean and
	 * still badged, and closing that took three fixes — so it is asserted on its own in
	 * `02-on-upload` rather than folded in here, where a failure would read as a broken
	 * matrix cell.
	 */
	inPlace('on_upload', () => {
		cy.task('fixture:pdf', { pages: 2, text: 'on_upload' }).then((base64) => {
			cy.wmUpload(`${folder}/${files.on_upload}`, base64)
		})
	})
})
