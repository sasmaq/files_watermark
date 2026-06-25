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

function mountModal(filePath, fileName) {
    const container = document.createElement('div')
    document.body.appendChild(container)

    const app = createApp({
        render() {
            return h(WatermarkModal, {
                filePath,
                fileName,
                onClose() {
                    app.unmount()
                    container.remove()
                },
            })
        },
    })
    app.mount(container)
}

registerFileAction(new FileAction({
    id: 'files_watermark_apply',
    displayName: () => t('files_watermark', 'Apply Watermark'),
    iconSvgInline: () => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/></svg>',
    enabled(files) {
        return files.length === 1 && SUPPORTED_MIME.includes(files[0].mime)
    },
    async exec(file) {
        mountModal(file.path, file.basename)
    },
}))
