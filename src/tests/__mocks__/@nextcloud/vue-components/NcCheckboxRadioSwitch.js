import { defineComponent, h } from 'vue'

// Renders a real <input type="checkbox"> plus its label text, so DOM queries and
// change → update:modelValue round-trips behave like the real switch.
export default defineComponent({
	name: 'NcCheckboxRadioSwitch',
	props: { modelValue: Boolean, type: String, disabled: Boolean },
	emits: ['update:modelValue'],
	setup(props, { emit, slots }) {
		return () => h('label', { class: 'checkbox-radio-switch' }, [
			h('input', {
				type: 'checkbox',
				class: 'checkbox-radio-switch__input',
				checked: props.modelValue,
				disabled: props.disabled,
				onChange: (e) => emit('update:modelValue', e.target.checked),
			}),
			slots.default?.(),
		])
	},
})
