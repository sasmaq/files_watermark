/**
 * Under on_upload, nobody removes a watermark - the owner included.
 *
 * The owner rule that governs on_demand assumes the owner chose the mark. Under on_upload
 * the policy chose it, and the owner of an uploaded file is whoever uploaded it, so that
 * rule handed every user an off switch for the policy: upload, remove, download clean. The
 * Files app had always hidden the action in this mode, but the endpoint behind it did not
 * ask, and it is one request away from anyone with an account.
 *
 * So this asserts the endpoint, not the UI - the hidden button is `10-files-app`'s, and was
 * never the control. The two places a client learns that a file is locked - the
 * `nc:watermark-locked` DAV property, and the status endpoint the Files app falls back to
 * for a listing that arrived without it - are asserted alongside, because a lock the server
 * enforces but does not report is a Remove button that fails when clicked.
 */

const folder = 'e2e-unmark-policy'
const files = {
	// Marked by the policy as it arrives.
	uploaded: `${folder}/uploaded.pdf`,
	// Marked on demand, before the trigger was switched.
	applied: `${folder}/applied.pdf`,
	// Never marked: uploaded under on_demand and left alone.
	clean: `${folder}/clean.pdf`,
}

describe('Unmarking under the on_upload trigger', () => {
	const originals = {}

	before(() => {
		cy.ncLogin()
		cy.wmSetPolicy({ trigger: 'on_demand' })
		cy.wmFolder(folder)

		cy.task('fixture:pdf', { text: 'applied' }).then((base64) => cy.wmUpload(files.applied, base64))
		cy.wmApply(files.applied).its('status').should('eq', 200)
		cy.task('fixture:pdf', { text: 'clean' }).then((base64) => cy.wmUpload(files.clean, base64))

		cy.wmSetPolicy({ trigger: 'on_upload' })
		cy.task('fixture:pdf', { text: 'uploaded' }).then((base64) => {
			originals.uploaded = base64
			cy.wmUpload(files.uploaded, base64)
		})
	})

	after(() => {
		cy.wmSetPolicy({ trigger: 'on_demand' })
		cy.task('nc:delete', { user: Cypress.env('ncUser'), password: Cypress.env('ncPassword'), path: folder })
	})

	it('refuses the owner with 403, and the file stays watermarked', () => {
		cy.wmIsWatermarkedProp(files.uploaded).should('eq', '1')

		cy.wmRemove(files.uploaded, { failOnStatusCode: false }).then((response) => {
			expect(response.status, 'the owner unmarked a file the policy marked').to.eq(403)
			expect(response.body.error).to.eq('Watermarks cannot be removed under the current watermark policy.')
		})

		cy.wmIsWatermarkedProp(files.uploaded).should('eq', '1')
		cy.wmDownload(files.uploaded).then((base64) => {
			cy.task('probe:pdf', { base64 }).then((pdf) => {
				expect(pdf.watermarked, 'a refused unmark took the watermark off anyway').to.be.true
			})
		})
	})

	/**
	 * **A mark placed on demand is locked too, once the trigger is on_upload.** The trigger
	 * in force decides, not how a particular file came to be marked - otherwise every file
	 * marked before the switch would be an exception to the policy that nobody can see.
	 */
	it('refuses a mark placed on demand before the switch', () => {
		cy.wmRemove(files.applied, { failOnStatusCode: false }).its('status').should('eq', 403)
		cy.wmIsWatermarkedProp(files.applied).should('eq', '1')
	})

	it('reports every marked file as locked, over DAV and the status endpoint', () => {
		cy.wmIsLockedProp(files.uploaded).should('eq', '1')
		cy.wmIsLockedProp(files.applied).should('eq', '1')
		// Nothing to remove, so nothing to lock. Switching the trigger marks nothing that
		// is already stored, so this one is still clean.
		cy.wmIsWatermarkedProp(files.clean).should('eq', '0')
		cy.wmIsLockedProp(files.clean).should('eq', '0')

		const ids = {}
		Object.entries(files).forEach(([key, path]) => {
			cy.wmFileId(path).then((id) => {
				ids[key] = id
			})
		})

		cy.then(() => cy.wmApi('GET', `/api/v1/watermarked?ids=${Object.values(ids).join(',')}`))
			.its('body.locked')
			.should('have.members', [ids.uploaded, ids.applied])
	})

	/**
	 * **Switching back to on_demand hands the action back.** The lock belongs to the policy,
	 * not to the files - nothing was written onto them that would need undoing - so the owner
	 * rule governs again the moment the trigger changes, and the stored bytes are still the
	 * ones that were uploaded.
	 */
	it('lets the owner unmark again once the trigger is back to on_demand', () => {
		cy.wmSetPolicy({ trigger: 'on_demand' })

		cy.wmIsLockedProp(files.uploaded).should('eq', '0')
		cy.wmRemove(files.uploaded).its('status').should('eq', 200)
		cy.wmDownload(files.uploaded).then((base64) => {
			expect(base64, 'the stored file is not the one that was uploaded').to.eq(originals.uploaded)
		})
	})
})
