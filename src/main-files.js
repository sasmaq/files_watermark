import { createApp, h } from 'vue'
import { registerFileAction, FileAction, registerDavProperty } from '@nextcloud/files'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import WatermarkModal from './components/WatermarkModal.vue'

const SUPPORTED_MIME = [
	'application/pdf',
	'image/jpeg',
	'image/png',
	'image/webp',
]

// Marker class for the indicator badge so we can find / dedupe it on a row.
const INDICATOR_CLASS = 'files-watermark-indicator'

// WebDAV property served by our PROPFIND plugin. Requesting it here makes the Files
// client fetch it with every listing, so a node carries its watermarked status by
// the time its row renders — letting `enabled()` decide synchronously on the first
// (and, in Nextcloud, memoized) evaluation instead of racing an async lookup.
const DAV_WATERMARKED_PROP = 'is-watermarked'
registerDavProperty(`nc:${DAV_WATERMARKED_PROP}`, { nc: 'http://nextcloud.org/ns' })

/**
 * Whether a Files `Node` is already watermarked, read from the WebDAV property
 * delivered with the listing. The plugin returns '1' for watermarked, '0' otherwise.
 * @param {object} node - a Files `Node`
 * @return {boolean} true when the node is marked watermarked
 */
export function isNodeWatermarked(node) {
	return node?.attributes?.[DAV_WATERMARKED_PROP] === '1'
}

/**
 * Whether the "Apply watermark" action should be offered for the selection:
 * a single supported-MIME file that is not already watermarked.
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

// Inline content of img/app.svg — comments stripped for use as an inline SVG string.
// `fill="none"` is repeated on each shape (not just the root) because Nextcloud's
// `.icon-vue svg { fill: currentColor }` rule overrides the root attribute and would
// otherwise fill the document outline into a solid blob.
const APP_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path fill="none" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline fill="none" points="14 2 14 8 20 8"/><g fill="none" stroke-width="1.2" stroke-opacity="0.5"><line x1="6" y1="12" x2="12" y2="6"/><line x1="6" y1="16" x2="14" y2="8"/><line x1="9" y1="18" x2="17" y2="10"/><line x1="13" y1="18" x2="18" y2="13"/></g></svg>'

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
		// The file was just watermarked — badge the row straight away without waiting
		// for the next list refresh. The action itself hides once Nextcloud refreshes
		// the node (its `is-watermarked` WebDAV property flips to '1').
		if (result === true) {
			const id = Number(file.fileid ?? file.id)
			if (Number.isInteger(id) && id > 0) {
				decorateRows([id])
			}
		}
		return result
	},
}))

// --- Watermarked-file indicator -------------------------------------------

/**
 * Pick the file ids of supported-MIME nodes, dropping anything we never
 * watermark so we don't query the backend for ids that can't be watermarked.
 * @param {object[]} nodes - Files `Node` objects (need `mime` and `fileid`/`id`)
 * @return {number[]} positive integer file ids of supported nodes
 */
export function supportedFileIds(nodes) {
	return nodes
		.filter((n) => SUPPORTED_MIME.includes(n.mime))
		.map((n) => Number(n.fileid ?? n.id))
		.filter((id) => Number.isInteger(id) && id > 0)
}

/**
 * Ask the backend which of the given ids have ever been watermarked.
 * Never throws: a failed lookup resolves to [] so the file list is never
 * blocked by this best-effort decoration.
 * @param {number[]} ids - candidate file ids
 * @return {Promise<number[]>} watermarked file ids (subset of `ids`)
 */
export async function fetchWatermarkedIds(ids) {
	if (!ids || ids.length === 0) {
		return []
	}
	try {
		const res = await axios.get(
			generateUrl('/apps/files_watermark/api/v1/watermarked'),
			{ params: { ids: ids.join(',') } },
		)
		// ApiController returns a plain DataResponse ({ watermarked: [...] }).
		// Also accept the OCS envelope ({ ocs: { data: { watermarked } } }) so
		// the lookup survives the controller being switched to an OCSController.
		const data = res?.data
		return data?.watermarked ?? data?.ocs?.data?.watermarked ?? []
	} catch (e) {
		// Best-effort: swallow the error so the list keeps working.
		return []
	}
}

/**
 * Add the indicator badge to each row whose file id is watermarked. Idempotent:
 * a row that already carries the badge is left untouched.
 * @param {number[]} ids - watermarked file ids
 * @param {Document|HTMLElement} root - DOM root to search (defaults to document)
 */
export function decorateRows(ids, root = document) {
	for (const id of ids) {
		const row = root.querySelector(`[data-cy-files-list-row-fileid="${id}"]`)
		if (!row || row.querySelector(`.${INDICATOR_CLASS}`)) {
			continue
		}
		const badge = document.createElement('span')
		badge.className = INDICATOR_CLASS
		badge.title = t('files_watermark', 'This file is watermarked')
		badge.setAttribute('aria-label', badge.title)
		badge.innerHTML = INDICATOR_SVG
		// Inline styles keep the badge self-contained — no extra stylesheet to
		// ship for a DOM-injected element. flex:0 0 auto stops the badge from
		// being squeezed to zero width next to the (flex:1) file name.
		badge.style.display = 'inline-flex'
		badge.style.alignItems = 'center'
		badge.style.flex = '0 0 auto'
		badge.style.marginInlineStart = '6px'
		badge.style.color = 'var(--color-primary-element, #0082c9)'
		// Let clicks fall through to the row/name link underneath.
		badge.style.pointerEvents = 'none'
		// The name cell (`.files-list__row-name`) clips overflow and its inner
		// link fills the whole cell, so a badge appended *after* the link is
		// pushed past the edge and never shown. Drop it inside the name link
		// instead — the same flex line that carries the file name / extension —
		// where a flex:0 0 auto sibling stays visible while the name truncates.
		const target = row.querySelector('.files-list__row-name-link')
			?? row.querySelector('.files-list__row-name-text')?.parentElement
			?? row.querySelector('.files-list__row-name')
			?? row
		target.appendChild(badge)
	}
}

/**
 * Batch the visible supported nodes, look up their watermarked status and
 * decorate the matching rows.
 * @param {object[]} nodes - currently visible Files `Node` objects
 * @param {Document|HTMLElement} root - DOM root to decorate (defaults to document)
 * @return {Promise<number[]>} the watermarked ids that were decorated
 */
export async function refreshIndicators(nodes, root = document) {
	const ids = supportedFileIds(nodes)
	const watermarked = await fetchWatermarkedIds(ids)
	decorateRows(watermarked, root)
	return watermarked
}

/**
 * Collect the file ids of the rows currently rendered in the Files list.
 * @param {Document|HTMLElement} root - DOM root to scan
 * @return {number[]} visible file ids
 */
function visibleRowFileIds(root = document) {
	return [...root.querySelectorAll('[data-cy-files-list-row-fileid]')]
		.map((row) => Number(row.dataset.cyFilesListRowFileid))
		.filter((id) => Number.isInteger(id) && id > 0)
}

/**
 * Watch the Files list and decorate watermarked rows as they (re)render. The
 * Files app reuses the same table across folder navigation, so a debounced
 * batch keeps this to a single lookup per visible page.
 */
export function startIndicatorObserver() {
	if (typeof MutationObserver === 'undefined' || !document.body) {
		return
	}

	let timer = null
	const schedule = () => {
		clearTimeout(timer)
		timer = setTimeout(async () => {
			const ids = visibleRowFileIds()
			const watermarked = await fetchWatermarkedIds(ids)
			rememberWatermarked(watermarked)
			decorateRows(watermarked)
		}, 200)
	}

	const observer = new MutationObserver(schedule)
	observer.observe(document.body, { childList: true, subtree: true })
	schedule()
}

/**
 * Extract the numeric file id from a Files `Node`.
 * @param {object} node - a Files `Node`
 * @return {number} the file id, or NaN
 */
function nodeId(node) {
	return Number(node?.fileid ?? node?.id)
}

/**
 * Faithful shallow clone of a Files `Node`: a new object reference that keeps the
 * same prototype (so getters like `source` / `fileid` / `mime` still work) and all
 * own properties. Nextcloud's `files:node:updated` handler replaces the stored node
 * only when it receives a *different* reference for the same file id — that swap is
 * what makes the row re-render and re-evaluate `enabled()`.
 * @param {object} node - a Files `Node`
 * @return {object} a distinct clone of the node
 */
function cloneNode(node) {
	return Object.create(
		Object.getPrototypeOf(node),
		Object.getOwnPropertyDescriptors(node),
	)
}

/**
 * Watch the Files list contents (real `Node` objects) and, once their watermarked
 * status is known, hide the "Apply watermark" action on already-watermarked rows.
 *
 * `FileAction.enabled` is memoized by Nextcloud when a row first mounts — and rows
 * are reused across folder navigation — so populating the id cache alone does not
 * re-hide the action. Re-emitting `files:node:updated` for the affected nodes forces
 * Nextcloud to re-render those rows, which re-runs `enabled()` against the now-warm
 * cache. Only nodes newly discovered as watermarked are re-emitted, so this can't
 * loop if the update bounces back as another `files:list:updated`.
 */
export function startNodeListWatcher() {
	subscribe('files:list:updated', async (payload = {}) => {
		const { contents } = payload
		console.debug('[wm] files:list:updated', { keys: Object.keys(payload), contents: Array.isArray(contents) ? contents.length : contents })
		if (!Array.isArray(contents)) {
			return
		}
		const supported = contents.filter((n) => n && SUPPORTED_MIME.includes(n.mime))
		const ids = supported.map(nodeId).filter((id) => Number.isInteger(id) && id > 0)
		const watermarked = new Set(await fetchWatermarkedIds(ids))
		console.debug('[wm] supported ids', ids, 'watermarked', [...watermarked])
		if (watermarked.size === 0) {
			return
		}
		// Nodes we didn't already know about — the only ones that need a re-render.
		const newlyWatermarked = supported.filter(
			(n) => watermarked.has(nodeId(n)) && !watermarkedIds.has(nodeId(n)),
		)
		rememberWatermarked([...watermarked])
		console.debug('[wm] emitting node:updated for', newlyWatermarked.map(nodeId))
		for (const node of newlyWatermarked) {
			emit('files:node:updated', cloneNode(node))
		}
	})
}

// Auto-start in the browser. Skipped under the test runner so the observer's
// timers don't fire spurious lookups during unit tests.
if (typeof process === 'undefined' || process.env?.JEST_WORKER_ID === undefined) {
	startIndicatorObserver()
	startNodeListWatcher()
}
