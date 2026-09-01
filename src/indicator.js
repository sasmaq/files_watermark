import { subscribe } from '@nextcloud/event-bus'
import { registerDavProperty } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'

/**
 * The watermark badge on a file row, and the id set that drives it.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS ITS OWN MODULE.
 *
 * Two pages draw this badge: the Files app, and the public share page a link visitor
 * lands on. The Files bundle is the wrong thing to hand a public visitor - it carries two
 * Vue modals, the Apply/Remove file actions and everything they pull in, none of which an
 * anonymous reader can use. Splitting the badge out lets `main-public.js` be the small
 * bundle that page deserves, while `main-files.js` re-exports everything here so the
 * authenticated side (and its tests) see no change.
 *
 * Nothing in here talks to this app's REST API, deliberately: every source it reads is
 * either the DAV property on the listing or an id handed to it by the caller, both of
 * which work with no session at all.
 * ---------------------------------------------------------------------------
 */

// WebDAV property served by our PROPFIND plugin. Requesting it here makes the client
// fetch it with every listing, so a node carries its watermarked status by the time its
// row renders - letting `enabled()` decide synchronously on the first (and, in Nextcloud,
// memoized) evaluation instead of racing an async lookup.
//
// **This line only reaches the first listing of a page load because the server loads this
// bundle ahead of the Files app's own** - the Files app builds its PROPFIND while its
// script runs, so a registration made after that is a registration the first listing never
// sees. `Application::boot()` is what buys the ordering, and `FilesPageScript` explains why
// nothing later can.
export const DAV_WATERMARKED_PROP = 'is-watermarked'
registerDavProperty(`nc:${DAV_WATERMARKED_PROP}`, { nc: 'http://nextcloud.org/ns' })

/**
 * Whether a Files `Node` is marked - that is, whether downloading or previewing it
 * produces a watermarked copy. Read from the WebDAV property delivered with the listing.
 * The plugin returns '1' for watermarked, '0' otherwise - but the webdav client parses tag
 * values (`parseTagValue: true`), so the value reaches us as the *number* 1/0, not a
 * string. Accept both to be safe.
 * @param {object} node - a Files `Node`
 * @return {boolean} true when the node is marked watermarked
 */
export function isNodeWatermarked(node) {
	const value = node?.attributes?.[DAV_WATERMARKED_PROP]
	return value === 1 || value === '1'
}

/**
 * Whether a node explicitly reports itself as *not* watermarked, as opposed to not
 * carrying the property at all. The distinction matters when clearing state after a
 * removal: a missing property means "unknown" (a listing fetched before the property was
 * registered), and treating that as "not watermarked" would wipe ids we legitimately
 * learned from elsewhere.
 * @param {object} node - a Files `Node`
 * @return {boolean} true when the property is present and false
 */
export function isNodeExplicitlyNotWatermarked(node) {
	const value = node?.attributes?.[DAV_WATERMARKED_PROP]
	return value === 0 || value === '0'
}

// The app's mark, from `img/app.svg`: a filled document with a watermark droplet knocked
// out of it. The knockout is what needs `fill-rule="evenodd"` - without it the droplet
// fills solid and the document reads as a plain page.
//
// **One path, two wrappers.** The row badge and the menu icon are the same shape at
// different sizes, so the symbol an admin learns in the Files list is the one they meet in
// the action menu. Kept as a constant rather than copied into both strings, because two
// copies of a path are two things to keep in step with `img/app.svg`.
const APP_MARK_PATH = 'M6 2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm6 7c-1.6 2-3 4.3-3 6a3 3 0 0 0 6 0c0-1.7-1.4-4-3-6Z'

/**
 * One `<svg>` carrying the app mark.
 * @param {string} attributes - extra attributes for the root element, e.g. a fixed size
 * @return {string} the markup
 */
export function appMarkSvg(attributes) {
	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"'
		+ ' fill-rule="evenodd" clip-rule="evenodd" ' + attributes + '>'
		+ '<path d="' + APP_MARK_PATH + '"/></svg>'
}

// Sized here rather than in CSS: the badge is injected into a row Nextcloud owns, and an
// unsized SVG there inherits whatever the file list happens to be doing.
const INDICATOR_SVG = appMarkSvg('width="16" height="16" aria-hidden="true"')

// Nextcloud's FileAction API can't render an icon-only, non-interactive badge in both list
// AND grid view: `inline` actions are drawn by NcActions with `force-name`, so they show a
// text label, and `renderInline` custom elements are list-view only (`gridMode ? [] : …`).
// So the badge is drawn directly onto the row, driven entirely by the WebDAV
// `is-watermarked` property the listing already carries. No extra HTTP lookup, no text,
// works in grid - and works with no session, which is what lets the public share page
// reuse it unchanged.

// Marker class so the badge can be found / deduped / removed on a row or tile.
const INDICATOR_CLASS = 'files-watermark-indicator'

// File ids the current listing reports as watermarked, read from the DAV property.
const watermarkedIds = new Set()

/**
 * Ids the *server* told us about when it rendered the page, rather than ids read from a
 * listing.
 *
 * Kept apart from the set above because they answer to nothing: a listing that arrives
 * without the DAV property replaces `watermarkedIds` with the empty set, and these have to
 * survive that. The public share page is where it matters - see `main-public.js` - and it
 * is the only caller.
 */
const seededIds = new Set()

/**
 * Seed ids the page was rendered with, and draw them.
 *
 * @param {Array<number|string>} ids - file ids the server reports as watermarked
 * @return {Set<number>} the seeded set
 */
export function seedWatermarkedIds(ids) {
	for (const raw of ids ?? []) {
		const id = Number(raw)
		if (Number.isInteger(id) && id > 0) {
			seededIds.add(id)
		}
	}
	decorateRows()
	return seededIds
}

/**
 * Rebuild the watermarked-id set from a folder listing's nodes (each carrying the
 * `is-watermarked` WebDAV property). Replaces the set so ids from the previously
 * viewed folder don't linger.
 * @param {object[]} nodes - the folder's Files `Node` objects
 * @return {Set<number>} the updated set (returned for tests)
 */
export function syncWatermarkedIds(nodes) {
	watermarkedIds.clear()
	for (const node of nodes) {
		const id = Number(node?.fileid ?? node?.id)
		if (isNodeWatermarked(node) && Number.isInteger(id) && id > 0) {
			watermarkedIds.add(id)
		}
	}
	return watermarkedIds
}

/**
 * Empty the watermarked-id set. Primarily a test seam.
 */
export function clearWatermarkedIds() {
	watermarkedIds.clear()
	seededIds.clear()
}

/** @return {Set<number>} the live set, for the authenticated bundle's own lookups */
export function watermarkedIdSet() {
	return watermarkedIds
}

/**
 * Record a file id as watermarked and repaint the badges. Used right after an
 * on-demand apply (where we already know the outcome) so the indicator appears
 * without waiting on a listing refresh or the DAV property.
 * @param {number} id - the file id that was just watermarked
 * @return {boolean} true when the id was newly added
 */
export function markWatermarked(id) {
	if (!Number.isInteger(id) || id <= 0 || watermarkedIds.has(id)) {
		return false
	}
	watermarkedIds.add(id)
	decorateRows()
	return true
}

/**
 * Forget a file id's watermarked status and repaint, so the badge clears immediately
 * after the watermark is removed.
 * @param {number} id - the file id whose watermark was removed
 * @return {boolean} true when the id was actually being tracked
 */
export function unmarkWatermarked(id) {
	if (!Number.isInteger(id) || !watermarkedIds.has(id)) {
		return false
	}
	watermarkedIds.delete(id)
	decorateRows()
	return true
}

/**
 * Draw (or remove) the badge next to the file name on every rendered row / grid
 * tile to match the current watermarked-id set. List and grid view share the same
 * `FileEntryName` markup, so the same target works in both. Idempotent, and strips
 * stale badges from rows the virtual scroller recycles for a different file.
 * @param {Document|HTMLElement} root - DOM root to decorate (defaults to document)
 */
export function decorateRows(root = document) {
	for (const row of root.querySelectorAll('[data-cy-files-list-row-fileid]')) {
		const id = Number(row.dataset.cyFilesListRowFileid)
		const existing = row.querySelector(`.${INDICATOR_CLASS}`)
		if (!watermarkedIds.has(id) && !seededIds.has(id)) {
			existing?.remove()
			continue
		}
		if (existing) {
			continue
		}
		// Sit on the same flex line as the file name / extension. The name cell
		// clips overflow and its inner link fills the cell, so a badge dropped
		// *inside* the name link (rather than after the cell) stays visible while
		// the name truncates. This element exists in both list and grid view.
		const target = row.querySelector('.files-list__row-name-link')
			?? row.querySelector('.files-list__row-name-text')?.parentElement
			?? row.querySelector('.files-list__row-name')
		if (!target) {
			continue
		}
		const badge = document.createElement('span')
		badge.className = INDICATOR_CLASS
		badge.title = t('files_watermark', 'Downloads and previews of this file are watermarked')
		badge.setAttribute('aria-label', badge.title)
		badge.innerHTML = INDICATOR_SVG
		// Inline, icon-only, non-interactive. flex:0 0 auto stops the badge from
		// being squeezed to zero width next to the (flex:1) file name.
		badge.style.display = 'inline-flex'
		badge.style.alignItems = 'center'
		badge.style.flex = '0 0 auto'
		badge.style.marginInlineStart = '6px'
		badge.style.color = 'var(--color-primary-element, #0082c9)'
		badge.style.pointerEvents = 'none'
		target.appendChild(badge)
	}
}

/**
 * Wire the indicator up to the live file list: keep the watermarked-id set in sync with
 * folder listings and node refreshes, and (re)draw badges as the virtual scroller and view
 * switches (list ⇄ grid) create rows.
 *
 * @param {object} [options] - options
 * @param {Function} [options.onListing] - called with a folder's contents after each
 *   listing is folded in. The authenticated bundle uses it for the REST fallback that
 *   covers a listing fetched before the DAV property was registered; the public bundle
 *   passes nothing, because that endpoint needs a session it does not have.
 */
export function startIndicator({ onListing } = {}) {
	// Full folder listing - the authoritative source of watermarked ids.
	subscribe('files:list:updated', ({ contents } = {}) => {
		if (!Array.isArray(contents)) {
			return
		}
		syncWatermarkedIds(contents)
		decorateRows()
		onListing?.(contents)
	})

	// A freshly-watermarked file's node is refreshed (property flips to '1') without a
	// full list reload - fold it into the set so its badge appears immediately. A removal
	// flips it to '0' and must take the id back out, otherwise the badge would survive
	// the restore. Only an explicit 0 clears: a node with the property *missing* is
	// unknown, not clean, and must leave the set alone.
	subscribe('files:node:updated', (node) => {
		const id = Number(node?.fileid ?? node?.id)
		if (!Number.isInteger(id) || id <= 0) {
			return
		}
		if (isNodeWatermarked(node)) {
			watermarkedIds.add(id)
			decorateRows()
		} else if (isNodeExplicitlyNotWatermarked(node)) {
			watermarkedIds.delete(id)
			decorateRows()
		}
	})

	// Rows are created/destroyed on scroll and when toggling list/grid view, so a
	// debounced observer re-applies the (cheap, id-set-driven) badges.
	if (typeof MutationObserver !== 'undefined' && document.body) {
		let timer = null
		const observer = new MutationObserver(() => {
			clearTimeout(timer)
			timer = setTimeout(() => decorateRows(), 50)
		})
		observer.observe(document.body, {
			childList: true,
			subtree: true,
			// The Files list virtual scroller recycles a <tr> for a different file
			// by patching its fileid attribute in place - no child add/remove - so a
			// childList-only observer never fires for it and a watermarked file that
			// scrolls into a recycled row silently loses its badge. Watching the
			// fileid attribute makes those in-place recycles re-trigger decoration.
			attributes: true,
			attributeFilter: ['data-cy-files-list-row-fileid'],
		})
	}
}

/**
 * Whether this bundle is running under Jest, where the observer and timers above must
 * not start on import - the specs drive the exported functions directly.
 * @return {boolean} true in the test runner
 */
export function inTestRunner() {
	return typeof process !== 'undefined' && process.env?.JEST_WORKER_ID !== undefined
}
