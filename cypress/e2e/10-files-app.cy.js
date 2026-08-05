/**
 * The file actions, driven through the Files app.
 *
 * The API half of on-demand is covered in `01-on-demand.cy.js`; what only a browser
 * can show is the gating around it. The two actions mirror each other - a row must
 * never offer both - and the badge has to appear on the row that was just
 * watermarked, which is precisely where this app has been wrong before: Nextcloud
 * memoises `FileAction.enabled()` per node, so the just-watermarked file kept
 * offering Apply until the code started emitting `files:node:updated` itself.
 *
 * **A reload is part of the sequence, deliberately.** Every check up to that point can
 * pass on state this app is holding in the page - the id set it wrote when the apply
 * returned. Only a fresh page load makes the row prove itself from what the server
 * says, and that is where this went wrong in production: the badge disappeared and the
 * menu offered Apply on a watermarked file, so Remove - the only route back to the
 * original - was gone. The restore below runs on that reloaded page for the same reason.
 */

const folder = 'e2e-files-ui'
const name = 'ui.pdf'

/**
 * Open a row's overflow menu and hand back the menu element.
 *
 * The toggle has to be named precisely: the first button in the actions cell is the
 * sharing-status one, and clicking that opens the sharing sidebar and no menu at all.
 */
const openRowMenu = () => {
	cy.get('body').type('{esc}')

	cy.get(`[data-cy-files-list-row-name="${name}"]`, { timeout: 30000 })
		.find('button.action-item__menutoggle')
		.first()
		.click({ force: true })

	return cy.get('.v-popper__popper:not(.v-popper__popper--hidden) [role="menu"]', { timeout: 10000 })
}

describe('Files app actions', () => {
	before(() => {
		cy.ncLogin()
		cy.wmSetPolicy({ trigger: 'on_demand', textTemplate: 'E2E - {displayname}' })
		cy.wmFolder(folder)
		cy.task('fixture:pdf', { text: 'files app' })
			.then((base64) => cy.wmUpload(`${folder}/${name}`, base64))
	})

	after(() => {
		cy.task('nc:delete', { user: Cypress.env('ncUser'), password: Cypress.env('ncPassword'), path: folder })
	})

	it('offers Apply watermark on a supported, unwatermarked file', () => {
		cy.visit(`/apps/files/files?dir=/${folder}`)

		openRowMenu().within(() => {
			cy.contains('Apply watermark').should('exist')
			cy.contains('Remove watermark').should('not.exist')
		})
	})

	it('applies the watermark from the modal and badges the row', () => {
		cy.visit(`/apps/files/files?dir=/${folder}`)

		openRowMenu().contains('Apply watermark').click({ force: true })

		cy.contains('Apply Watermark', { timeout: 20000 }).should('exist')
		cy.get('.modal-container, [role="dialog"]').last().within(() => {
			cy.contains('button', 'Apply').click({ force: true })
			// The dialog swaps its own button to Close when the apply lands, which is
			// the client-side signal that the request came back clean.
			cy.contains('button', 'Close', { timeout: 60000 }).click({ force: true })
		})

		cy.get(`[data-cy-files-list-row-name="${name}"] .files-watermark-indicator`, { timeout: 20000 })
			.should('exist')
	})

	it('actually stamped the file, not just the row', () => {
		cy.wmDownload(`${folder}/${name}`).then((base64) => {
			cy.task('probe:pdf', { base64 }).its('watermarked').should('be.true')
		})
	})

	it('swaps to Remove watermark without a folder reload', () => {
		openRowMenu().within(() => {
			cy.contains('Remove watermark').should('exist')
			cy.contains('Apply watermark').should('not.exist')
		})
	})

	it('asks for the watermark status on the very first listing of a page load', () => {
		// The Files app builds its PROPFIND payload from the registered DAV properties
		// while its own bundle runs - so this app's bundle has to be loaded *ahead* of
		// it, which is what `Application::boot()` arranges. Regress that ordering and
		// this property is missing from the request below, every node in the first
		// listing comes back with no status, and the rest of this spec's symptoms
		// follow: no badge, and Apply offered on a file that is watermarked.
		cy.intercept({ method: 'PROPFIND', url: '**/remote.php/dav/files/**' }).as('listing')
		cy.visit(`/apps/files/files?dir=/${folder}`)
		cy.wait('@listing', { timeout: 30000 })
			.its('request.body')
			.should('contain', 'is-watermarked')
	})

	it('still badges the file and offers Remove after a page reload', () => {
		// The regression this spec exists for: everything above passes on the state the
		// browser is already holding. A reload throws that away, so the row has to be
		// rebuilt from what the server says - and when it could not be, the badge
		// vanished and the menu offered Apply on a file that is watermarked, which is
		// how a user loses the ability to restore their original.
		cy.visit(`/apps/files/files?dir=/${folder}`)

		cy.get(`[data-cy-files-list-row-name="${name}"] .files-watermark-indicator`, { timeout: 20000 })
			.should('exist')

		openRowMenu().within(() => {
			cy.contains('Remove watermark').should('exist')
			cy.contains('Apply watermark').should('not.exist')
		})
	})

	it('restores the original from the Remove action, badge and all', () => {
		openRowMenu().contains('Remove watermark').click({ force: true })

		// Destructive-styled confirmation: the watermarked version is discarded.
		cy.get('.modal-container, [role="dialog"]').last().within(() => {
			cy.contains('button', /Remove|Restore|Confirm/).click({ force: true })
		})

		cy.get(`[data-cy-files-list-row-name="${name}"] .files-watermark-indicator`, { timeout: 20000 })
			.should('not.exist')

		cy.wmDownload(`${folder}/${name}`).then((base64) => {
			cy.task('probe:pdf', { base64 }).its('watermarked').should('be.false')
		})
	})

	it('hides both actions when the policy is on_upload', () => {
		// The actions are gated on the effective trigger, which reaches the client as
		// initial state - so this needs a fresh page load to take effect. Under on_upload
		// the app marks every supported upload itself, so a manual action could only ever
		// contradict the policy.
		cy.wmSetPolicy({ trigger: 'on_upload' })
		cy.visit(`/apps/files/files?dir=/${folder}`)

		openRowMenu().within(() => {
			cy.contains('Apply watermark').should('not.exist')
			cy.contains('Remove watermark').should('not.exist')
		})

		cy.wmSetPolicy({ trigger: 'on_demand' })
	})
})
