import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api, apiDownload } from '../api/client'
import type { QueryResult } from '../api/types'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'
import { saveFile } from '../utils/download'
import QueryPane from './QueryPane.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn(), apiDownload: vi.fn() }))
vi.mock('../utils/download', () => ({ saveFile: vi.fn() }))

const mocked = vi.mocked(api)
const result: QueryResult = { columns: ['a'], rows: [[1]], rowCount: 1, truncated: false, affectedRows: null, durationMs: 4 }

const EditorStub = {
  props: ['modelValue', 'dialect', 'tables'],
  emits: ['update:modelValue', 'run'],
  template: '<textarea class="stub" :data-dialect="dialect" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" @keydown.ctrl.enter="$emit(\'run\')"></textarea>',
}

async function mountPane() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useConnectionsStore().items = [{ id: 'abc', name: 'Production', engine: 'postgresql', createdAt: '', updatedAt: '' }]
  mocked.mockResolvedValueOnce({ tables: [{ name: 'orders', kind: 'table', estimatedRows: 3 }] })
  await useWorkspaceStore().open('abc')
  const wrapper = mount(QueryPane, { global: { plugins: [pinia], stubs: { SqlEditor: EditorStub } }, attachTo: document.body })

  return { wrapper, store: useWorkspaceStore() }
}

describe('QueryPane', () => {
  beforeEach(() => mocked.mockReset())

  it('starts with one tab, an empty editor and a hint', async () => {
    const { wrapper } = await mountPane()

    expect(wrapper.findAll('[role="tab"]')).toHaveLength(1)
    expect(wrapper.get('[role="tab"]').text()).toContain('Requête 1')
    expect(wrapper.text()).toContain('Exécutez une requête')
    expect(wrapper.get('.stub').attributes('data-dialect')).toBe('postgresql')
    wrapper.unmount()
  })

  it('runs the statement and shows its result', async () => {
    const { wrapper } = await mountPane()
    await wrapper.get('.stub').setValue('select 1')
    mocked.mockResolvedValueOnce(result)

    await wrapper.get('button.run').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/abc/query', { sql: 'select 1', mode: 'read_only', confirmed: false })
    expect(wrapper.get('table').text()).toContain('1')
    wrapper.unmount()
  })

  it('runs from the keyboard shortcut of the editor', async () => {
    const { wrapper } = await mountPane()
    await wrapper.get('.stub').setValue('select 1')
    mocked.mockResolvedValueOnce(result)

    await wrapper.get('.stub').trigger('keydown', { key: 'Enter', ctrlKey: true })
    await flushPromises()

    expect(mocked).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('shows that a query is running', async () => {
    const { wrapper } = await mountPane()
    await wrapper.get('.stub').setValue('select 1')
    mocked.mockReturnValueOnce(new Promise(() => undefined))

    await wrapper.get('button.run').trigger('click')

    expect(wrapper.get('button.run').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Exécution')
    wrapper.unmount()
  })

  it('shows a refusal with its explanation', async () => {
    const { wrapper } = await mountPane()
    await wrapper.get('.stub').setValue('drop table orders')
    mocked.mockRejectedValueOnce(new ApiError(403, 'query_refused', { reason: 'not_read_only' }))

    await wrapper.get('button.run').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('lecture seule')
    wrapper.unmount()
  })

  it('is read-only by default and switches to write mode explicitly', async () => {
    const { wrapper } = await mountPane()
    const toggle = wrapper.get('button[role="switch"]')

    expect(toggle.attributes('aria-checked')).toBe('true')
    expect(toggle.text()).toContain('Lecture seule')

    await toggle.trigger('click')
    expect(wrapper.get('button[role="switch"]').attributes('aria-checked')).toBe('false')
    expect(wrapper.text()).toContain('Écriture activée')

    await wrapper.get('.stub').setValue('update orders set status = 1 where id = 1')
    mocked.mockResolvedValueOnce({ ...result, columns: [], rows: [], rowCount: 0, affectedRows: 1 })
    await wrapper.get('button.run').trigger('click')
    await flushPromises()
    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/abc/query', expect.objectContaining({ mode: 'read_write' }))
    wrapper.unmount()
  })

  it('asks for a confirmation before a destructive statement, then runs it', async () => {
    const { wrapper } = await mountPane()
    await wrapper.get('button[role="switch"]').trigger('click')
    await wrapper.get('.stub').setValue('delete from orders')
    mocked.mockRejectedValueOnce(new ApiError(409, 'confirmation_required', { reason: 'destructive_without_where' }))

    await wrapper.get('button.run').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="dialog"]').text()).toContain('WHERE')
    mocked.mockResolvedValueOnce({ ...result, columns: [], rows: [], rowCount: 0, affectedRows: 3 })
    await wrapper.get('button.confirm').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/abc/query', { sql: 'delete from orders', mode: 'read_write', confirmed: true })
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('3 lignes modifiées')
    wrapper.unmount()
  })

  it('does nothing when the confirmation is declined', async () => {
    const { wrapper } = await mountPane()
    await wrapper.get('button[role="switch"]').trigger('click')
    await wrapper.get('.stub').setValue('delete from orders')
    mocked.mockRejectedValueOnce(new ApiError(409, 'confirmation_required', { reason: 'destructive_without_where' }))
    await wrapper.get('button.run').trigger('click')
    await flushPromises()

    await wrapper.get('button.cancel').trigger('click')

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(mocked).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('adds, selects and closes tabs', async () => {
    const { wrapper, store } = await mountPane()

    await wrapper.get('button.add-tab').trigger('click')
    expect(wrapper.findAll('[role="tab"]')).toHaveLength(2)
    expect(wrapper.findAll('[role="tab"]')[1]?.attributes('aria-selected')).toBe('true')

    await wrapper.findAll('[role="tab"]')[0]?.trigger('click')
    expect(store.activeTab.title).toBe('Requête 1')

    await wrapper.findAll('button.close-tab')[1]?.trigger('click')
    expect(wrapper.findAll('[role="tab"]')).toHaveLength(1)
    wrapper.unmount()
  })

  it('opens the history, loads it, and puts a picked statement back in the editor', async () => {
    const { wrapper, store } = await mountPane()
    mocked.mockResolvedValueOnce({
      entries: [{ id: 'h1', occurredAt: '2026-11-01T10:00:00+00:00', actor: 'web', outcome: 'success', sql: 'select * from orders', mode: 'read_only', rowCount: 3, durationMs: 4, reason: null }],
      next: null,
    })

    await wrapper.get('button.history-toggle').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/abc/history?limit=50')
    expect(wrapper.find('section.history').exists()).toBe(true)
    expect(wrapper.find('.stub').exists()).toBe(false)

    await wrapper.get('button.pick').trigger('click')

    expect(store.activeTab.sql).toBe('select * from orders')
    expect(wrapper.find('section.history').exists()).toBe(false)
    expect((wrapper.get('.stub').element as HTMLTextAreaElement).value).toBe('select * from orders')
    wrapper.unmount()
  })

  it('closes the history with the same button', async () => {
    const { wrapper } = await mountPane()
    mocked.mockResolvedValueOnce({ entries: [], next: null })

    await wrapper.get('button.history-toggle').trigger('click')
    await flushPromises()
    await wrapper.get('button.history-toggle').trigger('click')

    expect(wrapper.find('section.history').exists()).toBe(false)
    expect(wrapper.find('.stub').exists()).toBe(true)
    expect(wrapper.get('button.history-toggle').attributes('aria-pressed')).toBe('false')
    wrapper.unmount()
  })

  it('exports only once there is a result with rows', async () => {
    const { wrapper } = await mountPane()
    expect(wrapper.get('button.export-csv').attributes('disabled')).toBeDefined()

    await wrapper.get('.stub').setValue('select 1')
    mocked.mockResolvedValueOnce(result)
    await wrapper.get('button.run').trigger('click')
    await flushPromises()

    expect(wrapper.get('button.export-csv').attributes('disabled')).toBeUndefined()
    wrapper.unmount()
  })

  it('downloads the result as CSV or JSON', async () => {
    const { wrapper } = await mountPane()
    await wrapper.get('.stub').setValue('select 1')
    mocked.mockResolvedValueOnce(result)
    await wrapper.get('button.run').trigger('click')
    await flushPromises()
    const blob = new Blob(['x'])
    vi.mocked(apiDownload).mockResolvedValueOnce({ blob, filename: 'terrildb-export-1.csv', truncated: false })

    await wrapper.get('button.export-csv').trigger('click')
    await flushPromises()

    expect(apiDownload).toHaveBeenLastCalledWith('/web/connections/abc/export', { sql: 'select 1', format: 'csv' })
    expect(saveFile).toHaveBeenCalledWith(blob, 'terrildb-export-1.csv')

    vi.mocked(apiDownload).mockResolvedValueOnce({ blob, filename: 'terrildb-export-1.json', truncated: true })
    await wrapper.get('button.export-json').trigger('click')
    await flushPromises()
    expect(apiDownload).toHaveBeenLastCalledWith('/web/connections/abc/export', { sql: 'select 1', format: 'json' })
    expect(wrapper.get('.export-notice').text()).toContain('limité')
    wrapper.unmount()
  })

  it('does not offer an export after a write', async () => {
    const { wrapper } = await mountPane()
    await wrapper.get('button[role="switch"]').trigger('click')
    await wrapper.get('.stub').setValue('update orders set status = 1 where id = 1')
    mocked.mockResolvedValueOnce({ ...result, columns: [], rows: [], rowCount: 0, affectedRows: 1 })
    await wrapper.get('button.run').trigger('click')
    await flushPromises()

    expect(wrapper.get('button.export-csv').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })

  it('shows the execution plan of the statement', async () => {
    const { wrapper } = await mountPane()
    await wrapper.get('.stub').setValue('select * from orders')
    mocked.mockResolvedValueOnce({
      plan: { totalCost: 5, root: { kind: 'full_scan', label: 'Seq Scan', relation: 'orders', index: null, rows: 3800, cost: 5, condition: null, fullScan: true, children: [] } },
      findings: [{ rule: 'full_scan', relation: 'orders', rows: 3800, condition: null }],
    })

    await wrapper.get('button.plan').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/abc/plan', { sql: 'select * from orders' })
    expect(wrapper.find('section.plan').exists()).toBe(true)
    expect(wrapper.get('li.node').text()).toContain('Parcours complet')
    expect(wrapper.find('li.finding').exists()).toBe(true)
    wrapper.unmount()
  })

  it('offers no execution plan for an engine that has none', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    useConnectionsStore().items = [{ id: 'abc', name: 'Analytique', engine: 'clickhouse', createdAt: '', updatedAt: '' }]
    mocked.mockResolvedValueOnce({ tables: [] })
    await useWorkspaceStore().open('abc')
    const wrapper = mount(QueryPane, { global: { plugins: [pinia], stubs: { SqlEditor: EditorStub } }, attachTo: document.body })

    expect(wrapper.find('button.plan').exists()).toBe(false)
    expect(wrapper.get('.stub').attributes('data-dialect')).toBe('clickhouse')
    wrapper.unmount()
  })

  it('goes back to the result after a run', async () => {
    const { wrapper } = await mountPane()
    await wrapper.get('.stub').setValue('select 1')
    mocked.mockResolvedValueOnce({ plan: { totalCost: null, root: { kind: 'other', label: 'Result', relation: null, index: null, rows: null, cost: null, condition: null, fullScan: false, children: [] } }, findings: [] })
    await wrapper.get('button.plan').trigger('click')
    await flushPromises()
    mocked.mockResolvedValueOnce(result)

    await wrapper.get('button.run').trigger('click')
    await flushPromises()

    expect(wrapper.find('section.plan').exists()).toBe(false)
    expect(wrapper.find('table').exists()).toBe(true)
    wrapper.unmount()
  })

  it('reports a refused plan', async () => {
    const { wrapper } = await mountPane()
    await wrapper.get('.stub').setValue('delete from orders')
    mocked.mockRejectedValueOnce(new ApiError(403, 'query_refused', { reason: 'not_read_only' }))

    await wrapper.get('button.plan').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('lecture seule')
    wrapper.unmount()
  })
})
