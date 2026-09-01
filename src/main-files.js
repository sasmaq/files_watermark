import { createApp, h } from 'vue'
import { registerFileAction, FileAction } from '@nextcloud/files'
import { getCurrentUser } from '@nextcloud/auth'
import { emit } from '@nextcloud/event-bus'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import WatermarkModal from './components/WatermarkModal.vue'
import RemoveWatermarkModal from './components/RemoveWatermarkModal.vue'
import {
	DAV_WATERMARKED_PROP,
	appMarkSvg,
	decorateRows,
	inTestRunner,
	isNodeWatermarked,
	markWatermarked,
	startIndicator,
	unmarkWatermarked,
	watermarkedIdSet,
} from './indicator.js'

// The badge, its id set and the DAV property live in `indicator.js`, which the public
// share page's bundle also loads - see the note at the top of that file. Re-exported here
// because this module is the app's entry point for the Files app and its tests, and a
// caller should not have to know which half of the pair a helper ended up in.
export {
	clearWatermarkedIds,
	decorateRows,
	isNodeExplicitlyNotWatermarked,
	isNodeWatermarked,
	markWatermarked,
	startIndicator,
	syncWatermarkedIds,
	unmarkWatermarked,
} from './indicator.js'

// The live id set, for the two action predicates below: a file showing the badge must not
// also be offered "Apply watermark", so both read exactly what the badge reads.
const watermarkedIds = watermarkedIdSet()

const SUPPORTED_MIME = [
	'application/pdf',
	'image/jpeg',
	'image/png',
	'image/webp',
]

/**
 * The effective watermark trigger for the current user, resolved server-side
 * (the global policy, or the built-in default) and handed over as initial state by
 * LoadAdditionalScriptsListener. Defaults to `on_demand` when the state is
 * absent so the manual actions degrade to available rather than silently gone.
 * @return {string} either on_demand or on_upload
 */
export function getEffectiveTrigger() {
	return loadState('files_watermark', 'effective-trigger', 'on_demand')
}

/**
 * Whether watermarking is on-demand. The manual Apply and Remove actions are only offered
 * in this mode; under on_upload the app marks every supported upload itself, so a manual
 * action would only ever contradict the policy.
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
 * @param {object} [view] - the Files `View` the action is offered in; the trash bin offers neither
 * @return {boolean} true for a single file of a supported type in on_demand mode
 */
export function isSingleSupportedFile(files, view) {
	if (isReadOnlyView(view)) {
		return false
	}
	if (!isOnDemandTrigger()) {
		return false
	}
	if (files.length !== 1) {
		return false
	}
	return SUPPORTED_MIME.includes(files[0].mime)
}

/**
 * Views where a file is not somewhere a watermark policy can be changed.
 *
 * The trash bin is the one that matters. A deleted file keeps its id, so it keeps its
 * mark - the download of a trashed file is watermarked, and its preview always was - but
 * "Apply watermark" and "Remove watermark" have nothing to act on there: the node is not
 * at a path the API resolves, so both would offer a button whose only outcome is an error.
 * Deleting a file is not the moment to be asked about watermark policy either.
 *
 * The view is what the Files app hands `enabled()` for exactly this kind of question, and
 * it is the honest source: a trashed node is otherwise an ordinary file object, down to
 * its mime and its id.
 * @param {object} [view] - the Files `View` the action is being offered in
 * @return {boolean} true when the actions must stay hidden
 */
export function isReadOnlyView(view) {
	return view?.id === 'trashbin'
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
 * hidden - even in the window where the node's DAV attribute is still stale.
 * @param {object[]} files - selected Files `Node` objects
 * @param {object} [view] - the Files `View` the action is offered in; the trash bin offers neither
 * @return {boolean} true when the action should be shown
 */
export function isApplyActionEnabled(files, view) {
	if (!isSingleSupportedFile(files, view)) {
		return false
	}
	const node = files[0]
	const id = Number(node?.fileid ?? node?.id)
	const watermarked = isNodeWatermarked(node)
		|| (Number.isInteger(id) && watermarkedIds.has(id))
	return !watermarked
}

/**
 * Whether the current user owns this node.
 *
 * The Files client puts the owner's uid on every node it lists (`owner`, from the DAV
 * `owner-id` property), and a node in the user's own home reports them as the owner - so
 * this is false only for a received share.
 *
 * **A node that does not say who owns it is treated as not ours.** That is the safe
 * direction: the only thing gated on this is the Remove action, so an unknown owner hides
 * a button rather than offering one that the server will refuse.
 * @param {object} node - a Files `Node`
 * @return {boolean} true when the acting user is the node's owner
 */
export function isOwnedByCurrentUser(node) {
	const owner = node?.owner ?? node?.attributes?.['owner-id']
	const me = getCurrentUser()?.uid
	return Boolean(owner) && Boolean(me) && String(owner) === String(me)
}

/**
 * Whether the "Remove watermark" action should be offered: a single supported file, in
 * on_demand mode, that **is** watermarked and that the acting user **owns**.
 *
 * The ownership half is the one asymmetry between this and {@see isApplyActionEnabled},
 * and it is deliberate. A share recipient with edit permission passes every other check
 * here, and letting them take the watermark off the document they were given defeats the
 * whole point of it - whoever the shared copy would have named is exactly whoever wants it
 * to name nobody. The server refuses them either way; this stops the button being offered
 * so the refusal is not something a user has to discover.
 * @param {object[]} files - selected Files `Node` objects
 * @param {object} [view] - the Files `View` the action is offered in; the trash bin offers neither
 * @return {boolean} true when the action should be shown
 */
export function isRemoveActionEnabled(files, view) {
	if (!isSingleSupportedFile(files, view)) {
		return false
	}
	const node = files[0]
	if (!isOwnedByCurrentUser(node)) {
		return false
	}
	const id = Number(node?.fileid ?? node?.id)
	return isNodeWatermarked(node)
		|| (Number.isInteger(id) && watermarkedIds.has(id))
}

// The action-menu icon: the app mark, unsized on purpose - the menu sizes it, and
// `fill="currentColor"` lets it inherit the menu text colour (Nextcloud's
// `.icon-vue svg { fill: currentColor }` rule).
const APP_ICON_SVG = appMarkSvg('')

/**
 * Mounts WatermarkModal and returns a Promise that resolves with:
 *   true  - the file was marked
 *   null  - user cancelled before applying
 *
 * Keeping exec() awaiting this Promise lets Nextcloud Files show a spinner
 * on the file row and auto-refresh the file when exec resolves with true.
 * @param {string} filePath - Path of the file to watermark
 * @param {string} fileName - Display name shown in the modal
 * @return {Promise<boolean|null>} true when applied, null when cancelled
 */
function mountModal(filePath, fileName) {
	return new Promise((resolve) => {
		let watermarked = false
		const container = document.createElement('div')
		document.body.appendChild(container)

		const app = createApp({
			render() {
				return h(WatermarkModal, {
					filePath,
					fileName,
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
	// `title` - otherwise the long description would show as the button text.
	displayName: () => t('files_watermark', 'Apply watermark'),
	iconSvgInline: () => APP_ICON_SVG,
	enabled: isApplyActionEnabled,
	async exec(file) {
		const result = await mountModal(file.path, file.basename)
		// The file was just watermarked, so record its id ourselves and redraw the
		// badge. We can't rely on the post-exec node refresh to re-deliver our custom
		// `is-watermarked` DAV property - it often doesn't - which is why the badge
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

// Undo icon - a counter-clockwise arrow over a document, deliberately distinct from the
// Apply action's icon so the two are not confused in the menu.
const UNDO_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13 3a9 9 0 0 0-9 9H1l3.9 3.9.1.1L9 12H6a7 7 0 1 1 2.1 5l-1.4 1.4A9 9 0 1 0 13 3Zm-1 5v5l4.3 2.5.7-1.2-3.5-2.1V8H12Z"/></svg>'

/**
 * Mounts RemoveWatermarkModal and returns a Promise that resolves with:
 *   true  - the mark was removed
 *   null  - user cancelled
 * @param {string} filePath - Path of the file to unmark
 * @param {string} fileName - Display name shown in the modal
 * @return {Promise<boolean|null>} true when unmarked, null when cancelled
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
	iconSvgInline: () => UNDO_ICON_SVG,
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

// --- Status fallback for a listing without the DAV property ---------------

/**
 * How many ids one status request may carry.
 *
 * The ids travel in the query string, and a folder is as big as a user makes it: at
 * ~8 characters an id, a few hundred files is already several KB of URI, and the
 * default `LimitRequestLine` on Apache (which RHEL/Debian packages ship unchanged) is
 * 8190 bytes. Past that the server answers **414 before any of this app's code runs**,
 * the catch below swallows it, and every watermarked file in that folder silently loses
 * its badge and offers Apply instead of Remove. Chunking keeps each request far inside
 * the limit whatever the folder holds; the requests run in parallel, so a big folder
 * costs round trips, not latency.
 */
const STATUS_QUERY_CHUNK = 100

/**
 * Fallback status lookup for a listing whose nodes carry NO `is-watermarked`
 * attribute at all - which happens when the listing was fetched before our DAV property
 * was registered, so the property is simply missing rather than present-and-false. For
 * those ids only, ask the REST endpoint and fold any watermarked ones into the set. A
 * present-but-0 value is trusted and never re-queried, so the DAV property stays the
 * primary source.
 *
 * With the early `dav-property` bundle in place this should now find nothing to do on a
 * normal Files page: the property arrives with the first listing. It stays because it is
 * the only cover for a page that bundle does not reach, and it is what has to work when
 * it is needed - hence the chunking above.
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

	const chunks = []
	for (let i = 0; i < missing.length; i += STATUS_QUERY_CHUNK) {
		chunks.push(missing.slice(i, i + STATUS_QUERY_CHUNK))
	}

	// Settled, not all: one failed chunk must not discard the answers that arrived. A
	// folder where half the badges are right is strictly better than one where none are.
	const responses = await Promise.allSettled(chunks.map((chunk) =>
		axios.get(generateUrl('/apps/files_watermark/api/v1/watermarked'), {
			params: { ids: chunk.join(',') },
		}),
	))

	let changed = false
	for (const response of responses) {
		if (response.status !== 'fulfilled') {
			// Best-effort: the indicator must never block or break the file list.
			continue
		}
		for (const raw of response.value?.data?.watermarked ?? []) {
			const id = Number(raw)
			if (Number.isInteger(id) && id > 0 && !watermarkedIds.has(id)) {
				watermarkedIds.add(id)
				changed = true
				// The Apply action's enabled() was already evaluated (and memoized by
				// Nextcloud) as true for this node during the initial render - because
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
	}

	if (changed) {
		decorateRows()
	}
}

// Auto-start in the browser; skipped under the test runner so its observer/timers don't
// fire during unit tests (which drive the exported functions directly).
//
// `reconcileMissingStatus` is handed in rather than living in the indicator: it is the
// authenticated fallback for a listing that arrived without the DAV property, and it needs
// a session. The public bundle starts the same indicator with no fallback at all.
if (!inTestRunner()) {
	startIndicator({ onListing: reconcileMissingStatus })
}
