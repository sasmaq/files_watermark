import { defineComponent, h } from 'vue'

export default defineComponent({
	name: 'NcDialog',
	props: { open: Boolean, name: String },
	emits: ['update:open'],
	setup(props, { slots }) {
		return () => (props.open
			? h('div', { class: 'nc-dialog' }, [slots.default?.(), slots.actions?.()])
			: null)
	},
})
