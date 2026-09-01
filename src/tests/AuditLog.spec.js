import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { __setState, __resetState } from '@nextcloud/initial-state'
import AuditLog from '../components/AuditLog.vue'

// @nextcloud/vue, axios, router and l10n are stubbed via jest.config moduleNameMapper.

const SAMPLE = [
	{ id: 1, createdAt: '2026-06-29 10:00:00', userId: 'alice', filePath: '/a.pdf', trigger: 'on_demand' },
	{ id: 2, createdAt: '2026-06-29 11:00:00', userId: 'bob', filePath: '/b.png', trigger: 'on_upload' },
]

// A full page of entries - the "Next" button is only enabled when the
// returned page is full (entries.length >= limit).
const FULL_PAGE = Array.from({ length: 50 }, (_, i) => ({
	id: i + 1, createdAt: '2026-06-29 10:00:00', userId: `user${i}`, filePath: `/f${i}.pdf`, trigger: 'on_demand',
}))

describe('AuditLog', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		__resetState()
		axios.get.mockResolvedValue({ data: SAMPLE })
	})

	// The API has already converted every row into the instance's timezone, so the table is
	// the one place an admin can be told which clock they are reading. An hour on its own
	// cannot be reconciled with a mail timestamp or a server log.
	describe('timezone caption', () => {
		it('names the zone the server provided', async () => {
			__setState('files_watermark', 'time-zone', 'Asia/Aden')
			const wrapper = mount(AuditLog)
			await flushPromises()

			expect(wrapper.text()).toContain('Times shown in Asia/Aden')
		})

		it('says UTC when the page loaded without state, which is what the server falls back to', async () => {
			const wrapper = mount(AuditLog)
			await flushPromises()

			expect(wrapper.text()).toContain('Times shown in UTC')
		})

		it('is not shown over an empty log', async () => {
			axios.get.mockResolvedValue({ data: [] })
			const wrapper = mount(AuditLog)
			await flushPromises()

			expect(wrapper.text()).not.toContain('Times shown in')
		})
	})

	it('fetches the log on mount and renders a row per entry', async () => {
		const wrapper = mount(AuditLog)
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(
			'/nc/apps/files_watermark/api/v1/log',
			{ params: { limit: 50, offset: 0 } },
		)
		expect(wrapper.findAll('tbody tr')).toHaveLength(2)
		expect(wrapper.text()).toContain('alice')
		expect(wrapper.text()).toContain('/b.png')
	})

	describe('trigger badge', () => {
		it('gives each kind of row its own badge and its own glyph', async () => {
			const wrapper = mount(AuditLog)
			await flushPromises()

			const badges = wrapper.findAll('.trigger-badge')
			expect(badges).toHaveLength(2)
			expect(badges[0].classes()).toContain('trigger-badge--on_demand')
			expect(badges[1].classes()).toContain('trigger-badge--on_upload')
			expect(badges[0].text()).toBe('On demand')

			// The distinction must survive losing colour - a printed or screenshotted log,
			// or a reader who cannot tell the hues apart - so the glyphs have to differ too.
			const paths = wrapper.findAll('.trigger-icon path').map((p) => p.attributes('d'))
			expect(paths).toHaveLength(2)
			expect(paths[0]).not.toBe(paths[1])
		})

		/**
		 * Every kind of row gets its own hue *and* its own glyph. The pair matters: hue alone
		 * is gone in a printed or screenshotted log and to a reader who cannot tell the
		 * colours apart, and this is the table an auditor scans for "watermark removed".
		 */
		it('gives all four kinds their own class and their own glyph', async () => {
			const triggers = ['on_demand', 'on_upload', 'delivered', 'unmarked']
			axios.get.mockResolvedValue({
				data: triggers.map((trigger, i) => ({
					id: i + 1,
					createdAt: '2026-06-29 10:00:00',
					userId: 'alice',
					filePath: `/f${i}.pdf`,
					trigger,
				})),
			})
			const wrapper = mount(AuditLog)
			await flushPromises()

			const badges = wrapper.findAll('.trigger-badge')
			expect(badges).toHaveLength(4)
			triggers.forEach((trigger, i) => {
				expect(badges[i].classes()).toContain(`trigger-badge--${trigger}`)
			})

			const paths = wrapper.findAll('.trigger-icon path').map((p) => p.attributes('d'))
			expect(new Set(paths).size).toBe(4)
		})

		/**
		 * The two rows that are *about protection* share the app's document silhouette and
		 * differ only in what it carries - droplet for a mark placed, a bar for one taken
		 * off. That pairing is the point of the design, so it is asserted rather than left to
		 * whoever next edits the glyph table.
		 */
		it('draws marked and unmarked as the same document in two states', async () => {
			axios.get.mockResolvedValue({
				data: [
					{ id: 1, createdAt: '2026-06-29 10:00:00', userId: 'a', filePath: '/a.pdf', trigger: 'on_demand' },
					{ id: 2, createdAt: '2026-06-29 10:00:00', userId: 'a', filePath: '/a.pdf', trigger: 'unmarked' },
				],
			})
			const wrapper = mount(AuditLog)
			await flushPromises()

			const [marked, unmarked] = wrapper.findAll('.trigger-icon path').map((p) => p.attributes('d'))
			// The shared outline, and two different things inside it.
			const document = 'M6 2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z'
			expect(marked.startsWith(document)).toBe(true)
			expect(unmarked.startsWith(document)).toBe(true)
			expect(marked).not.toBe(unmarked)
		})

		it('renders a row whose trigger this version does not know', async () => {
			// An older or newer release writing a value we have no label or glyph for must
			// not blank the row: the log is evidence, and an unexplained row still happened.
			axios.get.mockResolvedValue({ data: [{ ...SAMPLE[0], trigger: 'from_the_future' }] })
			const wrapper = mount(AuditLog)
			await flushPromises()

			const badge = wrapper.find('.trigger-badge')
			expect(badge.text()).toBe('from_the_future')
			expect(badge.find('.trigger-icon path').attributes('d')).toBeTruthy()
		})
	})

	it('renders file paths left to right whatever the interface direction', async () => {
		// A path is structurally LTR. Left to inherit an RTL document direction, its
		// leading slash is a neutral at the start of the paragraph and is rendered at the
		// far end, so `/Documents/تقرير.pdf` reads as though the file were named backwards.
		axios.get.mockResolvedValue({
			data: [{ ...SAMPLE[0], filePath: '/Documents/تقرير.pdf' }],
		})
		const wrapper = mount(AuditLog)
		await flushPromises()

		expect(wrapper.find('.file-path').attributes('dir')).toBe('ltr')
	})

	it('shows an empty-state row when there are no entries', async () => {
		axios.get.mockResolvedValue({ data: [] })
		const wrapper = mount(AuditLog)
		await flushPromises()

		expect(wrapper.text()).toContain('No entries yet.')
	})

	it('shows an error note when the request fails', async () => {
		axios.get.mockRejectedValue({ response: { data: { error: 'Forbidden' } } })
		const wrapper = mount(AuditLog)
		await flushPromises()

		expect(wrapper.find('.nc-note-card').text()).toContain('Forbidden')
	})

	it('advances the offset and refetches when Next is clicked', async () => {
		axios.get.mockResolvedValue({ data: FULL_PAGE })
		const wrapper = mount(AuditLog)
		await flushPromises()

		// First (Previous) is disabled at offset 0; the second button is Next.
		const buttons = wrapper.findAll('button')
		await buttons[buttons.length - 1].trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith(
			'/nc/apps/files_watermark/api/v1/log',
			{ params: { limit: 50, offset: 50 } },
		)
	})

	it('resets the offset to 0 when the page size changes', async () => {
		axios.get.mockResolvedValue({ data: FULL_PAGE })
		const wrapper = mount(AuditLog)
		await flushPromises()

		// Move forward first so offset is non-zero.
		const buttons = wrapper.findAll('button')
		await buttons[buttons.length - 1].trigger('click')
		await flushPromises()

		const select = wrapper.find('select')
		select.element.value = '25'
		await select.trigger('change')
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith(
			'/nc/apps/files_watermark/api/v1/log',
			{ params: { limit: 25, offset: 0 } },
		)
	})
})
