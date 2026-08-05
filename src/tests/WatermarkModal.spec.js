import { mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import WatermarkModal from '../components/WatermarkModal.vue'

// @nextcloud/vue, axios, router and l10n are stubbed via jest.config moduleNameMapper.

/**
 * Mount the modal.
 * @return {object} the mounted wrapper
 */
function mountModal() {
	return mount(WatermarkModal, {
		props: { filePath: '/report.pdf', fileName: 'report.pdf' },
	})
}

describe('WatermarkModal', () => {
	it('says the file itself is not changed', () => {
		// The single most likely misreading of this dialog, and the one the old behaviour
		// actually did: "apply watermark" used to rewrite the file. It marks it now, and a
		// user who expects a modified file will go looking for one.
		expect(mountModal().find('.wm-hint').text())
			.toContain('The file itself is not changed')
	})

	describe('apply failures', () => {
		beforeEach(() => {
			axios.post.mockReset()
		})

		/**
		 * Trigger an apply that rejects with the given axios-shaped error.
		 * @param {object} response - the `error.response` the request rejects with
		 * @return {Promise<object>} the mounted wrapper, after the rejection is handled
		 */
		async function failWith(response) {
			const wrapper = mountModal()
			axios.post.mockRejectedValueOnce({ response, message: 'Request failed with status code ' + response.status })
			await wrapper.vm.apply()
			return wrapper
		}

		// The 413 is raised by this app and names both the file's size and the ceiling,
		// so the admin reading it over the user's shoulder knows what to set.
		it('shows the message the server sent when a file is over the size cap', async () => {
			const wrapper = await failWith({
				status: 413,
				data: { error: 'This file is too large to watermark on demand (210.4 MB; the limit is 67.1 MB).' },
			})

			expect(wrapper.text()).toContain('the limit is 67.1 MB')
		})

		// The 429 is raised by core's rate-limiting middleware *before* this app runs, so
		// it carries no `error` field. Without an explicit branch the dialog falls back to
		// axios' own English "Request failed with status code 429".
		it('explains a throttled request rather than showing the raw axios message', async () => {
			const wrapper = await failWith({ status: 429, data: '' })

			expect(wrapper.text()).toContain('Too many watermark requests at once')
			expect(wrapper.text()).not.toContain('status code 429')
		})
	})
})
