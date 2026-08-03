/**
 * The admin settings page, driven as an admin drives it.
 *
 * Everything else in this suite talks to the API the page talks to, which proves the
 * server and proves nothing about the page: a form that fails to mount, loses the
 * stored policy on reload, or posts a field the server rejects is invisible to all of
 * it. So this spec only asserts things that require a browser - that the Vue app
 * mounts into the server-rendered section, that a saved policy comes back on reload,
 * and that the live preview shows the text an admin is approving.
 *
 * Elements are asserted to *exist* rather than to be visible, and interactions are
 * forced. Nextcloud's settings layout puts the content in a `position: fixed`
 * scrolling ancestor, which Cypress's visibility rule reports as hidden however far
 * the page is scrolled - asserting visibility here would be testing the harness's
 * opinion of the layout, not the app.
 */

const settingsUrl = '/settings/admin/watermark'
const template = 'E2E Confidential - {displayname}'
const defaultTemplate = '{displayname} - {date}'

/** The settings page with the Vue app mounted. */
const openSettings = () => {
	cy.visit(settingsUrl)
	cy.get('#files-watermark-admin-settings', { timeout: 30000 }).should('exist')
	cy.get('.watermark-form', { timeout: 30000 }).should('exist')
}

describe('Admin settings page', () => {
	before(() => {
		cy.ncLogin()
		cy.wmSetPolicy({ trigger: 'on_demand', textTemplate: defaultTemplate })
	})

	after(() => {
		cy.wmSetPolicy({ trigger: 'on_demand', textTemplate: defaultTemplate })
	})

	it('mounts the settings app into the admin section', () => {
		openSettings()
		cy.contains('h3', 'Global Watermark Policy').should('exist')
	})

	it('saves a policy the server accepts, and confirms it on screen', () => {
		openSettings()

		cy.get('.watermark-form input[type="text"]').first()
			.scrollIntoView()
			.clear({ force: true })
			// `{displayname}` is a template token, not a Cypress key sequence.
			.type(template, { force: true, parseSpecialCharSequences: false })

		cy.get('input[name="wm-trigger"][value="on_download"]').check({ force: true })

		cy.get('.wm-save').scrollIntoView().click({ force: true })
		cy.get('.wm-status--success', { timeout: 20000 }).should('contain', 'Saved')
	})

	it('shows the saved policy again after a reload', () => {
		openSettings()

		cy.get('.watermark-form input[type="text"]').first().should('have.value', template)
		cy.get('input[name="wm-trigger"][value="on_download"]').should('be.checked')
	})

	it('stored exactly what the form showed', () => {
		// The page is what an admin approves; the API is what the renderers read. A
		// form that posts a different template than it displays is the one failure
		// neither side can see alone.
		cy.wmApi('GET', '/api/v1/config').then((response) => {
			const config = response.body.configs[0]
			expect(config.textTemplate).to.eq(template)
			expect(config.trigger).to.eq('on_download')
		})
	})

	it('previews the template rather than the raw token', () => {
		openSettings()

		// The preview substitutes sample values, so the literal half of the template
		// must be on screen and the token must not.
		cy.get('.watermark-form svg text').should('exist')
		cy.get('.watermark-form svg').should('contain', 'E2E Confidential')
		cy.get('.watermark-form svg').should('not.contain', '{displayname}')
	})

	it('lists watermark activity in the audit log', () => {
		cy.wmSetPolicy({ trigger: 'on_demand', textTemplate: template })
		cy.wmFolder('e2e-audit')
		cy.task('fixture:pdf', { text: 'audited' })
			.then((base64) => cy.wmUpload('e2e-audit/audited.pdf', base64))
		cy.wmApply('e2e-audit/audited.pdf')

		openSettings()
		cy.contains('h3', 'Activity log').should('exist')
		cy.contains('audited.pdf', { timeout: 20000 }).should('exist')

		cy.task('nc:delete', {
			user: Cypress.env('ncUser'),
			password: Cypress.env('ncPassword'),
			path: 'e2e-audit',
		})
	})
})
