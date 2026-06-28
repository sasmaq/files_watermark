import { defineComponent, h } from 'vue'

export default defineComponent({
	name: 'NcLoadingIcon',
	props: { size: Number },
	setup() {
		return () => h('span', { class: 'nc-loading-icon' })
	},
})
