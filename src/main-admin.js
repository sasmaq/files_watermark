import { createApp } from 'vue'
import AdminSettings from './components/AdminSettings.vue'

document.addEventListener('DOMContentLoaded', () => {
	const el = document.getElementById('files-watermark-admin-settings')
	if (!el) {
		return
	}
	createApp(AdminSettings).mount(el)
})
