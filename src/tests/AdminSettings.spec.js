import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import AdminSettings from '../components/AdminSettings.vue'
import WatermarkForm from '../components/WatermarkForm.vue'

// @nextcloud/vue, axios, router and l10n are stubbed via jest.config moduleNameMapper.

const CONFIG_URL = '/nc/apps/files_watermark/api/v1/config'

const GLOBAL_CONFIG = { id: 7, type: 'text', textTemplate: '{username}', opacity: 80 }

/**
 * Stub axios.get so the log endpoint resolves empty and the config endpoint
 * returns the supplied promise.
 * @param {Promise} config - Promise the config endpoint should resolve/reject with
 */
function mockGet(config) {
	axios.get.mockImplementation((url) => {
		if (url === CONFIG_URL) return config
		return Promise.resolve({ data: [] })
	})
}

describe('AdminSettings', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('loads the global config on mount and hands it to the form', async () => {
		mockGet(Promise.resolve({ data: [GLOBAL_CONFIG] }))
		const wrapper = mount(AdminSettings)
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(CONFIG_URL)
		const form = wrapper.findComponent(WatermarkForm)
		expect(form.exists()).toBe(true)
		expect(form.props('modelValue')).toMatchObject({ id: 7, textTemplate: '{username}' })
	})

	it('saves the config (with the existing id) and shows a success note', async () => {
		mockGet(Promise.resolve({ data: [GLOBAL_CONFIG] }))
		axios.post.mockResolvedValue({ data: { ...GLOBAL_CONFIG, opacity: 50 } })
		const wrapper = mount(AdminSettings)
		await flushPromises()

		wrapper.findComponent(WatermarkForm).vm.$emit('save', { type: 'text', opacity: 50 })
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			CONFIG_URL,
			expect.objectContaining({ type: 'text', opacity: 50, id: 7 }),
		)
		expect(wrapper.find('.wm-status--success').exists()).toBe(true)
	})

	it('surfaces a save error returned by the API', async () => {
		mockGet(Promise.resolve({ data: [GLOBAL_CONFIG] }))
		axios.post.mockRejectedValue({ response: { data: { error: 'Invalid color' } } })
		const wrapper = mount(AdminSettings)
		await flushPromises()

		wrapper.findComponent(WatermarkForm).vm.$emit('save', { type: 'text' })
		await flushPromises()

		expect(wrapper.find('.wm-status--error').text()).toContain('Invalid color')
	})

	it('treats a 404 on load as "no config yet" without showing an error', async () => {
		const notFound = Object.assign(new Error('Not Found'), { response: { status: 404 } })
		mockGet(Promise.reject(notFound))
		const wrapper = mount(AdminSettings)
		await flushPromises()

		expect(wrapper.find('.nc-note-card--error').exists()).toBe(false)
		expect(wrapper.findComponent(WatermarkForm).exists()).toBe(true)
	})
})
