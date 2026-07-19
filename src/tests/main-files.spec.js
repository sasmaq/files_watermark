import {
	isNodeWatermarked,
	isApplyActionEnabled,
	isOnDemandTrigger,
	getEffectiveTrigger,
	isSingleSupportedFile,
	syncWatermarkedIds,
	decorateRows,
	clearWatermarkedIds,
	markWatermarked,
	unmarkWatermarked,
	isRemoveActionEnabled,
	isNodeExplicitlyNotWatermarked,
	reconcileMissingStatus,
	startIndicator,
} from '../main-files.js'
import { __setState, __resetState } from '@nextcloud/initial-state'
import { emit, subscribe } from '@nextcloud/event-bus'
import axios from '@nextcloud/axios'

// @nextcloud/files, event-bus, router, l10n and initial-state are stubbed via
// jest.config moduleNameMapper.

const INDICATOR_SELECTOR = '.files-watermark-indicator'

/**
 * Build a fake Files `Node` carrying the WebDAV `is-watermarked` attribute that
 * PROPFIND delivers with the listing.
 * @param {object} props - overrides
 * @param {string} props.mime - the node's MIME type
 * @param {number} props.fileid - the node's file id
 * @param {boolean} props.watermarked - whether the property marks it watermarked
 * @return {object} a node-like object
 */
function node({ mime = 'application/pdf', fileid = 1, watermarked = false } = {}) {
	return {
		fileid,
		mime,
		// The webdav client parses the tag value to a number, so mirror that here.
		attributes: { 'is-watermarked': watermarked ? 1 : 0 },
	}
}

/**
 * Build a fake Files-list row/tile carrying the fileid hook and the `FileEntryName`
 * markup (name link + text) that both list and grid view render.
 * @param {number} fileid - the row's file id
 * @return {HTMLElement} the row element (attached to document.body)
 */
function addRow(fileid) {
	const row = document.createElement('tr')
	row.setAttribute('data-cy-files-list-row-fileid', String(fileid))
	row.dataset.cyFilesListRowFileid = String(fileid)
	const cell = document.createElement('td')
	cell.className = 'files-list__row-name'
	const link = document.createElement('a')
	link.className = 'files-list__row-name-link'
	const text = document.createElement('span')
	text.className = 'files-list__row-name-text'
	link.appendChild(text)
	cell.appendChild(link)
	row.appendChild(cell)
	document.body.appendChild(row)
	return row
}

describe('main-files', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		clearWatermarkedIds()
		__resetState()
		// Default the effective trigger to on_demand so existing assertions about
		// action availability hold; the trigger-gating suite overrides it per case.
		__setState('files_watermark', 'effective-trigger', 'on_demand')
		axios.get.mockReset()
		emit.mockClear()
		subscribe.mockClear()
	})

	/**
	 * Start the indicator and hand back the handler it registered for an event, so the
	 * subscription's behaviour can be driven directly.
	 * @param {string} event - the event-bus event name
	 * @return {Function} the registered handler
	 */
	function handlerFor(event) {
		startIndicator()
		return subscribe.mock.calls.find(([name]) => name === event)[1]
	}

	describe('isNodeWatermarked', () => {
		it('reads the WebDAV property delivered with the listing', () => {
			expect(isNodeWatermarked(node({ watermarked: true }))).toBe(true)
			expect(isNodeWatermarked(node({ watermarked: false }))).toBe(false)
		})

		it('accepts the numeric value the webdav client parses (parseTagValue)', () => {
			expect(isNodeWatermarked({ attributes: { 'is-watermarked': 1 } })).toBe(true)
			expect(isNodeWatermarked({ attributes: { 'is-watermarked': 0 } })).toBe(false)
		})

		it('also accepts a string value for safety', () => {
			expect(isNodeWatermarked({ attributes: { 'is-watermarked': '1' } })).toBe(true)
			expect(isNodeWatermarked({ attributes: { 'is-watermarked': '0' } })).toBe(false)
		})

		it('treats a missing property as not watermarked', () => {
			expect(isNodeWatermarked({})).toBe(false)
			expect(isNodeWatermarked(null)).toBe(false)
		})
	})

	describe('isApplyActionEnabled', () => {
		it('is enabled for a single supported file that is not watermarked', () => {
			expect(isApplyActionEnabled([node()])).toBe(true)
		})

		it('is disabled for unsupported MIME types', () => {
			expect(isApplyActionEnabled([node({ mime: 'text/plain' })])).toBe(false)
		})

		it('is disabled for multi-select', () => {
			expect(isApplyActionEnabled([node(), node({ fileid: 2 })])).toBe(false)
		})

		it('is disabled once the file is watermarked (from the PROPFIND property)', () => {
			expect(isApplyActionEnabled([node({ watermarked: true })])).toBe(false)
		})

		it('is disabled when the file carries the indicator badge, even if its DAV property is stale', () => {
			// Post-apply / reconcile path: the id is in the badge set (so the indicator
			// shows) while the node's is-watermarked attribute is still 0.
			markWatermarked(42)
			expect(isApplyActionEnabled([node({ fileid: 42, watermarked: false })])).toBe(false)
			// A different, un-badged file of the same type stays enabled.
			expect(isApplyActionEnabled([node({ fileid: 99, watermarked: false })])).toBe(true)
		})

		it('is disabled when the effective trigger is not on_demand', () => {
			for (const trigger of ['on_upload', 'on_download', 'on_share']) {
				__setState('files_watermark', 'effective-trigger', trigger)
				expect(isApplyActionEnabled([node()])).toBe(false)
			}
		})
	})

	describe('isRemoveActionEnabled', () => {
		it('is enabled only for a watermarked single supported file', () => {
			expect(isRemoveActionEnabled([node({ watermarked: true })])).toBe(true)
			expect(isRemoveActionEnabled([node({ watermarked: false })])).toBe(false)
		})

		it('is the exact mirror of Apply, so a row never offers both', () => {
			const watermarked = [node({ watermarked: true })]
			const clean = [node({ watermarked: false })]
			expect(isApplyActionEnabled(watermarked)).toBe(false)
			expect(isRemoveActionEnabled(watermarked)).toBe(true)
			expect(isApplyActionEnabled(clean)).toBe(true)
			expect(isRemoveActionEnabled(clean)).toBe(false)
		})

		it('is enabled from the badge set when the DAV property is still stale', () => {
			markWatermarked(42)
			expect(isRemoveActionEnabled([node({ fileid: 42, watermarked: false })])).toBe(true)
		})

		it('is disabled for unsupported MIME types and multi-select', () => {
			expect(isRemoveActionEnabled([node({ mime: 'text/plain', watermarked: true })])).toBe(false)
			expect(isRemoveActionEnabled([
				node({ watermarked: true }),
				node({ fileid: 2, watermarked: true }),
			])).toBe(false)
		})

		it('is disabled when the effective trigger is not on_demand', () => {
			for (const trigger of ['on_upload', 'on_download', 'on_share']) {
				__setState('files_watermark', 'effective-trigger', trigger)
				expect(isRemoveActionEnabled([node({ watermarked: true })])).toBe(false)
			}
		})
	})

	describe('unmarkWatermarked', () => {
		it('drops the id and repaints so the badge clears after a restore', () => {
			addRow(7)
			markWatermarked(7)
			expect(document.querySelectorAll(INDICATOR_SELECTOR)).toHaveLength(1)

			expect(unmarkWatermarked(7)).toBe(true)
			expect(document.querySelectorAll(INDICATOR_SELECTOR)).toHaveLength(0)
			// ...and the file becomes appliable again.
			expect(isApplyActionEnabled([node({ fileid: 7, watermarked: false })])).toBe(true)
		})

		it('reports false for an id it was not tracking', () => {
			expect(unmarkWatermarked(123)).toBe(false)
			expect(unmarkWatermarked(NaN)).toBe(false)
		})
	})

	describe('isNodeExplicitlyNotWatermarked', () => {
		it('distinguishes a present-and-false property from a missing one', () => {
			// A removal sets it to 0 (clear the id); a missing property means "unknown"
			// and must not clear anything the reconcile path discovered.
			expect(isNodeExplicitlyNotWatermarked({ attributes: { 'is-watermarked': 0 } })).toBe(true)
			expect(isNodeExplicitlyNotWatermarked({ attributes: { 'is-watermarked': '0' } })).toBe(true)
			expect(isNodeExplicitlyNotWatermarked({ attributes: { 'is-watermarked': 1 } })).toBe(false)
			expect(isNodeExplicitlyNotWatermarked({ attributes: {} })).toBe(false)
			expect(isNodeExplicitlyNotWatermarked({})).toBe(false)
		})
	})

	describe('effective trigger gating', () => {
		it('reads the trigger from initial state', () => {
			__setState('files_watermark', 'effective-trigger', 'on_upload')
			expect(getEffectiveTrigger()).toBe('on_upload')
		})

		it('defaults to on_demand when the state is absent', () => {
			__resetState()
			expect(getEffectiveTrigger()).toBe('on_demand')
			expect(isOnDemandTrigger()).toBe(true)
		})

		it('isOnDemandTrigger is true only for on_demand', () => {
			__setState('files_watermark', 'effective-trigger', 'on_demand')
			expect(isOnDemandTrigger()).toBe(true)
			__setState('files_watermark', 'effective-trigger', 'on_share')
			expect(isOnDemandTrigger()).toBe(false)
		})

		it('isSingleSupportedFile requires on_demand, a single file, and a supported MIME', () => {
			expect(isSingleSupportedFile([node()])).toBe(true)
			expect(isSingleSupportedFile([node(), node({ fileid: 2 })])).toBe(false)
			expect(isSingleSupportedFile([node({ mime: 'text/plain' })])).toBe(false)
			__setState('files_watermark', 'effective-trigger', 'on_upload')
			expect(isSingleSupportedFile([node()])).toBe(false)
		})
	})

	describe('syncWatermarkedIds', () => {
		it('keeps only the ids of watermarked nodes', () => {
			const ids = syncWatermarkedIds([
				node({ fileid: 1, watermarked: true }),
				node({ fileid: 2, watermarked: false }),
				node({ fileid: 3, watermarked: true }),
			])
			expect([...ids].sort()).toEqual([1, 3])
		})

		it('replaces the previous folder\'s ids on each call', () => {
			syncWatermarkedIds([node({ fileid: 1, watermarked: true })])
			const ids = syncWatermarkedIds([node({ fileid: 2, watermarked: true })])
			expect([...ids]).toEqual([2])
		})
	})

	describe('decorateRows', () => {
		it('badges only the rows whose id is watermarked', () => {
			addRow(1)
			addRow(2)
			addRow(3)
			syncWatermarkedIds([
				node({ fileid: 1, watermarked: true }),
				node({ fileid: 2, watermarked: false }),
				node({ fileid: 3, watermarked: true }),
			])

			decorateRows()

			expect(document.querySelector('[data-cy-files-list-row-fileid="1"]').querySelector(INDICATOR_SELECTOR)).not.toBeNull()
			expect(document.querySelector('[data-cy-files-list-row-fileid="2"]').querySelector(INDICATOR_SELECTOR)).toBeNull()
			expect(document.querySelector('[data-cy-files-list-row-fileid="3"]').querySelector(INDICATOR_SELECTOR)).not.toBeNull()
		})

		it('places the badge inside the name link (shared by list and grid view)', () => {
			addRow(1)
			syncWatermarkedIds([node({ fileid: 1, watermarked: true })])
			decorateRows()
			const link = document.querySelector('.files-list__row-name-link')
			expect(link.querySelector(INDICATOR_SELECTOR)).not.toBeNull()
		})

		it('is idempotent — never doubles the badge on a row', () => {
			addRow(1)
			syncWatermarkedIds([node({ fileid: 1, watermarked: true })])
			decorateRows()
			decorateRows()
			expect(document.querySelectorAll(INDICATOR_SELECTOR)).toHaveLength(1)
		})

		it('strips a stale badge when a recycled row is no longer watermarked', () => {
			// The virtual scroller reuses the same <tr>: it held watermarked file 1,
			// then is recycled for unwatermarked file 2.
			const row = addRow(1)
			syncWatermarkedIds([node({ fileid: 1, watermarked: true })])
			decorateRows()
			expect(row.querySelector(INDICATOR_SELECTOR)).not.toBeNull()

			row.setAttribute('data-cy-files-list-row-fileid', '2')
			row.dataset.cyFilesListRowFileid = '2'
			syncWatermarkedIds([node({ fileid: 2, watermarked: false })])
			decorateRows()

			expect(row.querySelector(INDICATOR_SELECTOR)).toBeNull()
		})

		it('carries the localized tooltip', () => {
			addRow(1)
			syncWatermarkedIds([node({ fileid: 1, watermarked: true })])
			decorateRows()
			expect(document.querySelector(INDICATOR_SELECTOR).title).toBe('This file is watermarked')
		})
	})

	describe('markWatermarked', () => {
		it('records the id and paints the badge (post-apply path)', () => {
			addRow(7)
			expect(markWatermarked(7)).toBe(true)
			expect(document.querySelector('[data-cy-files-list-row-fileid="7"]').querySelector(INDICATOR_SELECTOR)).not.toBeNull()
		})

		it('is idempotent and ignores invalid ids', () => {
			markWatermarked(7)
			expect(markWatermarked(7)).toBe(false)
			expect(markWatermarked(0)).toBe(false)
			expect(markWatermarked(NaN)).toBe(false)
		})
	})

	describe('reconcileMissingStatus', () => {
		// A node as delivered when the DAV property was NOT requested: no attribute.
		const bareNode = (fileid) => ({ fileid, mime: 'application/pdf', attributes: {} })

		it('queries the REST endpoint for nodes missing the property and badges the result', async () => {
			addRow(1)
			addRow(2)
			axios.get.mockResolvedValue({ data: { watermarked: [2] } })

			await reconcileMissingStatus([bareNode(1), bareNode(2)])

			expect(axios.get).toHaveBeenCalledWith(
				'/nc/apps/files_watermark/api/v1/watermarked',
				{ params: { ids: '1,2' } },
			)
			expect(document.querySelector('[data-cy-files-list-row-fileid="2"]').querySelector(INDICATOR_SELECTOR)).not.toBeNull()
			expect(document.querySelector('[data-cy-files-list-row-fileid="1"]').querySelector(INDICATOR_SELECTOR)).toBeNull()
		})

		it('stamps the node and emits a node update so the memoized Apply action re-hides', async () => {
			const n2 = bareNode(2)
			axios.get.mockResolvedValue({ data: { watermarked: [2] } })

			await reconcileMissingStatus([bareNode(1), n2])

			// The discovered node now reports watermarked, so a re-evaluated enabled()
			// (and the indicator) both see it, and the Files app is told to re-render.
			expect(n2.attributes['is-watermarked']).toBe(1)
			expect(emit).toHaveBeenCalledWith('files:node:updated', n2)
			expect(isApplyActionEnabled([n2])).toBe(false)
		})

		it('does not query when the property is present (present-but-0 is trusted)', async () => {
			await reconcileMissingStatus([node({ fileid: 1, watermarked: false })])
			expect(axios.get).not.toHaveBeenCalled()
		})

		it('never throws when the request fails', async () => {
			axios.get.mockRejectedValue(new Error('network'))
			await expect(reconcileMissingStatus([bareNode(1)])).resolves.toBeUndefined()
		})
	})

	describe('startIndicator', () => {
		it('clears the badge when a node reports itself no longer watermarked', () => {
			const onNodeUpdated = handlerFor('files:node:updated')
			addRow(7)
			markWatermarked(7)
			expect(document.querySelectorAll(INDICATOR_SELECTOR)).toHaveLength(1)

			// What the Remove action emits after a successful restore.
			onNodeUpdated({ fileid: 7, attributes: { 'is-watermarked': 0 } })

			expect(document.querySelectorAll(INDICATOR_SELECTOR)).toHaveLength(0)
		})

		it('leaves a tracked id alone when the node carries no status property', () => {
			// "Missing" means unknown (the hard-refresh race), not clean — clearing here
			// would undo what the REST reconcile just discovered.
			const onNodeUpdated = handlerFor('files:node:updated')
			addRow(7)
			markWatermarked(7)

			onNodeUpdated({ fileid: 7, attributes: {} })

			expect(document.querySelectorAll(INDICATOR_SELECTOR)).toHaveLength(1)
		})

		it('observes the fileid attribute so in-place recycled rows are re-decorated', () => {
			// The virtual scroller reuses a <tr> for a different file by patching its
			// fileid attribute rather than remounting; a childList-only observer would
			// miss that, so the observer must also watch the fileid attribute.
			const observe = jest.fn()
			const OriginalMO = global.MutationObserver
			global.MutationObserver = class {

				constructor(cb) { this.cb = cb }
				observe(...args) { observe(...args) }
				disconnect() {}

			}

			try {
				startIndicator()
			} finally {
				global.MutationObserver = OriginalMO
			}

			expect(observe).toHaveBeenCalledWith(
				document.body,
				expect.objectContaining({
					childList: true,
					subtree: true,
					attributes: true,
					attributeFilter: ['data-cy-files-list-row-fileid'],
				}),
			)
		})
	})
})
