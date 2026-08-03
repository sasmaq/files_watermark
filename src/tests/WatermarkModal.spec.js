import { mount } from '@vue/test-utils'
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
})
