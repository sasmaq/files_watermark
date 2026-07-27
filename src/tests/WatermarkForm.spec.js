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

	describe('where to apply', () => {
		/**
		 * The MIME checkboxes, in the order they render.
		 * @param {object} wrapper - the mounted wrapper
		 * @return {Array} the checkbox component wrappers
		 */
		function mimeBoxes(wrapper) {
			return wrapper.findAll('.wm-checks .checkbox-radio-switch input')
		}

		it('offers exactly the types the server supports', () => {
			// Free text here let an admin store a typo, which is a filter nothing can
			// match — a policy that silently watermarks nothing.
			const wrapper = mountForm({ isAdmin: true })
			expect(mimeBoxes(wrapper)).toHaveLength(4)
			const text = wrapper.text()
			for (const label of ['PDF', 'JPEG image', 'PNG image', 'WEBP image']) {
				expect(text).toContain(label)
			}
		})

		it('starts with nothing selected when the filter is blank', () => {
			const wrapper = mountForm({ isAdmin: true, modelValue: { mimeTypes: '' } })
			expect(mimeBoxes(wrapper).every((b) => !b.element.checked)).toBe(true)
		})

		it('reflects a stored filter, whitespace and all', () => {
			const wrapper = mountForm({ isAdmin: true, modelValue: { mimeTypes: 'application/pdf, image/png' } })
			const checked = mimeBoxes(wrapper).map((b) => b.element.checked)
			expect(checked).toEqual([true, false, true, false])
		})

		it('writes the selection back as a comma-separated list in canonical order', async () => {
			const wrapper = mountForm({ isAdmin: true, modelValue: { mimeTypes: '' } })
			// Tick WEBP first, then PDF: the stored order must not follow the clicks.
			await mimeBoxes(wrapper)[3].setValue(true)
			await mimeBoxes(wrapper)[0].setValue(true)

			expect(wrapper.vm.form.mimeTypes).toBe('application/pdf,image/webp')
		})

		it('unticking the last type restores "every supported file"', async () => {
			const wrapper = mountForm({ isAdmin: true, modelValue: { mimeTypes: 'image/png' } })
			await mimeBoxes(wrapper)[2].setValue(false)

			expect(wrapper.vm.form.mimeTypes).toBe('')
		})

		it('picks the folder tag from the server rather than a typed id', () => {
			// A tag *name* typed into the old text field was accepted and then made
			// every watermark request fail with "Tag id must be integer".
			const wrapper = mountForm({ isAdmin: true })
			expect(wrapper.findComponent({ name: 'NcSelectTags' }).exists()).toBe(true)
			expect(wrapper.findComponent({ name: 'NcSelectTags' }).props('multiple')).toBe(false)
		})

		it('hands the picker the stored tag id as a number', () => {
			const wrapper = mountForm({ isAdmin: true, modelValue: { folderTag: '42' } })
			expect(wrapper.findComponent({ name: 'NcSelectTags' }).props('modelValue')).toBe(42)
		})

		it('stores the picked tag id, and blank when it is cleared', async () => {
			const wrapper = mountForm({ isAdmin: true })
			const picker = wrapper.findComponent({ name: 'NcSelectTags' })

			await picker.vm.$emit('update:modelValue', 7)
			expect(wrapper.vm.form.folderTag).toBe('7')

			await picker.vm.$emit('update:modelValue', null)
			expect(wrapper.vm.form.folderTag).toBe('')
		})

		it('accepts a tag object from the picker as well as a bare id', async () => {
			const wrapper = mountForm({ isAdmin: true })
			await wrapper.findComponent({ name: 'NcSelectTags' })
				.vm.$emit('update:modelValue', { id: 13, displayName: 'Confidential' })

			expect(wrapper.vm.form.folderTag).toBe('13')
		})

		it('says the tag belongs on the folder, not on the files', () => {
			// The old help text claimed the opposite of what the server checks.
			const wrapper = mountForm({ isAdmin: true })
			expect(wrapper.text()).toContain('containing folder carries this tag')
		})
	})

	describe('PDF flattening', () => {
		it('omits the block entirely when the server has no rasteriser', () => {
			// Absent, not disabled: an admin should never see a setting this server
			// cannot honour, and a disabled control invites a support ticket.
			const wrapper = mountForm({ isAdmin: true, flattenAvailable: false })
			expect(wrapper.text()).not.toContain('Tamper resistance')
			expect(wrapper.find('#wm-flatten-dpi').exists()).toBe(false)
			expect(wrapper.find('.wm-flatten-toggle').exists()).toBe(false)
		})

		it('offers the toggle when the server can flatten', () => {
			const wrapper = mountForm({ isAdmin: true, flattenAvailable: true })
			expect(wrapper.text()).toContain('Tamper resistance')
			const toggle = wrapper.findComponent('.wm-flatten-toggle')
			expect(toggle.exists()).toBe(true)
			expect(toggle.props('type')).toBe('switch')
		})

		it('warns about the accessibility and size costs', () => {
			const wrapper = mountForm({ isAdmin: true, flattenAvailable: true })
			const text = wrapper.text()
			expect(text).toContain('screen-reader')
			expect(text).toContain('impractical, not impossible')
		})

		it('toggling the switch updates the form', async () => {
			const wrapper = mountForm({ isAdmin: true, flattenAvailable: true })
			expect(wrapper.vm.form.flattenPdf).toBe(false)

			await wrapper.findComponent('.wm-flatten-toggle')
				.vm.$emit('update:modelValue', true)

			expect(wrapper.vm.form.flattenPdf).toBe(true)
		})

		it('reveals the DPI control only once flattening is on', async () => {
			const wrapper = mountForm({ isAdmin: true, flattenAvailable: true })
			expect(wrapper.find('#wm-flatten-dpi').exists()).toBe(false)

			wrapper.vm.form.flattenPdf = true
			await wrapper.vm.$nextTick()

			expect(wrapper.find('#wm-flatten-dpi').exists()).toBe(true)
		})

		it('hides the block for a config that can never touch a PDF', () => {
			const wrapper = mountForm({
				isAdmin: true,
				flattenAvailable: true,
				modelValue: { mimeTypes: 'image/png, image/jpeg' },
			})
			expect(wrapper.text()).not.toContain('Tamper resistance')
		})

		it('shows the block when the type filter includes PDFs', () => {
			const wrapper = mountForm({
				isAdmin: true,
				flattenAvailable: true,
				modelValue: { mimeTypes: 'application/pdf, image/png' },
			})
			expect(wrapper.text()).toContain('Tamper resistance')
		})

		it('sends the flattening settings on save', async () => {
			const wrapper = mountForm({
				isAdmin: true,
				flattenAvailable: true,
				modelValue: { type: 'text', flattenPdf: true, flattenDpi: 200 },
			})
			await wrapper.find('.wm-save').trigger('click')

			const [payload] = wrapper.emitted('save')[0]
			expect(payload.flattenPdf).toBe(true)
			expect(payload.flattenDpi).toBe(200)
		})
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
