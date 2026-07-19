import { mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import WatermarkForm from '../components/WatermarkForm.vue'

// @nextcloud/vue components are stubbed via jest.config moduleNameMapper
// (src/tests/__mocks__/@nextcloud/vue-components/*).

/**
 * Drive the hidden file input the way the browser would after the user picks a file.
 * `files` is read-only on a real input, so it is defined onto the element directly.
 * @param {object} wrapper - the mounted wrapper
 * @param {File} file - the file to pick
 * @return {Promise<void>} resolves once the upload handler has settled
 */
async function pickFile(wrapper, file) {
	const input = wrapper.find('input[type="file"]')
	Object.defineProperty(input.element, 'files', { value: [file], writable: true })
	await input.trigger('change')
	// Let the upload promise (and its .finally) resolve before assertions run.
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
}

describe('WatermarkForm', () => {
	beforeEach(() => {
		axios.post.mockReset()
	})

	/**
	 * Mount WatermarkForm with default props merged with the given overrides.
	 * @param {object} [props] - Prop overrides
	 * @return {object} The mounted test-utils wrapper
	 */
	function mountForm(props = {}) {
		return mount(WatermarkForm, {
			props: {
				modelValue: {},
				isAdmin: false,
				saving: false,
				...props,
			},
			global: { stubs: { transition: true } },
		})
	}

	it('renders without errors', () => {
		const wrapper = mountForm()
		expect(wrapper.exists()).toBe(true)
	})

	it('hides the image upload section when type is text', () => {
		const wrapper = mountForm({ modelValue: { type: 'text' } })
		expect(wrapper.find('input[type="file"]').exists()).toBe(false)
	})

	describe('watermark image upload', () => {
		it('offers a file picker instead of a path field', () => {
			const wrapper = mountForm({ modelValue: { type: 'image' } })
			const input = wrapper.find('input[type="file"]')
			expect(input.exists()).toBe(true)
			// Only the types every render path can actually draw.
			expect(input.attributes('accept')).toBe('image/png,image/jpeg')
		})

		it('uploads the picked file and keeps only the returned reference', async () => {
			const reference = `${'a'.repeat(32)}.png`
			axios.post.mockResolvedValue({ data: { imagePath: reference } })

			const wrapper = mountForm({ modelValue: { type: 'image' } })
			await pickFile(wrapper, new File(['x'], 'logo.png', { type: 'image/png' }))

			const [url, body] = axios.post.mock.calls[0]
			expect(url).toContain('/apps/files_watermark/api/v1/image')
			expect(body).toBeInstanceOf(FormData)
			// The bytes go to the server; the form holds the opaque reference.
			expect(wrapper.emitted('update:modelValue').pop()[0].imagePath).toBe(reference)
		})

		it('rejects a non-PNG/JPEG file without uploading it', async () => {
			const wrapper = mountForm({ modelValue: { type: 'image' } })
			await pickFile(wrapper, new File(['x'], 'logo.svg', { type: 'image/svg+xml' }))

			expect(axios.post).not.toHaveBeenCalled()
			expect(wrapper.text()).toContain('PNG or JPEG')
		})

		it('rejects an oversized file without uploading it', async () => {
			const wrapper = mountForm({ modelValue: { type: 'image' } })
			const big = new File(['x'], 'logo.png', { type: 'image/png' })
			Object.defineProperty(big, 'size', { value: 3 * 1024 * 1024 })
			await pickFile(wrapper, big)

			expect(axios.post).not.toHaveBeenCalled()
			expect(wrapper.text()).toContain('smaller than')
		})

		it('surfaces a server-side rejection', async () => {
			axios.post.mockRejectedValue({ response: { data: { error: 'The image must be a PNG or JPEG file.' } } })

			const wrapper = mountForm({ modelValue: { type: 'image' } })
			await pickFile(wrapper, new File(['x'], 'logo.png', { type: 'image/png' }))

			expect(wrapper.text()).toContain('The image must be a PNG or JPEG file.')
		})
	})

	it('hides text-template section when type is image', () => {
		const wrapper = mountForm({ modelValue: { type: 'image' } })
		// text template input should not exist
		const inputs = wrapper.findAll('input[type="text"]')
		expect(inputs.length).toBe(0)
	})

	it('hides admin scope section for non-admin users', () => {
		const wrapper = mountForm({ isAdmin: false })
		expect(wrapper.text()).not.toContain('Where to apply')
	})

	it('shows admin scope section for admin users', () => {
		const wrapper = mountForm({ isAdmin: true })
		expect(wrapper.text()).toContain('Where to apply')
	})

	it('emits save event with form data when save button clicked', async () => {
		const wrapper = mountForm({ modelValue: { type: 'text', textTemplate: '{username}' } })
		await wrapper.find('.wm-save').trigger('click')
		expect(wrapper.emitted('save')).toBeTruthy()
		const [payload] = wrapper.emitted('save')[0]
		expect(payload.type).toBe('text')
	})

	it('emits update:modelValue when form data changes', async () => {
		const wrapper = mountForm({ modelValue: { type: 'text', opacity: 80 } })
		// Trigger a reactive change
		wrapper.vm.form.opacity = 50
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update:modelValue')
		expect(emitted).toBeTruthy()
		const lastEmit = emitted[emitted.length - 1][0]
		expect(lastEmit.opacity).toBe(50)
	})
})
