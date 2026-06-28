import { createApp, h } from 'vue'
import { registerFileAction, FileAction } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import WatermarkModal from './components/WatermarkModal.vue'

const SUPPORTED_MIME = [
	'application/pdf',
	'image/jpeg',
	'image/png',
	'image/webp',
]

// Inline content of img/app.svg — comments stripped for use as an inline SVG string.
const APP_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="17" x2="16" y2="11" stroke-opacity="0.6" stroke-width="1.2"/><line x1="8" y1="14" x2="13" y2="9" stroke-opacity="0.4" stroke-width="1.2"/><line x1="11" y1="17" x2="16" y2="13" stroke-opacity="0.4" stroke-width="1.2"/></svg>'

/**
 * Mounts WatermarkModal and returns a Promise that resolves with:
 *   true  — watermark was applied successfully
 *   null  — user cancelled before applying
 *
 * Keeping exec() awaiting this Promise lets Nextcloud Files show a spinner
 * on the file row and auto-refresh the file when exec resolves with true.
 * @param {string} filePath - Path of the file to watermark
 * @param {string} fileName - Display name shown in the modal
 * @param {number} fileSize - File size in bytes, used for the time estimate
 * @return {Promise<boolean|null>} true when applied, null when cancelled
 */
function mountModal(filePath, fileName, fileSize = 0) {
	return new Promise((resolve) => {
		let watermarked = false
		const container = document.createElement('div')
		document.body.appendChild(container)

		const app = createApp({
			render() {
				return h(WatermarkModal, {
					filePath,
					fileName,
					fileSize,
					onWatermarked() {
						watermarked = true
						resolve(true)
					},
					onClose() {
						app.unmount()
						container.remove()
						if (!watermarked) {
							resolve(null)
						}
					},
				})
			},
		})
		app.mount(container)
	})
}

registerFileAction(new FileAction({
	id: 'files_watermark_apply',
	displayName: () => t('files_watermark', 'Apply Watermark'),
	title: () => t('files_watermark', 'Embed identity information into this file as a visible watermark'),
	iconSvgInline: () => APP_ICON_SVG,
	enabled(files) {
		return files.length === 1 && SUPPORTED_MIME.includes(files[0].mime)
	},
	async exec(file) {
		return mountModal(file.path, file.basename, file.size ?? 0)
	},
}))
