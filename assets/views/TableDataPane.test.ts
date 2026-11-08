import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import type { QueryResult, TableDetail } from '../api/types'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'
import TableDataPane from './TableDataPane.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn(), apiDownload: vi.fn() }))

const mocked = vi.mocked(api)
const result: QueryResult = { columns: ['id', 'status', 'note'], rows: [[1, 'paid', null], [2, 'new', 'x']], rowCount: 2, truncated: false, affectedRows: null, durationMs: 4 }
const detail: TableDetail = {
  name: 'orders', fingerprint: 'f'.repeat(64), kind: 'table', estimatedRows: 2, viewDefinition: null,
  columns: [
    { name: 'id', type: 'integer', nullable: false, default: null, autoIncrement: true, comment: null },
    { name: 'status', type: 'text', nullable: false, default: null, autoIncrement: false, comment: null },
    { name: 'note', type: 'text', nullable: true, default: null, autoIncrement: false, comment: null },
  ],
  indexes: [{ name: 'orders_pkey', columns: ['id'], unique: true, primary: true }],
  foreignKeys: [],
}
const plan = { statements: [{ sql: "UPDATE \"orders\" SET \"status\" = 'ok' WHERE \"id\" = 1", operation: 'update', subject: 'orders', destructive: false, matched: 1 }], transactional: true, asynchronous: false, destructive: false, ok: true }

const EditorStub = {
  props: ['modelValue', 'dialect', 'tables'],
  emits: ['update:modelValue', 'run'],
  template: '<textarea class="stub" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" @keydown.ctrl.enter="$emit(\'run\')"></textarea>',
}

type Answers = { query?: QueryResult; preview?: unknown; apply?: unknown | Error }

function answer(answers: Answers = {}) {
  mocked.mockImplementation((...args: unknown[]): Promise<unknown> => {
    const url = String(args[1])
    if (url.endsWith('/data/preview')) {
      return Promise.resolve(answers.preview ?? plan)
    }
    if (url.endsWith('/data/apply')) {
      return answers.apply instanceof Error ? Promise.reject(answers.apply) : Promise.resolve(answers.apply ?? plan)
    }
    if (url.endsWith('/query')) {
      return Promise.resolve(answers.query ?? result)
    }
    if (/\/tables\/[^/?]+$/.test(url)) {
      return Promise.resolve({ table: { ...detail, name: url.split('/').pop() } })
    }

    return Promise.resolve({ tables: [] })
  })
}

async function setup(answers: Answers = {}, engine = 'postgresql') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useConnectionsStore().items = [{ id: 'abc', name: 'Production', engine: engine as 'postgresql', createdAt: '', updatedAt: '' }]
  answer(answers)
  await useWorkspaceStore().open('abc')

  return { pinia, store: useWorkspaceStore() }
}

async function mountPane(pinia: ReturnType<typeof createPinia>, name = 'orders') {
  const wrapper = mount(TableDataPane, { props: { id: 'abc', name }, global: { plugins: [pinia], stubs: { SqlEditor: EditorStub } } })
  await flushPromises()

  return wrapper
}

const posts = (suffix: string) => mocked.mock.calls.filter(([method, url]) => method === 'POST' && String(url).endsWith(suffix))

describe('TableDataPane', () => {
  beforeEach(() => mocked.mockReset())

  it('runs a read of the table on first visit and shows the rows', async () => {
    const { pinia } = await setup()

    const wrapper = await mountPane(pinia)

    expect(mocked).toHaveBeenCalledWith('POST', '/web/connections/abc/query', { sql: 'SELECT *\n  FROM "orders"\n LIMIT 100;', mode: 'read_only', confirmed: false })
    expect(wrapper.get('h1').text()).toBe('orders')
    expect((wrapper.get('input[aria-label="status, ligne 1"]').element as HTMLInputElement).value).toBe('paid')
  })

  it('lets the SQL be edited and runs the edited statement', async () => {
    const { pinia } = await setup()
    const wrapper = await mountPane(pinia)

    await wrapper.get('.stub').setValue('SELECT id FROM "orders" WHERE status = \'paid\'')
    await wrapper.get('button.run').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/abc/query', { sql: 'SELECT id FROM "orders" WHERE status = \'paid\'', mode: 'read_only', confirmed: false })
  })

  it('keeps the edited SQL when coming back and does not run again', async () => {
    const { pinia, store } = await setup()
    const first = await mountPane(pinia)
    await first.get('.stub').setValue('SELECT 1')
    first.unmount()
    const runs = posts('/query').length

    const second = await mountPane(pinia)

    expect(posts('/query')).toHaveLength(runs)
    expect((second.get('.stub').element as HTMLTextAreaElement).value).toBe('SELECT 1')
    expect(store.tabs.filter((tab) => tab.table === 'orders')).toHaveLength(1)
  })

  it('opens another table in its own tab', async () => {
    const { pinia, store } = await setup()
    const wrapper = await mountPane(pinia)

    await wrapper.setProps({ name: 'customers' })
    await flushPromises()

    expect(store.tabs.map((tab) => tab.table)).toEqual([null, 'orders', 'customers'])
    expect(posts('/query').at(-1)?.[2]).toEqual(expect.objectContaining({ sql: 'SELECT *\n  FROM "customers"\n LIMIT 100;' }))
  })

  it('stays read-only, and says why, when the query does not give back the rows of the table', async () => {
    const { pinia } = await setup({ query: { ...result, columns: ['total'], rows: [[3]] } })

    const wrapper = await mountPane(pinia)

    expect(wrapper.find('input.field').exists()).toBe(false)
    expect(wrapper.get('.readonly').text()).toContain('Lecture seule')
    expect(wrapper.get('table').text()).toContain('3')
  })

  describe('editing', () => {
    it('counts what is pending, keeps the query from being re-run and drops everything on cancel', async () => {
      const { pinia } = await setup()
      const wrapper = await mountPane(pinia)

      await wrapper.get('input[aria-label="status, ligne 1"]').setValue('ok')

      expect(wrapper.get('.changes').text()).toContain('1 changement en attente')
      expect(wrapper.get('button.run').attributes('disabled')).toBeDefined()

      await wrapper.get('button.cancel-changes').trigger('click')

      expect(wrapper.find('.changes').exists()).toBe(false)
      expect((wrapper.get('input[aria-label="status, ligne 1"]').element as HTMLInputElement).value).toBe('paid')
    })

    it('checks the changes before writing anything and shows the SQL with the rows it finds', async () => {
      const { pinia } = await setup()
      const wrapper = await mountPane(pinia)
      await wrapper.get('input[aria-label="status, ligne 1"]').setValue('ok')

      await wrapper.get('button.review').trigger('click')
      await flushPromises()

      expect(posts('/data/preview')[0]?.[2]).toEqual({ updates: [{ original: { id: 1, status: 'paid', note: null }, changes: { status: 'ok' } }], inserts: [], deletes: [] })
      expect(posts('/data/apply')).toHaveLength(0)
      expect(wrapper.get('.sql').text()).toContain("UPDATE \"orders\" SET \"status\" = 'ok'")
      expect(wrapper.get('.sql').text()).toContain('1 ligne')
    })

    it('applies after validation, reads the table again and clears the draft', async () => {
      const { pinia } = await setup()
      const wrapper = await mountPane(pinia)
      await wrapper.get('input[aria-label="status, ligne 1"]').setValue('ok')
      await wrapper.get('button.review').trigger('click')
      await flushPromises()
      const runs = posts('/query').length

      await wrapper.get('button.apply').trigger('click')
      await flushPromises()

      expect(posts('/data/apply')[0]?.[2]).toEqual(expect.objectContaining({ confirmed: false }))
      expect(posts('/query')).toHaveLength(runs + 1)
      expect(wrapper.find('.changes').exists()).toBe(false)
      expect(wrapper.get('.applied').text()).toContain('appliqué')
    })

    it('refuses to apply when a row is not found exactly once', async () => {
      const { pinia } = await setup({ preview: { ...plan, ok: false, statements: [{ ...plan.statements[0], matched: 0 }] } })
      const wrapper = await mountPane(pinia)
      await wrapper.get('input[aria-label="status, ligne 1"]').setValue('ok')

      await wrapper.get('button.review').trigger('click')
      await flushPromises()

      expect(wrapper.get('.alert').text()).toContain('modifiée')
      expect(wrapper.get('button.apply').attributes('disabled')).toBeDefined()
    })

    it('marks rows for deletion and sends the confirmation with the validation', async () => {
      const { pinia } = await setup({ preview: { ...plan, destructive: true, statements: [{ sql: 'DELETE FROM "orders" WHERE "id" = 2', operation: 'delete', subject: 'orders', destructive: true, matched: 1 }] } })
      const wrapper = await mountPane(pinia)

      await wrapper.get('button[aria-label="Supprimer la ligne 2"]').trigger('click')
      await wrapper.get('button.review').trigger('click')
      await flushPromises()
      await wrapper.get('button.apply').trigger('click')
      await flushPromises()

      expect(posts('/data/preview')[0]?.[2]).toEqual({ updates: [], inserts: [], deletes: [{ original: { id: 2, status: 'new', note: 'x' } }] })
      expect(posts('/data/apply')[0]?.[2]).toEqual(expect.objectContaining({ confirmed: true }))
    })

    it('adds a row and sends only what was filled in', async () => {
      const { pinia } = await setup()
      const wrapper = await mountPane(pinia)

      await wrapper.get('button.add-row').trigger('click')
      await wrapper.get('input[aria-label="status, nouvelle ligne 1"]').setValue('fresh')
      await wrapper.get('button.review').trigger('click')
      await flushPromises()

      expect(posts('/data/preview')[0]?.[2]).toEqual({ updates: [], inserts: [{ values: { status: 'fresh' } }], deletes: [] })
    })

    it('sets a cell to NULL', async () => {
      const { pinia } = await setup()
      const wrapper = await mountPane(pinia)

      await wrapper.get('button[aria-label="Mettre note à NULL, ligne 2"]').trigger('click')
      await wrapper.get('button.review').trigger('click')
      await flushPromises()

      expect(posts('/data/preview')[0]?.[2]).toEqual({ updates: [{ original: { id: 2, status: 'new', note: 'x' }, changes: { note: null } }], inserts: [], deletes: [] })
    })

    it('says that ClickHouse applies changes later', async () => {
      const { pinia } = await setup({ preview: { ...plan, asynchronous: true, transactional: false } }, 'clickhouse')
      const wrapper = await mountPane(pinia)
      await wrapper.get('input[aria-label="status, ligne 1"]').setValue('ok')

      await wrapper.get('button.review').trigger('click')
      await flushPromises()

      expect(wrapper.get('.async').text()).toContain('arrière-plan')
    })

    it('shows the engine refusal and keeps the draft', async () => {
      const { pinia } = await setup({ apply: new ApiError(409, 'data_change_failed', { message: 'duplicate key' }) })
      const wrapper = await mountPane(pinia)
      await wrapper.get('input[aria-label="status, ligne 1"]').setValue('ok')
      await wrapper.get('button.review').trigger('click')
      await flushPromises()

      await wrapper.get('button.apply').trigger('click')
      await flushPromises()

      expect(wrapper.get('.alert').text()).toContain('duplicate key')
      expect(wrapper.find('.changes').exists()).toBe(true)
    })
  })
})
