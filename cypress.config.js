const { defineConfig } = require('cypress')

const tasks = require('./cypress/tasks/index.js')

/**
 * End-to-end suite. Drives a real Nextcloud with the app enabled - see
 * `cypress/README.md` for standing it up.
 *
 * `testIsolation` is off: these specs share one server-wide policy and a folder of
 * uploaded fixtures, and Cypress's per-test browser reset would throw away the admin
 * session before every one of them. Each spec sets the config it needs in `before`
 * and cleans up its own folder.
 */
module.exports = defineConfig({
	e2e: {
		baseUrl: process.env.NC_URL || 'http://localhost:8080',
		specPattern: 'cypress/e2e/**/*.cy.js',
		supportFile: 'cypress/support/e2e.js',
		fixturesFolder: false,
		testIsolation: false,
		video: false,
		screenshotOnRunFailure: true,
		// A desktop-sized window: the settings page lays its form out in two columns
		// and the Files list hides its action column when narrow.
		viewportWidth: 1440,
		viewportHeight: 900,
		screenshotsFolder: 'cypress/screenshots',
		// Rendering a tiled watermark per fetch is real work, and an archive renders
		// once per member; the stock 4s is not enough for the archive specs.
		defaultCommandTimeout: 15000,
		responseTimeout: 60000,
		retries: { runMode: 1, openMode: 0 },
		setupNodeEvents(on, config) {
			on('task', tasks)
			return config
		},
	},
	env: {
		ncUser: process.env.NC_ADMIN || 'admin',
		ncPassword: process.env.NC_ADMIN_PASSWORD || 'admin',
	},
})
