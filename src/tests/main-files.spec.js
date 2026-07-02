import axios from '@nextcloud/axios'
import {
	supportedFileIds,
	fetchWatermarkedIds,
	decorateRows,
	refreshIndicators,
	isApplyActionEnabled,
	rememberWatermarked,
	clearWatermarkedIds,
} from '../main-files.js'

// @nextcloud/files, axios, router and l10n are stubbed via jest.config moduleNameMapper.

const INDICATOR_SELECTOR = '.files-watermark-indicator'

/**
 * Build a fake Files-list row carrying the data attribute the decorator keys on.
 * @param {number} fileid - the row's file id
 * @return {HTMLElement} the row element (already attached to document.body)
 */
function addRow(fileid) {
	const row = document.createElement('tr')
	row.setAttribute('data-cy-files-list-row-fileid', String(fileid))
	row.dataset.cyFilesListRowFileid = String(fileid)
	const name = document.createElement('td')
	name.className = 'files-list__row-name'
	row.appendChild(name)
	document.body.appendChild(row)
	return row
}

/**
 * Build a row that mirrors the real Nextcloud Files DOM: the name cell holds a
 * full-width link (`.files-list__row-name-link`) wrapping the name text. The
 * badge must land *inside* the link, not after the cell, or it is clipped.
 * @param {number} fileid - the row's file id
 * @return {HTMLElement} the row element (already attached to document.body)
 */
function addRealRow(fileid) {
	const row = addRow(fileid)
	const cell = row.querySelector('.files-list__row-name')
	const link = document.createElement('a')
	link.className = 'files-list__row-name-link'
	const text = document.createElement('span')
	text.className = 'files-list__row-name-text'
	link.appendChild(text)
	cell.appendChild(link)
	return row
}

describe('main-files watermarked indicator', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		document.body.innerHTML = ''
		clearWatermarkedIds()
	})

	describe('supportedFileIds', () => {
		it('keeps supported MIME types and drops the rest', () => {
			const nodes = [
				{ fileid: 1, mime: 'application/pdf' },
				{ fileid: 2, mime: 'image/png' },
				{ fileid: 3, mime: 'text/plain' },
				{ fileid: 4, mime: 'application/zip' },
			]
			expect(supportedFileIds(nodes)).toEqual([1, 2])
		})

		it('falls back to id and drops invalid ids', () => {
			const nodes = [
				{ id: 7, mime: 'image/jpeg' },
				{ fileid: 0, mime: 'image/jpeg' },
				{ fileid: NaN, mime: 'image/jpeg' },
			]
			expect(supportedFileIds(nodes)).toEqual([7])
		})
	})

	describe('fetchWatermarkedIds', () => {
		it('reads the ids from the OCS envelope the controller actually returns', async () => {
			// ApiController extends OCSController → { ocs: { data: { watermarked } } }.
			axios.get.mockResolvedValue({ data: { ocs: { data: { watermarked: [2, 5] } } } })

			const ids = await fetchWatermarkedIds([1, 2, 5])

			expect(axios.get).toHaveBeenCalledWith(
				'/nc/apps/files_watermark/api/v1/watermarked',
				{ params: { ids: '1,2,5' } },
			)
			expect(ids).toEqual([2, 5])
		})

		it('also accepts a plain (non-OCS) response shape', async () => {
			axios.get.mockResolvedValue({ data: { watermarked: [7] } })
			expect(await fetchWatermarkedIds([7])).toEqual([7])
		})

		it('skips the request and returns [] for no ids', async () => {
			const ids = await fetchWatermarkedIds([])
			expect(axios.get).not.toHaveBeenCalled()
			expect(ids).toEqual([])
		})

		it('no-ops to [] when the lookup fails', async () => {
			axios.get.mockRejectedValue(new Error('boom'))
			const ids = await fetchWatermarkedIds([1])
			expect(ids).toEqual([])
		})
	})

	describe('decorateRows', () => {
		it('renders the badge for watermarked rows only', () => {
			addRow(1)
			addRow(2)
			addRow(3)

			decorateRows([1, 3])

			expect(document.querySelector('[data-cy-files-list-row-fileid="1"]').querySelector(INDICATOR_SELECTOR)).not.toBeNull()
			expect(document.querySelector('[data-cy-files-list-row-fileid="2"]').querySelector(INDICATOR_SELECTOR)).toBeNull()
			expect(document.querySelector('[data-cy-files-list-row-fileid="3"]').querySelector(INDICATOR_SELECTOR)).not.toBeNull()
		})

		it('is idempotent — never doubles the badge on a row', () => {
			addRow(1)

			decorateRows([1])
			decorateRows([1])

			expect(document.querySelectorAll(INDICATOR_SELECTOR)).toHaveLength(1)
		})

		it('carries the localized tooltip', () => {
			addRow(1)
			decorateRows([1])
			expect(document.querySelector(INDICATOR_SELECTOR).title).toBe('This file is watermarked')
		})

		it('places the badge inside the name link so it is not clipped', () => {
			addRealRow(1)
			decorateRows([1])
			const link = document.querySelector('.files-list__row-name-link')
			// Must be a child of the visible flex link, not merely somewhere in the row.
			expect(link.querySelector(INDICATOR_SELECTOR)).not.toBeNull()
		})
	})

	describe('isApplyActionEnabled', () => {
		const pdf = { fileid: 1, mime: 'application/pdf' }

		it('is enabled for a single supported file that is not watermarked', () => {
			expect(isApplyActionEnabled([pdf])).toBe(true)
		})

		it('is disabled for unsupported MIME types', () => {
			expect(isApplyActionEnabled([{ fileid: 1, mime: 'text/plain' }])).toBe(false)
		})

		it('is disabled for multi-select', () => {
			expect(isApplyActionEnabled([pdf, { fileid: 2, mime: 'image/png' }])).toBe(false)
		})

		it('is disabled once the file id is known to be watermarked', () => {
			expect(isApplyActionEnabled([pdf])).toBe(true)
			rememberWatermarked([1])
			expect(isApplyActionEnabled([pdf])).toBe(false)
		})

		it('refreshIndicators feeds the cache so the action hides for watermarked rows', async () => {
			axios.get.mockResolvedValue({ data: { watermarked: [1] } })
			await refreshIndicators([pdf])
			expect(isApplyActionEnabled([pdf])).toBe(false)
		})
	})

	describe('refreshIndicators', () => {
		it('queries only supported ids and decorates the watermarked ones', async () => {
			addRow(1)
			addRow(2)
			addRow(3)
			axios.get.mockResolvedValue({ data: { watermarked: [2] } })

			const decorated = await refreshIndicators([
				{ fileid: 1, mime: 'application/pdf' },
				{ fileid: 2, mime: 'image/png' },
				{ fileid: 3, mime: 'text/plain' }, // unsupported — not queried
			])

			expect(axios.get).toHaveBeenCalledWith(
				'/nc/apps/files_watermark/api/v1/watermarked',
				{ params: { ids: '1,2' } },
			)
			expect(decorated).toEqual([2])
			expect(document.querySelector('[data-cy-files-list-row-fileid="2"]').querySelector(INDICATOR_SELECTOR)).not.toBeNull()
			expect(document.querySelector('[data-cy-files-list-row-fileid="1"]').querySelector(INDICATOR_SELECTOR)).toBeNull()
		})
	})
})
