/**
 * `occ files_watermark:prune-log`, driven as an admin drives it.
 *
 * Two things only a real instance can answer, and both have been silent failures in
 * apps before:
 *
 *  - **the command is registered at all.** It reaches `occ` through a `<commands>`
 *    entry in `appinfo/info.xml`, which no unit test reads. A class that is never
 *    listed is a command that does not exist, and its unit tests stay green;
 *  - **it deletes the right rows in a real database.** The mapper tests assert the
 *    `WHERE` clauses against a mocked query builder — they cannot see a clause that is
 *    valid SQL and matches the wrong thing.
 *
 * The in-place half is the one worth being careful about: those rows are not history,
 * they are how the app knows a file's stored bytes carry a watermark. Pruning cannot
 * reach them at all — not by default, but by construction — so the last test here asks
 * for the flag that used to allow it and expects the command to refuse.
 */

const folder = 'e2e-prune'
const applied = `${folder}/applied.pdf`
const delivered = `${folder}/delivered.pdf`

/**
 * Rows are matched by **file id**, never by path. The log accumulates across runs and the
 * fixtures are recreated with the same names every time, so a path match would pick up
 * every previous run's rows and the assertions would drift with the instance's history.
 */
const ids = {}

const rowsFor = (name) => (rows) =>
	rows.filter((entry) => entry.fileId === ids[name]).map((entry) => entry.trigger)

const logRows = () => cy.wmApi('GET', '/api/v1/log?limit=500').its('body')

describe('occ files_watermark:prune-log', () => {
	before(() => {
		cy.ncLogin()
		cy.wmFolder(folder)

		// One in-place row (on_demand), which must survive an ordinary prune...
		cy.wmSetPolicy({ trigger: 'on_demand' })
		cy.task('fixture:pdf', { text: 'applied' }).then((base64) => cy.wmUpload(applied, base64))
		cy.wmApply(applied).its('body.status').should('eq', 'watermarked')
		cy.wmFileId(applied).then((id) => {
			ids['applied.pdf'] = id
		})

		// ...and delivery rows, which are what it exists to remove.
		cy.task('fixture:pdf', { text: 'delivered' }).then((base64) => cy.wmUpload(delivered, base64))
		cy.wmFileId(delivered).then((id) => {
			ids['delivered.pdf'] = id
		})
		cy.wmSetPolicy({ trigger: 'on_download', logDelivery: true })
		cy.wmDownload(delivered)
		cy.wmDownload(delivered)
	})

	after(() => {
		cy.wmSetPolicy({ trigger: 'on_demand', logDelivery: false })
		cy.task('nc:delete', { user: Cypress.env('ncUser'), password: Cypress.env('ncPassword'), path: folder })
	})

	it('is registered with occ', () => {
		cy.task('nc:occ', { args: ['list', 'files_watermark'] }).then((result) => {
			expect(result.code, result.stderr).to.eq(0)
			expect(result.stdout).to.contain('files_watermark:prune-log')
		})
	})

	it('starts from two delivery rows and one apply row', () => {
		logRows().then(rowsFor('delivered.pdf')).should('deep.eq', ['on_download', 'on_download'])
		logRows().then(rowsFor('applied.pdf')).should('deep.eq', ['on_demand'])
	})

	it('reports without deleting under --dry-run', () => {
		cy.task('nc:occ', { args: ['files_watermark:prune-log', '--all', '--dry-run'] })
			.then((result) => {
				expect(result.code, result.stderr).to.eq(0)
				expect(result.stdout).to.match(/Would delete \d+ row\(s\)/)
			})

		logRows().then(rowsFor('delivered.pdf')).should('have.length', 2)
	})

	it('leaves everything alone when nothing is old enough', () => {
		// The rows were written seconds ago, so the default 90-day retention must not
		// match them — an off-by-one on the cutoff would take the lot.
		cy.task('nc:occ', { args: ['files_watermark:prune-log'] }).then((result) => {
			expect(result.code, result.stderr).to.eq(0)
			expect(result.stdout).to.contain('Deleted 0 row(s)')
		})

		logRows().then(rowsFor('delivered.pdf')).should('have.length', 2)
	})

	it('deletes delivery rows and keeps the apply row', () => {
		cy.task('nc:occ', { args: ['files_watermark:prune-log', '--all'] }).then((result) => {
			expect(result.code, result.stderr).to.eq(0)
			expect(result.stdout).to.contain('delivery rows only')
		})

		logRows().then(rowsFor('delivered.pdf')).should('be.empty')
		// The one that matters: the file is still watermarked, and the app still knows.
		logRows().then(rowsFor('applied.pdf')).should('deep.eq', ['on_demand'])
		cy.wmIsWatermarkedProp(applied).should('eq', '1')
	})

	it('has no option that would clear a badge', () => {
		// The guarantee is that a retention command cannot make the app forget a file it
		// has stamped — so the flag that used to allow it is gone, and asking for it is a
		// usage error rather than a silently ignored argument.
		cy.task('nc:occ', { args: ['files_watermark:prune-log', '--all', '--include-applied'] })
			.then((result) => {
				expect(result.code, 'the option still exists').to.not.eq(0)
				expect(`${result.stdout}${result.stderr}`).to.contain('--include-applied')
			})

		logRows().then(rowsFor('applied.pdf')).should('deep.eq', ['on_demand'])
		cy.wmIsWatermarkedProp(applied).should('eq', '1')
	})
})
