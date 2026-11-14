import { mount } from '@vue/test-utils'
import { CompletionContext, type Completion, type CompletionSource } from '@codemirror/autocomplete'
import { EditorView } from '@codemirror/view'
import { describe, expect, it } from 'vitest'
import { snippetsFor } from '../utils/snippets'
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

  it('accepts the ClickHouse dialect', () => {
    const wrapper = mountEditor({ dialect: 'clickhouse', modelValue: 'select count() from orders' })

    expect(wrapper.get('.cm-content').text()).toContain('select count() from orders')
    wrapper.unmount()
  })

  it('accepts a Redis command line', () => {
    const wrapper = mountEditor({ dialect: 'redis', modelValue: 'get user:1' })

    expect(wrapper.get('.cm-content').text()).toContain('get user:1')
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

describe('SqlEditor snippets', () => {
  async function offered(wrapper: ReturnType<typeof mountEditor>): Promise<Completion[]> {
    const state = view(wrapper).state
    const sources = state.languageDataAt<CompletionSource>('autocomplete', 0)
    const found: Completion[] = []
    for (const source of sources) {
      found.push(...((await source(new CompletionContext(state, 0, true)))?.options ?? []))
    }

    return found
  }

  const every = { recursive_cte: true, window_functions: true, lateral: true, json_table: true }

  it('offers the snippets it is given as completions, with their template', async () => {
    const wrapper = mountEditor({ modelValue: '', snippets: snippetsFor('postgresql', every) })

    const labels = (await offered(wrapper)).map((option) => option.label)

    expect(labels).toContain('WITH RECURSIVE : arbre des ancêtres')
    expect(labels).toContain('JOIN LATERAL : n dernières lignes par parent')
    wrapper.unmount()
  })

  it('offers none by default, nor when the capability is not declared', async () => {
    const wrapper = mountEditor({ modelValue: '', dialect: 'mariadb', snippets: snippetsFor('mariadb', { ...every, lateral: false }) })

    const labels = (await offered(wrapper)).map((option) => option.label)

    expect(labels).not.toContain('JOIN LATERAL : n dernières lignes par parent')
    expect(labels).toContain('Fonction de fenêtrage : cumul')
    await wrapper.setProps({ snippets: [] })
    expect((await offered(wrapper)).map((option) => option.label)).not.toContain('Fonction de fenêtrage : cumul')
    wrapper.unmount()
  })

  it('inserts the template into the editor without running anything', async () => {
    const wrapper = mountEditor({ modelValue: '', snippets: snippetsFor('postgresql', every) })
    const option = (await offered(wrapper)).find((candidate) => candidate.label.startsWith('Fonction de fenêtrage'))
    const apply = option?.apply
    expect(typeof apply).toBe('function')

    ;(apply as (v: EditorView, c: Completion, from: number, to: number) => void)(view(wrapper), option as Completion, 0, 0)

    expect(view(wrapper).state.doc.toString()).toContain('OVER (ORDER BY ')
    expect(wrapper.emitted('run')).toBeUndefined()
    wrapper.unmount()
  })
})
