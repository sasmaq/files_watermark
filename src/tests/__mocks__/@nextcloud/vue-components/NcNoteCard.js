import { defineComponent, h } from 'vue'

export default defineComponent({
    name: 'NcNoteCard',
    props: { type: String },
    setup(props, { slots }) {
        return () => h('div', { class: `nc-note-card nc-note-card--${props.type}` }, slots.default?.())
    },
})
