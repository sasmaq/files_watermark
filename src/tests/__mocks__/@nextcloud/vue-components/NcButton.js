import { defineComponent, h } from 'vue'

// Renders a real <button> so DOM queries (find('button')) and native
// click → @click fallthrough work in tests.
export default defineComponent({
	name: 'NcButton',
	props: { disabled: Boolean, type: String, nativeType: String },
	setup(props, { slots }) {
		return () => h('button', { disabled: props.disabled }, [
			slots.icon?.(),
			slots.default?.(),
		])
	},
})
