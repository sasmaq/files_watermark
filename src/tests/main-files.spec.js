import {
	isNodeWatermarked,
	isApplyActionEnabled,
	isOnDemandTrigger,
	getEffectiveTrigger,
	isSingleSupportedFile,
	syncWatermarkedIds,
	decorateRows,
	clearWatermarkedIds,
} from '../main-files.js'
import { __setState, __resetState } from '@nextcloud/initial-state'

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
	})

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

		it('is disabled when the effective trigger is not on_demand', () => {
			for (const trigger of ['on_upload', 'on_download', 'on_share']) {
				__setState('files_watermark', 'effective-trigger', trigger)
				expect(isApplyActionEnabled([node()])).toBe(false)
			}
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
})
