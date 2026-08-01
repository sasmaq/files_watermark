const fixtures = require('./fixtures')
const http = require('./http')
const image = require('./image')
const occ = require('./occ')
const pdf = require('./pdf')
const zip = require('./zip')

/**
 * Everything the specs do outside the browser.
 *
 * Two jobs live here rather than in the browser: binary-safe HTTP (see
 * `tasks/http.js` for why `cy.request` cannot carry a PDF), and reading the
 * delivered bytes. Tasks must return something JSON-serialisable, so buffers cross
 * the boundary as base64.
 */
module.exports = {
	'fixture:pdf': (args = {}) => fixtures.makePdf(args),
	'fixture:png': (args = {}) => fixtures.makePng(args),

	'nc:get': http.httpGet,
	'nc:put': http.davPut,
	'nc:putMany': http.davPutMany,
	'nc:download': http.davGet,
	'nc:delete': http.davDelete,
	'nc:copy': http.davCopy,
	'nc:mkcol': http.davMkcol,
	'nc:propfind': http.davPropfind,
	'nc:chunkedUpload': http.davChunkedUpload,
	'nc:ocs': http.ocs,
	'nc:occ': occ.occ,

	// Prints to the terminal running the suite. Headless Cypress swallows the
	// browser console, and a UI selector that stopped matching is unfixable without
	// seeing the markup it was supposed to match.
	'debug:log': (value) => {
		process.stdout.write(`\n[debug] ${typeof value === 'string' ? value : JSON.stringify(value, null, 2)}\n`)
		return null
	},

	'probe:pdf': pdf.probe,
	'probe:image': image.probe,
	'probe:zip': zip.list,
}
