import { createApp, h } from 'vue'
import { registerFileAction, FileAction, registerDavProperty } from '@nextcloud/files'
import { subscribe } from '@nextcloud/event-bus'
import { t } from '@nextcloud/l10n'
import WatermarkModal from './components/WatermarkModal.vue'

const SUPPORTED_MIME = [
	'application/pdf',
	'image/jpeg',
	'image/png',
	'image/webp',
]

// WebDAV property served by our PROPFIND plugin. Requesting it here makes the Files
// client fetch it with every listing, so a node carries its watermarked status by
// the time its row renders — letting `enabled()` decide synchronously on the first
// (and, in Nextcloud, memoized) evaluation instead of racing an async lookup.
const DAV_WATERMARKED_PROP = 'is-watermarked'
registerDavProperty(`nc:${DAV_WATERMARKED_PROP}`, { nc: 'http://nextcloud.org/ns' })

/**
 * Whether a Files `Node` is already watermarked, read from the WebDAV property
 * delivered with the listing. The plugin returns '1' for watermarked, '0' otherwise
 * — but the webdav client parses tag values (`parseTagValue: true`), so the value
 * reaches us as the *number* 1/0, not a string. Accept both to be safe.
 * @param {object} node - a Files `Node`
 * @return {boolean} true when the node is marked watermarked
 */
export function isNodeWatermarked(node) {
	const value = node?.attributes?.[DAV_WATERMARKED_PROP]
	return value === 1 || value === '1'
}

/**
 * Whether the "Apply watermark" action should be offered for the selection:
 * a single supported-MIME file that is not already watermarked. The watermarked
 * status comes from the WebDAV property PROPFIND delivers with the listing, so
 * this decides synchronously without a backend round-trip.
 * @param {object[]} files - selected Files `Node` objects
 * @return {boolean} true when the action should be shown
 */
export function isApplyActionEnabled(files) {
	if (files.length !== 1) {
		return false
	}
	const file = files[0]
	if (!SUPPORTED_MIME.includes(file.mime)) {
		return false
	}
	return !isNodeWatermarked(file)
}

// Small badge SVG (distinct from the action icon: a filled tag/seal mark).
const INDICATOR_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.4 8.5 8 11 4.6-2.5 8-6 8-11V5l-8-3Zm-1.2 13.2-3.3-3.3 1.4-1.4 1.9 1.9 4.5-4.5 1.4 1.4-5.9 5.9Z"/></svg>'

// Inline content of img/app.svg — a filled document with a watermark droplet
// knocked out via the even-odd rule. `fill="currentColor"` lets it inherit the
// menu text colour (Nextcloud's `.icon-vue svg { fill: currentColor }` rule).
const APP_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd"><path d="M6 2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm6 7c-1.6 2-3 4.3-3 6a3 3 0 0 0 6 0c0-1.7-1.4-4-3-6Z"/></svg>'

/**
 * Mounts WatermarkModal and returns a Promise that resolves with:
 *   true  — watermark was applied successfully
 *   null  — user cancelled before applying
 *
 * Keeping exec() awaiting this Promise lets Nextcloud Files show a spinner
 * on the file row and auto-refresh the file when exec resolves with true.
 * @param {string} filePath - Path of the file to watermark
 * @param {string} fileName - Display name shown in the modal
 * @param {number} fileSize - File size in bytes, used for the time estimate
 * @return {Promise<boolean|null>} true when applied, null when cancelled
 */
function mountModal(filePath, fileName, fileSize = 0) {
	return new Promise((resolve) => {
		let watermarked = false
		const container = document.createElement('div')
		document.body.appendChild(container)

		const app = createApp({
			render() {
				return h(WatermarkModal, {
					filePath,
					fileName,
					fileSize,
					onWatermarked() {
						watermarked = true
						resolve(true)
					},
					onClose() {
						app.unmount()
						container.remove()
						if (!watermarked) {
							resolve(null)
						}
					},
				})
			},
		})
		app.mount(container)
	})
}

registerFileAction(new FileAction({
	id: 'files_watermark_apply',
	// Nextcloud uses `title` as the menu label when present and only falls back
	// to `displayName`, so the action name lives in `displayName` with no
	// `title` — otherwise the long description would show as the button text.
	displayName: () => t('files_watermark', 'Apply watermark'),
	iconSvgInline: () => APP_ICON_SVG,
	enabled: isApplyActionEnabled,
	async exec(file) {
		// Nextcloud refreshes the node when exec resolves true, which re-runs PROPFIND:
		// the `is-watermarked` property flips to '1', hiding this action and showing the
		// indicator below — no manual DOM update needed.
		return mountModal(file.path, file.basename, file.size ?? 0)
	},
}))

// --- Watermarked-file indicator -------------------------------------------
//
// Nextcloud's FileAction API can't render an icon-only, non-interactive badge in
// both list AND grid view: `inline` actions are drawn by NcActions with
// `force-name`, so they show a text label, and `renderInline` custom elements are
// list-view only (`gridMode ? [] : …`). So the badge is drawn directly onto the
// row/tile icon — `.files-list__row-icon`, the one element present in both views —
// driven entirely by the WebDAV `is-watermarked` property the listing already
// carries. No extra HTTP lookup, no text, works in grid.

// Marker class so the badge can be found / deduped / removed on a row or tile.
const INDICATOR_CLASS = 'files-watermark-indicator'

// File ids the current listing reports as watermarked, read from the DAV property.
const watermarkedIds = new Set()

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
		if (!watermarkedIds.has(id)) {
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
		badge.title = t('files_watermark', 'This file is watermarked')
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
 * Wire the indicator up to the live Files list: keep the watermarked-id set in sync
 * with folder listings and node refreshes, and (re)draw badges as the virtual
 * scroller and view switches (list ⇄ grid) create rows.
 */
export function startIndicator() {
	// Full folder listing — the authoritative source of watermarked ids.
	subscribe('files:list:updated', ({ contents } = {}) => {
		if (!Array.isArray(contents)) {
			return
		}
		syncWatermarkedIds(contents)
		decorateRows()
	})

	// A freshly-watermarked file's node is refreshed (property flips to '1') without
	// a full list reload — fold it into the set so its badge appears immediately.
	subscribe('files:node:updated', (node) => {
		const id = Number(node?.fileid ?? node?.id)
		if (isNodeWatermarked(node) && Number.isInteger(id) && id > 0) {
			watermarkedIds.add(id)
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
		observer.observe(document.body, { childList: true, subtree: true })
	}
}

// Auto-start in the browser; skipped under the test runner so its observer/timers
// don't fire during unit tests (which drive the exported functions directly).
if (typeof process === 'undefined' || process.env?.JEST_WORKER_ID === undefined) {
	startIndicator()
}
