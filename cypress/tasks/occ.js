const { execFile } = require('child_process')

/**
 * Runs `occ` against the instance under test.
 *
 * This exists for one assertion the unit tests cannot make: they stub `IAppConfig`, so
 * they prove the *plugin* honours whatever `ArchiveLimits` returns and prove nothing
 * about the key an admin actually types. `occ config:app:set files_watermark
 * archive_max_members` is that key - a rename on either side leaves both suites green
 * and the setting inert, which is the exact failure the removed group/user overrides
 * were.
 *
 * The command is configurable so the suite is not welded to Docker: `NC_OCC` overrides
 * it (e.g. `sudo -u www-data php /var/www/nextcloud/occ` on a real host).
 */
const COMMAND = process.env.NC_OCC
	|| 'docker compose exec -T -u www-data nextcloud php occ'

/**
 * @param {{args: string[], timeout?: number}} options the `occ` arguments to run
 * @return {Promise<{code: number, stdout: string, stderr: string}>} never rejects -
 *   a non-zero exit is a result the spec asserts on, not a harness error
 */
function occ({ args = [], timeout = 120000 }) {
	const [file, ...prefix] = COMMAND.split(' ')

	return new Promise((resolve) => {
		execFile(file, [...prefix, ...args], { timeout }, (error, stdout, stderr) => {
			resolve({
				code: error?.code ?? 0,
				stdout: String(stdout),
				stderr: String(stderr),
			})
		})
	})
}

module.exports = { occ, COMMAND }
