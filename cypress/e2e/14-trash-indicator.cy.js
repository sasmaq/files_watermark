/**
 * The badge on a deleted file.
 *
 * A trashed file keeps its id, so it keeps its mark: its download out of the trash is
 * watermarked and its preview always was. The badge was the only part that disagreed.
 *
 * **This has to be an e2e test.** Every server-side check passed while the badge was
 * missing - `PropFindPlugin` serves the property correctly for a trashed node, and a curl
 * PROPFIND proves it. What was broken lived in the gap: `files_trashbin` freezes its
 * PROPFIND body at its own module load, so this app's property is never in the request
 * however early its bundle runs, and the trash falls back to `GET /api/v1/watermarked` -
 * whose scope test resolved inside `/{uid}/files` and dropped every trashed id as
 * inaccessible. Only a real browser loading the real trash view crosses that gap.
 *
 * The Files list is asserted first, as a control, so a failure says which half broke.
 */

const folder = 'e2e-trash-badge'
const name = 'trashed.pdf'

describe('Watermark badge in the trash', () => {
	before(() => {
		cy.ncLogin()
		cy.wmSetPolicy({ trigger: 'on_upload', textTemplate: 'E2E - {displayname}' })
		cy.wmFolder(folder)
		cy.task('fixture:pdf', { text: 'trash badge' })
			.then((base64) => cy.wmUpload(`${folder}/${name}`, base64))
	})

	after(() => {
		cy.task('nc:delete', { user: Cypress.env('ncUser'), password: Cypress.env('ncPassword'), path: folder })
	})

	it('shows the badge in the Files list first (the control)', () => {
		cy.visit(`/apps/files/files?dir=/${folder}`)
		cy.get(`[data-cy-files-list-row-name="${name}"]`, { timeout: 30000 })
			.find('.files-watermark-indicator')
			.should('exist')
	})

	it('still shows it once the file is deleted', () => {
		cy.task('nc:delete', {
			user: Cypress.env('ncUser'),
			password: Cypress.env('ncPassword'),
			path: `${folder}/${name}`,
		})

		cy.visit('/apps/files/trashbin')

		cy.get(`[data-cy-files-list-row-name^="${name}"]`, { timeout: 30000 })
			.should('exist')
			.find('.files-watermark-indicator')
			.should('exist')
	})
})
