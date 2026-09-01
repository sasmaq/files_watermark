import { loadState } from '@nextcloud/initial-state'
import { inTestRunner, seedWatermarkedIds, startIndicator } from './indicator.js'

/**
 * The watermark badge on a **public share page** - the file list a link visitor lands on.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS BUNDLE EXISTS AT ALL, RATHER THAN LOADING `files.js` THERE.
 *
 * Everything a visitor can do on that page, they can do without an account: read the list
 * and download. The Files bundle carries two Vue modals, the Apply and Remove file actions
 * and every dependency behind them - none of which an anonymous reader can use, and all of
 * which would be shipped to every visitor of every public link on the server. This entry
 * pulls in the badge and nothing else.
 *
 * It also cannot accidentally offer an action a visitor has no session for. That is a
 * property of the file, not a runtime check that has to be kept correct.
 * ---------------------------------------------------------------------------
 *
 * **The badge means "a download of this file will be watermarked", and on this page that
 * is a wider statement than a mark.** With the public-link switch on, every file behind
 * the link is watermarked whether or not anybody marked it. The DAV property answers the
 * whole question - see `PropFindPlugin`'s delivery mode - so nothing here has to know
 * which of the two reasons applies.
 *
 * No REST fallback is wired up, unlike the authenticated bundle: the endpoint behind it
 * needs a session. What covers the same gap here is **initial state** - the ids the server
 * already knew while it was rendering the page, seeded before any listing arrives.
 *
 * That is not belt-and-braces, it is the primary source on this page. Nextcloud emits this
 * app's script *after* `files-main.js`, so `registerDavProperty()` runs after the file list
 * has built its PROPFIND and the first listing comes back with no property on any node. A
 * folder recovers on the next navigation; a single-file share never lists anything again,
 * so without the seed its badge could not appear at all. See
 * `PublicShareScriptsListener`.
 */
if (!inTestRunner()) {
	// Started first, so the observer is watching before the seed draws anything: on a page
	// whose rows are rendered by a Vue app that has not mounted yet, the first paint is the
	// observer's, not the seed's.
	startIndicator()
	seedWatermarkedIds(loadState('files_watermark', 'public-watermarked-ids', []))
}
