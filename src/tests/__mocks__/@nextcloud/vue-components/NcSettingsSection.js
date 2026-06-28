import { defineComponent, h } from 'vue'

export default defineComponent({
	name: 'NcSettingsSection',
	props: { name: String, description: String },
	setup(props, { slots }) {
		return () => h('div', { class: 'nc-settings-section' }, slots.default?.())
	},
})
