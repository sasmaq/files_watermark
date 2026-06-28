import { createApp } from 'vue'
import AdminSettings from './components/AdminSettings.vue'

const mountAdmin = () => {
	const el = document.getElementById('files-watermark-admin-settings')
	if (!el) {
		return
	}
	createApp(AdminSettings).mount(el)
}

// The settings markup is server-rendered, so the mount point already exists by
// the time this (deferred) script runs. Guard for the case where
// DOMContentLoaded has already fired.
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mountAdmin)
} else {
	mountAdmin()
}
