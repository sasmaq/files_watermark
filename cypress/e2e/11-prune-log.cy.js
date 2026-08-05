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
 *    `WHERE` clauses against a mocked query builder - they cannot see a clause that is
 *    valid SQL and matches the wrong thing.
 *
 * **What changed, and what this spec is now for.** The command used to be able to delete
 * delivery rows and nothing else, because the other rows were not history - they were how
 * the app knew a file was watermarked, so deleting one un-badged its file. That record
 * lives in `watermark_mark` now, and the carve-out went with it: `--all` really does mean
 * every row.
 *
 * So the assertion that matters has inverted. It is no longer "the command refuses to
 * touch those rows" - it is **"the log can be emptied completely and the file is still
 * watermarked"**. That is the whole benefit of splitting the two tables, and it is only
 * observable against a real database.
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

		// A file that is marked and never fetched: one mark row, no delivery rows.
		cy.wmSetPolicy({ trigger: 'on_demand' })
		cy.task('fixture:pdf', { text: 'applied' }).then((base64) => cy.wmUpload(applied, base64))
		cy.wmApply(applied).its('body.status').should('eq', 'watermarked')
		cy.wmFileId(applied).then((id) => {
			ids['applied.pdf'] = id
		})

		// ...and a file that is marked *and* fetched twice, so it carries the delivery rows
		// this command exists to remove.
		cy.task('fixture:pdf', { text: 'delivered' }).then((base64) => cy.wmUpload(delivered, base64))
		cy.wmFileId(delivered).then((id) => {
			ids['delivered.pdf'] = id
		})
		cy.wmSetPolicy({ trigger: 'on_demand', logDelivery: true })
		cy.wmApply(delivered)
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

	it('starts from one mark row and one mark plus two delivery rows', () => {
		// Marking is recorded unconditionally - it is one row per policy decision - so the
		// fetched file carries three rows: its mark, and one per download.
		logRows().then(rowsFor('delivered.pdf'))
			.should('deep.eq', ['delivered', 'delivered', 'on_demand'])
		logRows().then(rowsFor('applied.pdf')).should('deep.eq', ['on_demand'])
	})

	it('reports without deleting under --dry-run', () => {
		cy.task('nc:occ', { args: ['files_watermark:prune-log', '--all', '--dry-run'] })
			.then((result) => {
				expect(result.code, result.stderr).to.eq(0)
				expect(result.stdout).to.match(/Would delete \d+ audit row\(s\)/)
			})

		logRows().then(rowsFor('delivered.pdf')).should('have.length', 3)
		logRows().then(rowsFor('applied.pdf')).should('have.length', 1)
	})

	it('leaves everything alone when nothing is old enough', () => {
		// The rows were written seconds ago, so the default 90-day retention must not
		// match them - an off-by-one on the cutoff would take the lot.
		cy.task('nc:occ', { args: ['files_watermark:prune-log'] }).then((result) => {
			expect(result.code, result.stderr).to.eq(0)
			expect(result.stdout).to.contain('Deleted 0 audit row(s)')
		})

		logRows().then(rowsFor('delivered.pdf')).should('have.length', 3)
		logRows().then(rowsFor('applied.pdf')).should('have.length', 1)
	})

	/**
	 * **The point of the whole split, asserted end to end.**
	 *
	 * `--all` empties the log - mark rows included, which it could not reach before - and
	 * both files are still watermarked afterwards. Under the old scheme this exact command
	 * would have un-badged them and let them be stamped a second time.
	 *
	 * The download is checked as well as the badge, because the badge is a WebDAV property
	 * and could in principle be answered from somewhere else; a watermark in the delivered
	 * bytes cannot be.
	 */
	it('empties the log without changing any file\'s watermarked status', () => {
		cy.task('nc:occ', { args: ['files_watermark:prune-log', '--all'] }).then((result) => {
			expect(result.code, result.stderr).to.eq(0)
			expect(result.stdout).to.match(/Deleted \d+ audit row\(s\) of any age/)
		})

		logRows().then(rowsFor('delivered.pdf')).should('be.empty')
		logRows().then(rowsFor('applied.pdf')).should('be.empty')

		cy.wmIsWatermarkedProp(applied).should('eq', '1')
		cy.wmIsWatermarkedProp(delivered).should('eq', '1')

		cy.wmDownload(applied).then((base64) => {
			cy.task('probe:pdf', { base64 }).its('watermarked').should('be.true')
		})
	})

	/**
	 * A file whose history has been pruned is still marked, so applying again is the same
	 * no-op it was before the prune.
	 *
	 * This is the failure the old carve-out existed to prevent, checked from the other
	 * side: if the log were still the app's memory, an emptied log would make this report
	 * a fresh watermark and stamp the document twice.
	 */
	it('still refuses to mark a pruned file a second time', () => {
		cy.wmApply(applied).its('body.status').should('eq', 'already_watermarked')
	})

	/**
	 * There is still no scope option, for a different reason than there used to be.
	 *
	 * `--include-applied` existed to unlock rows the command refused to touch. Nothing is
	 * refused now, so `--days` and `--all` are the whole of what retention means and the
	 * flag has nothing left to include. Asking for it is a usage error rather than a
	 * silently ignored argument.
	 */
	it('has no scope option', () => {
		cy.task('nc:occ', { args: ['files_watermark:prune-log', '--all', '--include-applied'] })
			.then((result) => {
				expect(result.code, 'the option still exists').to.not.eq(0)
				expect(`${result.stdout}${result.stderr}`).to.contain('--include-applied')
			})
	})

	it('rejects a --days value that is not a positive number', () => {
		// Coercing `abc` to 0 would delete everything, which is the opposite of what a
		// mistyped retention means.
		cy.task('nc:occ', { args: ['files_watermark:prune-log', '--days', 'abc'] })
			.then((result) => {
				expect(result.code, 'a mistyped retention was accepted').to.not.eq(0)
				expect(`${result.stdout}${result.stderr}`).to.contain('--days')
			})
	})
})
