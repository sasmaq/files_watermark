import { mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import WatermarkModal from '../components/WatermarkModal.vue'

// @nextcloud/vue, axios, router and l10n are stubbed via jest.config moduleNameMapper.

const MB = 1024 * 1024

/**
 * Mount the modal for a file of the given size.
 * @param {number} fileSize - size in bytes
 * @return {object} the mounted wrapper
 */
function mountModal(fileSize) {
	return mount(WatermarkModal, {
		props: { filePath: '/report.pdf', fileName: 'report.pdf', fileSize },
	})
}

describe('WatermarkModal', () => {
	// The estimate is this app's only plural call, and it is the reason the l10n files
	// carry a `pluralForm` line at all. Arabic has six forms and inflects the noun
	// differently in each, so "second(s)" - which is what this string used to say - is
	// untranslatable there: the parenthesised plural is an English-only shortcut.
	describe('processing-time estimate', () => {
		it('is hidden for files under a megabyte, where it would be noise', () => {
			expect(mountModal(512 * 1024).find('.time-hint').exists()).toBe(false)
		})

		it('uses the singular form for a one-second estimate', () => {
			expect(mountModal(1.2 * MB).find('.time-hint').text())
				.toBe('Estimated processing time: about 1 second for large files.')
		})

		it('uses the plural form, with the count substituted, above one', () => {
			// %n has to be replaced. A form that kept the literal marker would still read
			// as a fluent sentence and still be wrong.
			expect(mountModal(9 * MB).find('.time-hint').text())
				.toBe('Estimated processing time: about 9 seconds for large files.')
		})
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
			const wrapper = mountModal(2 * MB)
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
