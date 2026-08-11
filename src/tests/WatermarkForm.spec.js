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

	describe('identity placeholders', () => {
		// {username} used to resolve to the display name, so the account name could not be
		// watermarked at all and the token said one thing while meaning another. Both are
		// offered now, and the form has to make clear which is which - otherwise an admin
		// picks by name and gets the other one.
		it('offers both the display name and the account name', () => {
			const chips = mountForm().findAll('.wm-chip').map((c) => c.text())
			expect(chips).toContain('{displayname}')
			expect(chips).toContain('{username}')
		})

		it('labels each identity chip with what it resolves to', () => {
			const chips = mountForm().findAll('.wm-chip')
			const titleOf = (token) => chips.find((c) => c.text() === token).attributes('title')

			expect(titleOf('{displayname}')).toContain('Display name')
			expect(titleOf('{displayname}')).toContain('John Doe')
			expect(titleOf('{username}')).toContain('Account name')
			expect(titleOf('{username}')).toContain('john.doe')
		})

		it('spells out the difference in the section help text', () => {
			expect(mountForm().text()).toContain('account name used to sign in')
		})

		it('defaults to the display name, which is what a person reads', () => {
			expect(mountForm().vm.form.textTemplate).toBe('{displayname} - {date}')
		})

		it('previews the two tokens as different values', () => {
			const wrapper = mountForm({ modelValue: { type: 'text', textTemplate: '{displayname}/{username}' } })
			expect(wrapper.find('.wm-preview__svg text').text()).toBe('John Doe/john.doe')
		})
	})

	// The preview is the contract the renderers have to match, so what decides its base
	// direction is a behaviour and not a detail. The rule is the shaper's own: the
	// direction comes from the *text*, never from the interface language.
	//
	// This matters because the browser is a competent bidi engine and the renderers are
	// not obviously so - an Arabic template looks right in the preview the moment it is
	// typed, whether or not anything downstream can reproduce it.
	describe('preview direction', () => {
		const previewText = (wrapper) => wrapper.find('.wm-preview__svg text')

		afterEach(() => {
			document.documentElement.removeAttribute('dir')
		})

		it('draws a Latin template left to right', () => {
			const wrapper = mountForm({ modelValue: { type: 'text', textTemplate: 'Confidential' } })
			expect(previewText(wrapper).attributes('direction')).toBe('ltr')
		})

		it('draws an Arabic template right to left', () => {
			const wrapper = mountForm({ modelValue: { type: 'text', textTemplate: 'سري' } })
			expect(previewText(wrapper).attributes('direction')).toBe('rtl')
		})

		it('takes the direction from the first strong character, as the shaper does', () => {
			// Com\Tecnick\Unicode\Bidi resolves the paragraph direction from the first
			// strong character, so a template opening in Latin is an LTR paragraph however
			// much Arabic follows it - and the preview has to agree, or it shows an
			// admin a different arrangement from the one that gets stamped into the file.
			const wrapper = mountForm({ modelValue: { type: 'text', textTemplate: 'Confidential سري' } })
			expect(previewText(wrapper).attributes('direction')).toBe('ltr')
		})

		it('ignores digits and punctuation, which are not strong characters', () => {
			const wrapper = mountForm({ modelValue: { type: 'text', textTemplate: '2026-07-31 - سري' } })
			expect(previewText(wrapper).attributes('direction')).toBe('rtl')
		})

		it('does not follow the interface language', () => {
			// The whole point of pinning it. An Arabic-speaking admin and an English-speaking
			// one have to be shown the same picture of the same stored template, because
			// only one of the two can be what the renderer produces.
			document.documentElement.setAttribute('dir', 'rtl')
			const wrapper = mountForm({ modelValue: { type: 'text', textTemplate: 'Confidential' } })

			expect(previewText(wrapper).attributes('direction')).toBe('ltr')
		})

		it('keeps the page mock-up itself left to right', () => {
			// The faux document lines and the logo box are a picture of a page, not
			// interface text; flipping them under an RTL interface would show a mirrored
			// document that no renderer produces.
			document.documentElement.setAttribute('dir', 'rtl')
			const wrapper = mountForm({ modelValue: { type: 'text', textTemplate: 'سري' } })

			expect(wrapper.find('.wm-preview__svg').attributes('dir')).toBe('ltr')
		})

		// The rotation convention is pinned on the server by
		// testPositiveRotationTiltsTheTextUphill, and the preview's
		// patternTransform="rotate(-rotation)" is the other half of that contract. It is
		// the single most likely place to reintroduce the illegible-watermark bug, so:
		// reversing the reading direction must not touch the geometry. It is the glyph
		// order inside the line that reverses, not the line the tiles are laid along.
		it('lays the tile lattice at the same angle whatever the script', () => {
			const angleFor = (textTemplate) => mountForm({ modelValue: { type: 'text', rotation: 45, textTemplate } })
				.find('pattern').attributes('patternTransform')

			expect(angleFor('سري')).toBe(angleFor('Confidential'))
			expect(angleFor('سري')).toBe('rotate(-45)')
		})
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

	it('shows admin scope section for admin users once advanced options are open', async () => {
		const wrapper = await mountAdvanced()
		expect(wrapper.text()).toContain('Where to apply')
	})

	/**
	 * Mount as an admin and open the advanced options, which is where the scope
	 * controls live. Opening is a no-op when `modelValue` already narrows the policy,
	 * since the switch starts on in that case.
	 * @param {object} [modelValue] - the stored config the form starts from
	 * @return {Promise<object>} the mounted wrapper, advanced options showing
	 */
	async function mountAdvanced(modelValue = {}) {
		const wrapper = mountForm({ isAdmin: true, modelValue })
		await wrapper.find('.wm-advanced input').setValue(true)
		return wrapper
	}

	describe('advanced options switch', () => {
		it('keeps the scope section off the page until it is switched on', async () => {
			const wrapper = mountForm({ isAdmin: true })
			expect(wrapper.text()).not.toContain('Where to apply')

			await wrapper.find('.wm-advanced input').setValue(true)
			expect(wrapper.text()).toContain('Where to apply')
		})

		it('is not offered to non-admins, who have no scope section to reveal', () => {
			const wrapper = mountForm({ isAdmin: false })
			expect(wrapper.find('.wm-advanced').exists()).toBe(false)
		})

		it('starts on when the stored policy is already narrowed', () => {
			// Otherwise a policy that only marks PDFs would open with nothing on screen
			// saying why - the filter would be invisible and still in force.
			for (const modelValue of [{ mimeTypes: 'application/pdf' }, { folderTag: '42' }]) {
				const wrapper = mountForm({ isAdmin: true, modelValue })
				expect(wrapper.find('.wm-advanced input').element.checked).toBe(true)
				expect(wrapper.text()).toContain('Where to apply')
			}
		})

		it('leaves the stored filters alone when it is switched back off', async () => {
			// Hiding a control is not clearing it: switching off must not quietly widen
			// the policy to every file.
			const wrapper = mountForm({ isAdmin: true, modelValue: { mimeTypes: 'image/png', folderTag: '42' } })
			await wrapper.find('.wm-advanced input').setValue(false)

			expect(wrapper.text()).not.toContain('Where to apply')
			expect(wrapper.vm.form.mimeTypes).toBe('image/png')
			expect(wrapper.vm.form.folderTag).toBe('42')
		})
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

		it('offers exactly the types the server supports', async () => {
			// Free text here let an admin store a typo, which is a filter nothing can
			// match - a policy that silently watermarks nothing.
			const wrapper = await mountAdvanced()
			expect(mimeBoxes(wrapper)).toHaveLength(4)
			const text = wrapper.text()
			for (const label of ['PDF', 'JPEG image', 'PNG image', 'WEBP image']) {
				expect(text).toContain(label)
			}
		})

		it('starts with nothing selected when the filter is blank', async () => {
			const wrapper = await mountAdvanced({ mimeTypes: '' })
			expect(mimeBoxes(wrapper).every((b) => !b.element.checked)).toBe(true)
		})

		it('reflects a stored filter, whitespace and all', async () => {
			const wrapper = await mountAdvanced({ mimeTypes: 'application/pdf, image/png' })
			const checked = mimeBoxes(wrapper).map((b) => b.element.checked)
			expect(checked).toEqual([true, false, true, false])
		})

		it('writes the selection back as a comma-separated list in canonical order', async () => {
			const wrapper = await mountAdvanced({ mimeTypes: '' })
			// Tick WEBP first, then PDF: the stored order must not follow the clicks.
			await mimeBoxes(wrapper)[3].setValue(true)
			await mimeBoxes(wrapper)[0].setValue(true)

			expect(wrapper.vm.form.mimeTypes).toBe('application/pdf,image/webp')
		})

		it('unticking the last type restores "every supported file"', async () => {
			const wrapper = await mountAdvanced({ mimeTypes: 'image/png' })
			await mimeBoxes(wrapper)[2].setValue(false)

			expect(wrapper.vm.form.mimeTypes).toBe('')
		})

		it('picks the folder tag from the server rather than a typed id', async () => {
			// A tag *name* typed into the old text field was accepted and then made
			// every watermark request fail with "Tag id must be integer".
			const wrapper = await mountAdvanced()
			expect(wrapper.findComponent({ name: 'NcSelectTags' }).exists()).toBe(true)
			expect(wrapper.findComponent({ name: 'NcSelectTags' }).props('multiple')).toBe(false)
		})

		it('hands the picker the stored tag id as a number', async () => {
			const wrapper = await mountAdvanced({ folderTag: '42' })
			expect(wrapper.findComponent({ name: 'NcSelectTags' }).props('modelValue')).toBe(42)
		})

		it('stores the picked tag id, and blank when it is cleared', async () => {
			const wrapper = await mountAdvanced()
			const picker = wrapper.findComponent({ name: 'NcSelectTags' })

			await picker.vm.$emit('update:modelValue', 7)
			expect(wrapper.vm.form.folderTag).toBe('7')

			await picker.vm.$emit('update:modelValue', null)
			expect(wrapper.vm.form.folderTag).toBe('')
		})

		it('accepts a tag object from the picker as well as a bare id', async () => {
			const wrapper = await mountAdvanced()
			await wrapper.findComponent({ name: 'NcSelectTags' })
				.vm.$emit('update:modelValue', { id: 13, displayName: 'Confidential' })

			expect(wrapper.vm.form.folderTag).toBe('13')
		})

		it('says the tag belongs on the folder, not on the files', async () => {
			// The old help text claimed the opposite of what the server checks.
			const wrapper = await mountAdvanced()
			expect(wrapper.text()).toContain('containing folder carries this tag')
		})
	})

	describe('share switches', () => {
		/**
		 * One of the two share checkboxes, or null when it is not offered.
		 * @param {object} wrapper - the mounted wrapper
		 * @param {string} which - 'internal' or 'external'
		 * @return {object|null} the input wrapper
		 */
		function shareBox(wrapper, which) {
			const box = wrapper.find(`.wm-share-${which} input`)
			return box.exists() ? box : null
		}

		it('is admin-only, like the rest of the server-wide policy', () => {
			expect(shareBox(mountForm({ isAdmin: false }), 'internal')).toBeNull()
			expect(shareBox(mountForm({ isAdmin: true }), 'internal')).not.toBeNull()
		})

		it('defaults to off, matching the columns', () => {
			// An upgrade must not start watermarking - and refusing - files that were being
			// handed over cleanly the day before.
			const wrapper = mountForm({ isAdmin: true })
			expect(shareBox(wrapper, 'internal').element.checked).toBe(false)
			expect(shareBox(wrapper, 'external').element.checked).toBe(false)
			expect(wrapper.vm.form.watermarkInternalShares).toBe(false)
			expect(wrapper.vm.form.watermarkExternalShares).toBe(false)
		})

		it('reflects stored values independently', () => {
			const wrapper = mountForm({
				isAdmin: true,
				modelValue: { watermarkInternalShares: true, watermarkExternalShares: false },
			})
			expect(shareBox(wrapper, 'internal').element.checked).toBe(true)
			expect(shareBox(wrapper, 'external').element.checked).toBe(false)
		})

		it('is offered under either trigger, being policy about the fetch rather than a trigger', () => {
			for (const trigger of ['on_demand', 'on_upload']) {
				expect(shareBox(mountForm({ isAdmin: true, modelValue: { trigger } }), 'external')).not.toBeNull()
			}
		})

		it('sends both switches in the payload', async () => {
			const wrapper = mountForm({ isAdmin: true })
			await shareBox(wrapper, 'internal').setValue(true)
			await shareBox(wrapper, 'external').setValue(true)
			await wrapper.find('.wm-save').trigger('click')

			const [payload] = wrapper.emitted('save')[0]
			expect(payload.watermarkInternalShares).toBe(true)
			expect(payload.watermarkExternalShares).toBe(true)
		})

		it('leaves the other one alone', async () => {
			const wrapper = mountForm({ isAdmin: true })
			await shareBox(wrapper, 'external').setValue(true)

			expect(wrapper.vm.form.watermarkExternalShares).toBe(true)
			expect(wrapper.vm.form.watermarkInternalShares).toBe(false)
		})

		it('says that a file it cannot watermark is refused rather than served clean', () => {
			// The one surprise worth spelling out on the page: these switches put files that
			// nobody marked under the app's deny-rather-than-leak rule.
			expect(mountForm({ isAdmin: true }).text()).toContain('is refused rather than handed over clean')
		})
	})

	describe('delivery audit switch', () => {
		/**
		 * The "record every download" checkbox, or null when it is not offered.
		 * @param {object} wrapper - the mounted wrapper
		 * @return {object|null} the input wrapper
		 */
		function auditBox(wrapper) {
			const box = wrapper.find('.wm-audit input')
			return box.exists() ? box : null
		}

		it('is offered under both triggers, which both render per fetch', () => {
			// It used to be hidden for these two, back when they burned the watermark into
			// the stored file and had nothing per-download to record. Every marked file is
			// now rendered on every fetch whichever trigger marked it.
			for (const trigger of ['on_demand', 'on_upload']) {
				expect(auditBox(mountForm({ modelValue: { trigger } }))).not.toBeNull()
			}
		})

		it('defaults to on, matching the column default', () => {
			const wrapper = mountForm({ modelValue: { trigger: 'on_demand' } })
			expect(auditBox(wrapper).element.checked).toBe(true)
			expect(wrapper.vm.form.logDelivery).toBe(true)
		})

		// The three below drive the switch to **false**, which is the only direction that
		// proves anything now that the default is true: setting it to true would pass on a
		// form that ignored the stored value entirely.
		it('reflects a stored value', () => {
			const wrapper = mountForm({ modelValue: { trigger: 'on_upload', logDelivery: false } })
			expect(auditBox(wrapper).element.checked).toBe(false)
		})

		it('writes the change back to the form', async () => {
			const wrapper = mountForm({ modelValue: { trigger: 'on_demand' } })
			await auditBox(wrapper).setValue(false)
			expect(wrapper.vm.form.logDelivery).toBe(false)
		})

		it('is included in the payload the server is asked to save', async () => {
			const wrapper = mountForm({ modelValue: { trigger: 'on_demand' } })
			await auditBox(wrapper).setValue(false)
			await wrapper.find('.wm-save').trigger('click')

			const [payload] = wrapper.emitted('save')[0]
			expect(payload.logDelivery).toBe(false)
		})

		it('offers exactly the two triggers the server accepts', () => {
			// The radio group is the only place an admin can pick one, so an option here
			// that saveConfig() rejects is a form that fails on submit with no way back.
			const values = mountForm().findAll('input[name="wm-trigger"]').map((i) => i.element.value)
			expect(values).toEqual(['on_demand', 'on_upload'])
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
