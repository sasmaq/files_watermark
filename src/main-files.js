import { createApp, h } from 'vue'
import { registerFileAction, FileAction, registerDavProperty } from '@nextcloud/files'
import { subscribe, emit } from '@nextcloud/event-bus'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import WatermarkModal from './components/WatermarkModal.vue'
import RemoveWatermarkModal from './components/RemoveWatermarkModal.vue'

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
 * Whether a node explicitly reports itself as *not* watermarked, as opposed to not
 * carrying the property at all. The distinction matters when clearing state after a
 * removal: a missing property means "unknown" (the hard-refresh race the REST reconcile
 * covers), and treating that as "not watermarked" would wipe ids we legitimately learned
 * from elsewhere.
 * @param {object} node - a Files `Node`
 * @return {boolean} true when the property is present and false
 */
export function isNodeExplicitlyNotWatermarked(node) {
	const value = node?.attributes?.[DAV_WATERMARKED_PROP]
	return value === 0 || value === '0'
}

/**
 * The effective watermark trigger for the current user, resolved server-side
 * (user → group → global → default) and handed over as initial state by
 * LoadAdditionalScriptsListener. Defaults to `on_demand` when the state is
 * absent so the manual actions degrade to available rather than silently gone.
 * @return {string} one of on_demand / on_upload / on_download / on_share
 */
export function getEffectiveTrigger() {
	return loadState('files_watermark', 'effective-trigger', 'on_demand')
}

/**
 * Whether watermarking is on-demand. The manual Apply (and Remove) file actions
 * are only offered in this mode; in on_upload / on_download / on_share the app
 * applies watermarks itself, so the actions are hidden.
 * @return {boolean} true when the effective trigger is `on_demand`
 */
export function isOnDemandTrigger() {
	return getEffectiveTrigger() === 'on_demand'
}

/**
 * Whether the selection is a single supported-MIME file (the conditions the
 * Apply and Remove actions share). Each action layers its own watermarked check
 * on top: Apply requires the file be *not* watermarked, Remove requires it be.
 * @param {object[]} files - selected Files `Node` objects
 * @return {boolean} true for a single file of a supported type in on_demand mode
 */
export function isSingleSupportedFile(files) {
	if (!isOnDemandTrigger()) {
		return false
	}
	if (files.length !== 1) {
		return false
	}
	return SUPPORTED_MIME.includes(files[0].mime)
}

/**
 * Whether the "Apply watermark" action should be offered for the selection:
 * a single supported-MIME file, in on_demand mode, that is not already
 * watermarked.
 *
 * "Watermarked" mirrors the on-screen indicator's source of truth exactly: the
 * WebDAV property PROPFIND delivers with the listing, OR the badge id-set (which
 * also holds files just watermarked via `markWatermarked` and any folded in by the
 * REST reconcile). So whenever a file shows the indicator, its Apply action is
 * hidden — even in the window where the node's DAV attribute is still stale.
 * @param {object[]} files - selected Files `Node` objects
 * @return {boolean} true when the action should be shown
 */
export function isApplyActionEnabled(files) {
	if (!isSingleSupportedFile(files)) {
		return false
	}
	const node = files[0]
	const id = Number(node?.fileid ?? node?.id)
	const watermarked = isNodeWatermarked(node)
		|| (Number.isInteger(id) && watermarkedIds.has(id))
	return !watermarked
}

/**
 * Whether the "Remove watermark" action should be offered: the exact mirror of
 * {@see isApplyActionEnabled}, differing only in requiring the file to *be*
 * watermarked. The two are mutually exclusive, so a row never shows both.
 * @param {object[]} files - selected Files `Node` objects
 * @return {boolean} true when the action should be shown
 */
export function isRemoveActionEnabled(files) {
	if (!isSingleSupportedFile(files)) {
		return false
	}
	const node = files[0]
	const id = Number(node?.fileid ?? node?.id)
	return isNodeWatermarked(node)
		|| (Number.isInteger(id) && watermarkedIds.has(id))
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
		const result = await mountModal(file.path, file.basename, file.size ?? 0)
		// The file was just watermarked, so record its id ourselves and redraw the
		// badge. We can't rely on the post-exec node refresh to re-deliver our custom
		// `is-watermarked` DAV property — it often doesn't — which is why the badge
		// used to be missing right after an apply. The set persists across the row's
		// re-render, so the observer keeps the badge painted afterwards.
		if (result === true) {
			markWatermarked(Number(file.fileid ?? file.id))
			// Stamp the watermarked state onto the node itself and notify the Files
			// app so it re-renders this row. Nextcloud memoizes a FileAction's
			// `enabled()` per node, so without this the just-watermarked file keeps
			// offering "Apply watermark" until a full folder reload; updating the node
			// forces `enabled()` to re-run (now false) and the action disappears.
			if (file.attributes) {
				file.attributes[DAV_WATERMARKED_PROP] = 1
			}
			emit('files:node:updated', file)
		}
		return result
	},
}))

// Undo/restore icon — a counter-clockwise arrow over a document, deliberately distinct
// from the Apply action's icon so the two are not confused in the menu.
const RESTORE_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13 3a9 9 0 0 0-9 9H1l3.9 3.9.1.1L9 12H6a7 7 0 1 1 2.1 5l-1.4 1.4A9 9 0 1 0 13 3Zm-1 5v5l4.3 2.5.7-1.2-3.5-2.1V8H12Z"/></svg>'

/**
 * Mounts RemoveWatermarkModal and returns a Promise that resolves with:
 *   true  — original was restored
 *   null  — user cancelled
 * @param {string} filePath - Path of the file to restore
 * @param {string} fileName - Display name shown in the modal
 * @return {Promise<boolean|null>} true when restored, null when cancelled
 */
function mountRemoveModal(filePath, fileName) {
	return new Promise((resolve) => {
		let removed = false
		const container = document.createElement('div')
		document.body.appendChild(container)

		const app = createApp({
			render() {
				return h(RemoveWatermarkModal, {
					filePath,
					fileName,
					onRemoved() {
						removed = true
						resolve(true)
					},
					onClose() {
						app.unmount()
						container.remove()
						if (!removed) {
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
	id: 'files_watermark_remove',
	displayName: () => t('files_watermark', 'Remove watermark'),
	iconSvgInline: () => RESTORE_ICON_SVG,
	enabled: isRemoveActionEnabled,
	async exec(file) {
		const result = await mountRemoveModal(file.path, file.basename)
		// Mirror of the apply path: drop the id, flip the node's own attribute and tell
		// the Files app, so the badge disappears and `enabled()` re-evaluates (this
		// action off, Apply back on) without waiting for a folder reload.
		if (result === true) {
			unmarkWatermarked(Number(file.fileid ?? file.id))
			if (file.attributes) {
				file.attributes[DAV_WATERMARKED_PROP] = 0
			}
			emit('files:node:updated', file)
		}
		return result
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
 * Fallback status lookup for a listing whose nodes carry NO `is-watermarked`
 * attribute at all — which happens when our DAV property was registered after the
 * Files app built its initial PROPFIND (a hard-refresh race), so the property is
 * simply missing rather than present-and-false. For those ids only, ask the REST
 * endpoint and fold any watermarked ones into the set. A present-but-0 value is
 * trusted and never re-queried, so the DAV property stays the primary source.
 * @param {object[]} nodes - the folder's Files `Node` objects
 * @return {Promise<void>}
 */
export async function reconcileMissingStatus(nodes) {
	const missing = []
	// Map ids back to their nodes so a discovered watermarked file can have its
	// status stamped onto the node and the Files app notified (below).
	const nodeById = new Map()
	for (const node of nodes) {
		const present = node?.attributes?.[DAV_WATERMARKED_PROP] !== undefined
		const id = Number(node?.fileid ?? node?.id)
		if (Number.isInteger(id) && id > 0) {
			nodeById.set(id, node)
			if (!present) {
				missing.push(id)
			}
		}
	}
	if (missing.length === 0) {
		return
	}

	try {
		const res = await axios.get(generateUrl('/apps/files_watermark/api/v1/watermarked'), {
			params: { ids: missing.join(',') },
		})
		let changed = false
		for (const raw of res?.data?.watermarked ?? []) {
			const id = Number(raw)
			if (Number.isInteger(id) && id > 0 && !watermarkedIds.has(id)) {
				watermarkedIds.add(id)
				changed = true
				// The Apply action's enabled() was already evaluated (and memoized by
				// Nextcloud) as true for this node during the initial render — because
				// the DAV property was missing at that point. Stamp the now-known status
				// onto the node and emit a node update so the action re-evaluates to
				// false and the button disappears; without this it lingers until the
				// next folder navigation. Same mechanism the post-apply path uses.
				const node = nodeById.get(id)
				if (node) {
					if (node.attributes) {
						node.attributes[DAV_WATERMARKED_PROP] = 1
					}
					emit('files:node:updated', node)
				}
			}
		}
		if (changed) {
			decorateRows()
		}
	} catch (e) {
		// Best-effort: the indicator must never block or break the file list.
	}
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
		// Safety net for the hard-refresh race where the DAV property wasn't part of
		// the initial PROPFIND; no-op (no request) once the property is delivered.
		reconcileMissingStatus(contents)
	})

	// A freshly-watermarked file's node is refreshed (property flips to '1') without a
	// full list reload — fold it into the set so its badge appears immediately. A removal
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
			// by patching its fileid attribute in place — no child add/remove — so a
			// childList-only observer never fires for it and a watermarked file that
			// scrolls into a recycled row silently loses its badge. Watching the
			// fileid attribute makes those in-place recycles re-trigger decoration.
			attributes: true,
			attributeFilter: ['data-cy-files-list-row-fileid'],
		})
	}
}

// Auto-start in the browser; skipped under the test runner so its observer/timers
// don't fire during unit tests (which drive the exported functions directly).
if (typeof process === 'undefined' || process.env?.JEST_WORKER_ID === undefined) {
	startIndicator()
}
