require('./commands.js')

/**
 * Nextcloud's frontend logs its own errors and occasionally throws from a
 * third-party widget the app has no part in; an unrelated one must not fail a
 * watermark assertion. Errors the specs care about surface as failed assertions on
 * the delivered bytes, not as browser exceptions.
 */
Cypress.on('uncaught:exception', () => false)
