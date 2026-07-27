import { defineComponent, h } from 'vue'

// The real component fetches the server's system tags over WebDAV, which a unit
// test has no business doing. This keeps the contract that matters — a
// modelValue in, an id out — and exposes it as a <select> so tests can drive it.
export default defineComponent({
	name: 'NcSelectTags',
	props: {
		modelValue: { type: [Number, String, Array, Object], default: null },
		multiple: { type: Boolean, default: true },
		placeholder: { type: String, default: '' },
	},
	emits: ['update:modelValue'],
	setup(props, { emit }) {
		return () => h('select', {
			class: 'select-tags',
			value: props.modelValue ?? '',
			onChange: (e) => emit('update:modelValue', e.target.value === '' ? null : Number(e.target.value)),
		})
	},
})
