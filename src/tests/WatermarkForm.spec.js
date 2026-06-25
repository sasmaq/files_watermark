import { mount } from '@vue/test-utils'
import WatermarkForm from '../components/WatermarkForm.vue'

// Mock @nextcloud/vue component imports used inside WatermarkForm
jest.mock('@nextcloud/vue/dist/Components/NcButton.js', () => ({
    default: { template: '<button><slot /></button>' },
}))
jest.mock('@nextcloud/vue/dist/Components/NcSelect.js', () => ({
    default: { template: '<select />', props: ['modelValue', 'options', 'reduce', 'label', 'trackBy', 'placeholder'] },
}))
jest.mock('@nextcloud/vue/dist/Components/NcSettingsSection.js', () => ({
    default: { template: '<div><slot /></div>', props: ['name', 'description'] },
}))
jest.mock('@nextcloud/vue/dist/Components/NcTextField.js', () => ({
    default: { template: '<input />', props: ['modelValue', 'label', 'placeholder'] },
}))
jest.mock('@nextcloud/vue/dist/Components/NcLoadingIcon.js', () => ({
    default: { template: '<span />', props: ['size'] },
}))

describe('WatermarkForm', () => {
    function mountForm(props = {}) {
        return mount(WatermarkForm, {
            props: {
                modelValue: {},
                isAdmin: false,
                saving: false,
                ...props,
            },
            global: { stubs: { transition: true } },
        })
    }

    it('renders without errors', () => {
        const wrapper = mountForm()
        expect(wrapper.exists()).toBe(true)
    })

    it('hides image-path section when type is text', () => {
        const wrapper = mountForm({ modelValue: { type: 'text' } })
        expect(wrapper.text()).not.toContain('/path/to/logo.png')
    })

    it('hides text-template section when type is image', () => {
        const wrapper = mountForm({ modelValue: { type: 'image' } })
        // text template input should not exist
        const inputs = wrapper.findAll('input[type="text"]')
        expect(inputs.length).toBe(0)
    })

    it('hides admin scope section for non-admin users', () => {
        const wrapper = mountForm({ isAdmin: false })
        expect(wrapper.text()).not.toContain('MIME type whitelist')
    })

    it('shows admin scope section for admin users', () => {
        const wrapper = mountForm({ isAdmin: true })
        expect(wrapper.text()).toContain('MIME type whitelist')
    })

    it('emits save event with form data when save button clicked', async () => {
        const wrapper = mountForm({ modelValue: { type: 'text', textTemplate: '{username}' } })
        await wrapper.find('button').trigger('click')
        expect(wrapper.emitted('save')).toBeTruthy()
        const [payload] = wrapper.emitted('save')[0]
        expect(payload.type).toBe('text')
    })

    it('emits update:modelValue when form data changes', async () => {
        const wrapper = mountForm({ modelValue: { type: 'text', opacity: 80 } })
        // Trigger a reactive change
        wrapper.vm.form.opacity = 50
        await wrapper.vm.$nextTick()
        const emitted = wrapper.emitted('update:modelValue')
        expect(emitted).toBeTruthy()
        const lastEmit = emitted[emitted.length - 1][0]
        expect(lastEmit.opacity).toBe(50)
    })
})
