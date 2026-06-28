import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import AuditLog from '../components/AuditLog.vue'

// @nextcloud/vue, axios, router and l10n are stubbed via jest.config moduleNameMapper.

const SAMPLE = [
    { id: 1, createdAt: '2026-06-29 10:00:00', userId: 'alice', filePath: '/a.pdf', trigger: 'on_demand' },
    { id: 2, createdAt: '2026-06-29 11:00:00', userId: 'bob', filePath: '/b.png', trigger: 'on_upload' },
]

// A full page of entries — the "Next" button is only enabled when the
// returned page is full (entries.length >= limit).
const FULL_PAGE = Array.from({ length: 50 }, (_, i) => ({
    id: i + 1, createdAt: '2026-06-29 10:00:00', userId: `user${i}`, filePath: `/f${i}.pdf`, trigger: 'on_demand',
}))

describe('AuditLog', () => {
    beforeEach(() => {
        jest.clearAllMocks()
        axios.get.mockResolvedValue({ data: SAMPLE })
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
