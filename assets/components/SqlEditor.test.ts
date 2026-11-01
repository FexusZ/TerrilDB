import { mount } from '@vue/test-utils'
import { EditorView } from '@codemirror/view'
import { describe, expect, it } from 'vitest'
import SqlEditor from './SqlEditor.vue'

// jsdom ne mesure rien : CodeMirror a besoin de ces deux méthodes pour calculer ses positions.
Range.prototype.getClientRects = () => ({ length: 0, item: () => null, [Symbol.iterator]: function* () { /* vide */ } }) as unknown as DOMRectList
Range.prototype.getBoundingClientRect = () => new DOMRect()

function mountEditor(props: Record<string, unknown> = {}) {
  return mount(SqlEditor, { props: { modelValue: 'select 1', dialect: 'postgresql', tables: [], ...props }, attachTo: document.body })
}

function view(wrapper: ReturnType<typeof mountEditor>): EditorView {
  const found = EditorView.findFromDOM(wrapper.get('.cm-editor').element as HTMLElement)
  if (!found) {
    throw new Error('no editor view')
  }

  return found
}

describe('SqlEditor', () => {
  it('shows the statement', () => {
    const wrapper = mountEditor({ modelValue: 'select * from orders' })

    expect(wrapper.get('.cm-content').text()).toContain('select * from orders')
    wrapper.unmount()
  })

  it('reports what the user types', () => {
    const wrapper = mountEditor({ modelValue: '' })

    view(wrapper).dispatch({ changes: { from: 0, insert: 'select 2' } })

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['select 2'])
    wrapper.unmount()
  })

  it('follows a statement set from outside, without echoing it back', async () => {
    const wrapper = mountEditor({ modelValue: 'select 1' })

    await wrapper.setProps({ modelValue: 'select 99' })

    expect(view(wrapper).state.doc.toString()).toBe('select 99')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    wrapper.unmount()
  })

  it('runs on Ctrl+Enter', async () => {
    const wrapper = mountEditor()

    view(wrapper).contentDOM.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true, bubbles: true }))

    expect(wrapper.emitted('run')).toHaveLength(1)
    wrapper.unmount()
  })

  it('is labelled for assistive technology', () => {
    const wrapper = mountEditor()

    expect(wrapper.get('.cm-content').attributes('aria-label')).toBe('Éditeur de requête SQL')
    wrapper.unmount()
  })
})
