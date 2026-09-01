import { subscribe } from '@nextcloud/event-bus'
import axios from '@nextcloud/axios'
import {
	clearWatermarkedIds,
	decorateRows,
	seedWatermarkedIds,
	startIndicator,
	syncWatermarkedIds,
} from '../indicator.js'

// @nextcloud/files, event-bus and l10n are stubbed via jest.config moduleNameMapper.

const INDICATOR_SELECTOR = '.files-watermark-indicator'

/**
 * A file row shaped like the one the file list renders - the same markup on the public
 * share page as in the Files app, which is the whole reason one indicator serves both.
 * @param {number} id - the file id
 * @return {HTMLElement} the row, already in the document
 */
function row(id) {
	const tr = document.createElement('tr')
	tr.setAttribute('data-cy-files-list-row-fileid', String(id))
	const link = document.createElement('a')
	link.className = 'files-list__row-name-link'
	tr.appendChild(link)
	document.body.appendChild(tr)
	return tr
}

/**
 * A listing node carrying the DAV property, as the public DAV server delivers it.
 * @param {number} id - the file id
 * @param {number|undefined} watermarked - the `is-watermarked` value, or undefined for absent
 * @return {object} a Files `Node`-shaped object
 */
function node(id, watermarked) {
	return { fileid: id, attributes: watermarked === undefined ? {} : { 'is-watermarked': watermarked } }
}

describe('the public share page indicator', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		clearWatermarkedIds()
		jest.clearAllMocks()
	})

	/**
	 * Start the indicator and hand back the handler it registered for `$event`, so the
	 * subscription can be driven directly - the event-bus stub records subscriptions
	 * rather than delivering them.
	 * @param {string} event - the event-bus event name
	 * @return {Function} the registered handler
	 */
	function handlerFor(event) {
		startIndicator()
		return subscribe.mock.calls.filter(([name]) => name === event).pop()[1]
	}

	it('badges the files the public listing reports as watermarked', () => {
		row(1)
		row(2)

		syncWatermarkedIds([node(1, 1), node(2, 0)])
		decorateRows()

		expect(document.querySelectorAll(INDICATOR_SELECTOR)).toHaveLength(1)
		expect(document.querySelector('[data-cy-files-list-row-fileid="1"] ' + INDICATOR_SELECTOR))
			.not.toBeNull()
	})

	/**
	 * The public property answers "will a download of this be watermarked", which on that
	 * server is wider than "is it marked" - with the public-link switch on it is true for
	 * every file behind the link. The client never learns which of the two reasons applies,
	 * and must not try to: it draws what the property says.
	 */
	it('badges a file the server reports without anyone having marked it', () => {
		row(9)

		syncWatermarkedIds([node(9, 1)])
		decorateRows()

		expect(document.querySelectorAll(INDICATOR_SELECTOR)).toHaveLength(1)
	})

	it('draws nothing when the listing carries no property at all', () => {
		// An older server, or one where the plugin is not registered. The badge is absent
		// rather than guessed at - and, unlike the Files app, there is no REST fallback to
		// reach for, because that endpoint needs a session this page does not have.
		row(4)

		syncWatermarkedIds([node(4, undefined)])
		decorateRows()

		expect(document.querySelectorAll(INDICATOR_SELECTOR)).toHaveLength(0)
	})

	it('follows the live list, so a visitor navigating into a folder gets fresh badges', () => {
		const onListing = handlerFor('files:list:updated')
		row(5)

		onListing({ contents: [node(5, 1)] })

		expect(document.querySelectorAll(INDICATOR_SELECTOR)).toHaveLength(1)
	})

	it('makes no request of its own', async () => {
		// The public bundle must not reach for this app's REST API: every endpoint it has
		// requires a session, so a call here would be a guaranteed 401 on every listing.
		const onListing = handlerFor('files:list:updated')
		row(6)

		// Node 7 carries no property at all - the case the authenticated bundle answers
		// with a REST call. Here it must simply stay unbadged.
		onListing({ contents: [node(6, 1), node(7, undefined)] })
		await Promise.resolve()

		expect(axios.get).not.toHaveBeenCalled()
	})

	describe('the ids the server rendered the page with', () => {
		/**
		 * The seed is this page's *primary* source, not a nicety: this app's script is
		 * emitted after the file list's, so the DAV property is registered too late for the
		 * first listing - and a single-file share never lists anything a second time.
		 */
		it('badges a row from the seed alone, with no listing at all', () => {
			row(57)

			seedWatermarkedIds([57])

			expect(document.querySelectorAll(INDICATOR_SELECTOR)).toHaveLength(1)
		})

		it('draws seeded rows that only appear later, as the file list mounts', () => {
			// The seed runs while the page is still a Vue app that has not rendered; the
			// observer is what paints when the rows finally arrive.
			seedWatermarkedIds([57])
			expect(document.querySelectorAll(INDICATOR_SELECTOR)).toHaveLength(0)

			row(57)
			decorateRows()

			expect(document.querySelectorAll(INDICATOR_SELECTOR)).toHaveLength(1)
		})

		/**
		 * The listing that arrives without the property replaces the id set with an empty
		 * one. If the seed lived in that set, the badge would appear and then vanish - which
		 * is worse than never appearing, because it looks like the file changed.
		 */
		it('survives a listing that carries no property', () => {
			row(57)
			seedWatermarkedIds([57])

			syncWatermarkedIds([node(57, undefined)])
			decorateRows()

			expect(document.querySelectorAll(INDICATOR_SELECTOR)).toHaveLength(1)
		})

		it('ignores anything that is not a usable file id', () => {
			expect(seedWatermarkedIds(['12', 0, -3, null, undefined, 'nope']).has(12)).toBe(true)
			expect(seedWatermarkedIds(undefined).size).toBe(1)
		})
	})
})
