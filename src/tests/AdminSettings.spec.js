import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import AdminSettings from '../components/AdminSettings.vue'
import WatermarkForm from '../components/WatermarkForm.vue'
import AuditLog from '../components/AuditLog.vue'

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

/**
 * The config endpoint's payload.
 * @param {Array} configs - Saved watermark configs
 * @return {object} An axios-shaped response
 */
function configResponse(configs) {
	return { data: { configs } }
}

describe('AdminSettings', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('loads the global config on mount and hands it to the form', async () => {
		mockGet(Promise.resolve(configResponse([GLOBAL_CONFIG])))
		const wrapper = mount(AdminSettings)
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(CONFIG_URL)
		const form = wrapper.findComponent(WatermarkForm)
		expect(form.exists()).toBe(true)
		expect(form.props('modelValue')).toMatchObject({ id: 7, textTemplate: '{username}' })
	})

	it('saves the config (with the existing id) and shows a success note', async () => {
		mockGet(Promise.resolve(configResponse([GLOBAL_CONFIG])))
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
		mockGet(Promise.resolve(configResponse([GLOBAL_CONFIG])))
		axios.post.mockRejectedValue({ response: { data: { error: 'Invalid color' } } })
		const wrapper = mount(AdminSettings)
		await flushPromises()

		wrapper.findComponent(WatermarkForm).vm.$emit('save', { type: 'text' })
		await flushPromises()

		expect(wrapper.find('.wm-status--error').text()).toContain('Invalid color')
	})

	describe('activity log', () => {
		const LOG_URL = '/nc/apps/files_watermark/api/v1/log'

		it('is collapsed on arrival, and not fetched at all', async () => {
			mockGet(Promise.resolve(configResponse([GLOBAL_CONFIG])))
			const wrapper = mount(AdminSettings)
			await flushPromises()

			expect(wrapper.findComponent(AuditLog).exists()).toBe(false)
			// The point of `v-if` over `v-show`: an unmounted log makes no request. A
			// hidden-but-mounted one would still cost every admin a hundred rows on load.
			expect(axios.get.mock.calls.map(([url]) => url)).not.toContain(LOG_URL)
		})

		it('offers a control that says what it will do', async () => {
			mockGet(Promise.resolve(configResponse([GLOBAL_CONFIG])))
			const wrapper = mount(AdminSettings)
			await flushPromises()

			const toggle = wrapper.find('.watermark-log__toggle')
			expect(toggle.text()).toContain('Show activity log')
			expect(toggle.attributes('aria-expanded')).toBe('false')
		})

		it('mounts the log when it is opened, and drops it again when closed', async () => {
			mockGet(Promise.resolve(configResponse([GLOBAL_CONFIG])))
			const wrapper = mount(AdminSettings)
			await flushPromises()

			await wrapper.find('.watermark-log__toggle').trigger('click')
			expect(wrapper.findComponent(AuditLog).exists()).toBe(true)
			expect(wrapper.find('.watermark-log__toggle').text()).toContain('Hide activity log')
			expect(wrapper.find('.watermark-log__toggle').attributes('aria-expanded')).toBe('true')

			await wrapper.find('.watermark-log__toggle').trigger('click')
			expect(wrapper.findComponent(AuditLog).exists()).toBe(false)
		})
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
