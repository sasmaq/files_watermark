import { defineComponent, h } from 'vue'

const stub = (name) => defineComponent({
    name,
    props: { modelValue: {}, open: {}, name: String, description: String, type: String, disabled: Boolean, label: String, placeholder: String, options: Array, reduce: Function, trackBy: String },
    emits: ['update:modelValue', 'update:open', 'click'],
    setup(props, { slots, emit }) {
        return () => h('div', { class: `nc-${name.toLowerCase()}` }, slots.default?.() ?? [])
    },
})

export default {
    NcButton: stub('NcButton'),
    NcDialog: stub('NcDialog'),
    NcLoadingIcon: stub('NcLoadingIcon'),
    NcNoteCard: stub('NcNoteCard'),
    NcSelect: stub('NcSelect'),
    NcSettingsSection: stub('NcSettingsSection'),
    NcTextField: stub('NcTextField'),
}

// Named exports for dist/Components/* imports
export const NcButton        = stub('NcButton')
export const NcDialog        = stub('NcDialog')
export const NcLoadingIcon   = stub('NcLoadingIcon')
export const NcNoteCard      = stub('NcNoteCard')
export const NcSelect        = stub('NcSelect')
export const NcSettingsSection = stub('NcSettingsSection')
export const NcTextField     = stub('NcTextField')
