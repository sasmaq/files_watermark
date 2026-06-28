import { defineComponent, h } from 'vue'

export default defineComponent({
	name: 'NcTextField',
	props: { modelValue: String, label: String, placeholder: String },
	emits: ['update:modelValue'],
	setup(props, { emit }) {
		return () => h('input', {
			value: props.modelValue,
			placeholder: props.placeholder,
			onInput: (e) => emit('update:modelValue', e.target.value),
		})
	},
})
