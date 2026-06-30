import { createApp, h } from 'vue'
import { registerFileAction, FileAction } from '@nextcloud/files'
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

// Small badge SVG (distinct from the action icon: a filled tag/seal mark).
const INDICATOR_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.4 8.5 8 11 4.6-2.5 8-6 8-11V5l-8-3Zm-1.2 13.2-3.3-3.3 1.4-1.4 1.9 1.9 4.5-4.5 1.4 1.4-5.9 5.9Z"/></svg>'

// Inline content of img/app.svg — comments stripped for use as an inline SVG string.
const APP_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="17" x2="16" y2="11" stroke-opacity="0.6" stroke-width="1.2"/><line x1="8" y1="14" x2="13" y2="9" stroke-opacity="0.4" stroke-width="1.2"/><line x1="11" y1="17" x2="16" y2="13" stroke-opacity="0.4" stroke-width="1.2"/></svg>'

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
	displayName: () => t('files_watermark', 'Apply Watermark'),
	title: () => t('files_watermark', 'Embed identity information into this file as a visible watermark'),
	iconSvgInline: () => APP_ICON_SVG,
	enabled(files) {
		return files.length === 1 && SUPPORTED_MIME.includes(files[0].mime)
	},
	async exec(file) {
		const result = await mountModal(file.path, file.basename, file.size ?? 0)
		// The file was just watermarked — show the badge straight away without
		// waiting for the next list refresh.
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
		return res?.data?.watermarked ?? []
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
		// ship for a DOM-injected element.
		badge.style.display = 'inline-flex'
		badge.style.alignItems = 'center'
		badge.style.marginInlineStart = '6px'
		badge.style.color = 'var(--color-primary-element, #0082c9)'
		const target = row.querySelector('.files-list__row-name') ?? row
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
			decorateRows(await fetchWatermarkedIds(ids))
		}, 200)
	}

	const observer = new MutationObserver(schedule)
	observer.observe(document.body, { childList: true, subtree: true })
	schedule()
}

// Auto-start in the browser. Skipped under the test runner so the observer's
// timers don't fire spurious lookups during unit tests.
if (typeof process === 'undefined' || process.env?.JEST_WORKER_ID === undefined) {
	startIndicatorObserver()
}
