import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ConfirmDialog from './ConfirmDialog.vue'

function mountDialog(props: Record<string, unknown> = {}) {
  return mount(ConfirmDialog, {
    props: { title: 'Supprimer la connexion ?', message: 'Cette action est définitive.', confirmLabel: 'Supprimer', ...props },
    attachTo: document.body,
  })
}

describe('ConfirmDialog', () => {
  it('is an accessible modal dialog with its title and message', () => {
    const wrapper = mountDialog()

    const dialog = wrapper.get('[role="dialog"]')
    expect(dialog.attributes('aria-modal')).toBe('true')
    expect(wrapper.get('h2').text()).toBe('Supprimer la connexion ?')
    expect(dialog.attributes('aria-labelledby')).toBe(wrapper.get('h2').attributes('id'))
    expect(wrapper.text()).toContain('Cette action est définitive.')
    wrapper.unmount()
  })

  it('emits confirm and cancel', async () => {
    const wrapper = mountDialog()

    await wrapper.get('button.confirm').trigger('click')
    await wrapper.get('button.cancel').trigger('click')

    expect(wrapper.emitted('confirm')).toHaveLength(1)
    expect(wrapper.emitted('cancel')).toHaveLength(1)
    wrapper.unmount()
  })

  it('cancels on Escape and when the backdrop is clicked', async () => {
    const wrapper = mountDialog()

    await wrapper.get('[role="dialog"]').trigger('keydown', { key: 'Escape' })
    await wrapper.get('.backdrop').trigger('click')

    expect(wrapper.emitted('cancel')).toHaveLength(2)
    wrapper.unmount()
  })

  it('does not cancel when the dialog itself is clicked', async () => {
    const wrapper = mountDialog()

    await wrapper.get('[role="dialog"]').trigger('click')

    expect(wrapper.emitted('cancel')).toBeUndefined()
    wrapper.unmount()
  })

  it('puts the focus on the safe choice', () => {
    const wrapper = mountDialog()

    expect(document.activeElement).toBe(wrapper.get('button.cancel').element)
    wrapper.unmount()
  })

  it('shows a destructive confirmation as such', () => {
    const wrapper = mountDialog({ danger: true })

    expect(wrapper.get('button.confirm').classes()).toContain('danger')
    wrapper.unmount()
  })

  it('can disable the confirmation while something is running', () => {
    const wrapper = mountDialog({ busy: true })

    expect(wrapper.get('button.confirm').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })
})
