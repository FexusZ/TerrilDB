import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import type { TableDetail } from '../api/types'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'
import TablePane from './TablePane.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const detail: TableDetail = {
  name: 'orders', kind: 'table', estimatedRows: 3800, viewDefinition: null,
  columns: [{ name: 'id', type: 'integer', nullable: false, default: null, autoIncrement: true, comment: null }],
  indexes: [], foreignKeys: [],
}

async function mountPane(name = 'orders') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useConnectionsStore().items = [{ id: 'abc', name: 'Production', engine: 'postgresql', createdAt: '', updatedAt: '' }]
  mocked.mockResolvedValueOnce({ tables: [] })
  await useWorkspaceStore().open('abc')
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/connections/:id', name: 'workspace', component: { template: '<p />' } },
      { path: '/connections/:id/tables/:name(.+)', name: 'table', component: { template: '<p />' } },
    ],
  })
  await router.push(`/connections/abc/tables/${name}`)

  return { router, pinia }
}

describe('TablePane', () => {
  beforeEach(() => mocked.mockReset())

  it('shows the structure of the table', async () => {
    const { router, pinia } = await mountPane()
    mocked.mockResolvedValueOnce({ table: detail })

    const wrapper = mount(TablePane, { props: { id: 'abc', name: 'orders' }, global: { plugins: [pinia, router] } })
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('orders')
    expect(wrapper.text()).toContain('table')
    expect(wrapper.text()).toMatch(/~\s?3\s?800 lignes/)
    expect(wrapper.get('section.columns').text()).toContain('integer')
  })

  it('opens a preview of the data in the editor and runs it', async () => {
    const { router, pinia } = await mountPane()
    mocked.mockResolvedValueOnce({ table: detail })
    const wrapper = mount(TablePane, { props: { id: 'abc', name: 'orders' }, global: { plugins: [pinia, router] } })
    await flushPromises()
    mocked.mockResolvedValueOnce({ columns: ['id'], rows: [[1]], rowCount: 1, truncated: false, affectedRows: null, durationMs: 3 })

    await wrapper.get('button.preview').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/abc/query', { sql: 'SELECT *\n  FROM "orders"\n LIMIT 100;', mode: 'read_only', confirmed: false })
    expect(router.currentRoute.value.name).toBe('workspace')
    expect(useWorkspaceStore().tabs).toHaveLength(2)
  })

  it('says when the table does not exist any more', async () => {
    const { router, pinia } = await mountPane('gone')
    mocked.mockRejectedValueOnce(new ApiError(404, 'not_found'))

    const wrapper = mount(TablePane, { props: { id: 'abc', name: 'gone' }, global: { plugins: [pinia, router] } })
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('introuvable')
  })

  it('loads the new table when the address changes', async () => {
    const { router, pinia } = await mountPane()
    mocked.mockResolvedValueOnce({ table: detail })
    const wrapper = mount(TablePane, { props: { id: 'abc', name: 'orders' }, global: { plugins: [pinia, router] } })
    await flushPromises()

    mocked.mockResolvedValueOnce({ table: { ...detail, name: 'customers' } })
    await wrapper.setProps({ name: 'customers' })
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('customers')
  })
})
