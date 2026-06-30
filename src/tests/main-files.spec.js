import axios from '@nextcloud/axios'
import {
	supportedFileIds,
	fetchWatermarkedIds,
	decorateRows,
	refreshIndicators,
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

describe('main-files watermarked indicator', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		document.body.innerHTML = ''
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
		it('returns the watermarked ids from the response', async () => {
			axios.get.mockResolvedValue({ data: { watermarked: [2, 5] } })

			const ids = await fetchWatermarkedIds([1, 2, 5])

			expect(axios.get).toHaveBeenCalledWith(
				'/nc/apps/files_watermark/api/v1/watermarked',
				{ params: { ids: '1,2,5' } },
			)
			expect(ids).toEqual([2, 5])
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
