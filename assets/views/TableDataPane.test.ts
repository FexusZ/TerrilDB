import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../api/client'
import type { QueryResult } from '../api/types'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'
import TableDataPane from './TableDataPane.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn(), apiDownload: vi.fn() }))

const mocked = vi.mocked(api)
const result: QueryResult = { columns: ['id', 'status'], rows: [[1, 'paid']], rowCount: 1, truncated: false, affectedRows: null, durationMs: 4 }

const EditorStub = {
  props: ['modelValue', 'dialect', 'tables'],
  emits: ['update:modelValue', 'run'],
  template: '<textarea class="stub" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" @keydown.ctrl.enter="$emit(\'run\')"></textarea>',
}

async function setup() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useConnectionsStore().items = [{ id: 'abc', name: 'Production', engine: 'postgresql', createdAt: '', updatedAt: '' }]
  mocked.mockResolvedValueOnce({ tables: [] })
  await useWorkspaceStore().open('abc')

  return { pinia, store: useWorkspaceStore() }
}

function mountPane(pinia: ReturnType<typeof createPinia>, name = 'orders') {
  return mount(TableDataPane, { props: { id: 'abc', name }, global: { plugins: [pinia], stubs: { SqlEditor: EditorStub } } })
}

describe('TableDataPane', () => {
  beforeEach(() => mocked.mockReset())

  it('runs a read of the table on first visit and shows the rows', async () => {
    const { pinia } = await setup()
    mocked.mockResolvedValueOnce(result)

    const wrapper = mountPane(pinia)
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/abc/query', { sql: 'SELECT *\n  FROM "orders"\n LIMIT 100;', mode: 'read_only', confirmed: false })
    expect(wrapper.get('h1').text()).toBe('orders')
    expect(wrapper.get('table').text()).toContain('paid')
  })

  it('lets the SQL be edited and runs the edited statement', async () => {
    const { pinia } = await setup()
    mocked.mockResolvedValueOnce(result)
    const wrapper = mountPane(pinia)
    await flushPromises()
    mocked.mockResolvedValueOnce(result)

    await wrapper.get('.stub').setValue('SELECT id FROM "orders" WHERE status = \'paid\'')
    await wrapper.get('button.run').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/abc/query', { sql: 'SELECT id FROM "orders" WHERE status = \'paid\'', mode: 'read_only', confirmed: false })
  })

  it('keeps the edited SQL when coming back and does not run again', async () => {
    const { pinia, store } = await setup()
    mocked.mockResolvedValueOnce(result)
    const first = mountPane(pinia)
    await flushPromises()
    await first.get('.stub').setValue('SELECT 1')
    first.unmount()
    const calls = mocked.mock.calls.length

    const second = mountPane(pinia)
    await flushPromises()

    expect(mocked.mock.calls).toHaveLength(calls)
    expect((second.get('.stub').element as HTMLTextAreaElement).value).toBe('SELECT 1')
    expect(store.tabs.filter((tab) => tab.table === 'orders')).toHaveLength(1)
  })

  it('opens another table in its own tab', async () => {
    const { pinia, store } = await setup()
    mocked.mockResolvedValue(result)
    const wrapper = mountPane(pinia)
    await flushPromises()

    await wrapper.setProps({ name: 'customers' })
    await flushPromises()

    expect(store.tabs.map((tab) => tab.table)).toEqual([null, 'orders', 'customers'])
    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/abc/query', expect.objectContaining({ sql: 'SELECT *\n  FROM "customers"\n LIMIT 100;' }))
  })
})
