import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import type { TableDetail } from '../api/types'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'
import TableEditor from '../components/TableEditor.vue'
import TableStructure from '../components/TableStructure.vue'
import TablePane from './TablePane.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const detail: TableDetail = {
  name: 'orders', fingerprint: 'f'.repeat(64), kind: 'table', estimatedRows: 3800, viewDefinition: null,
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
      { path: '/connections/:id/data/:name(.+)', name: 'table-data', component: { template: '<p />' } },
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
    expect((wrapper.get('section.columns input[aria-label="Type de la colonne"]').element as HTMLInputElement).value).toBe('integer')
  })

  it('shows the partitions of a partitioned table', async () => {
    const { router, pinia } = await mountPane()
    mocked.mockResolvedValueOnce({ table: { ...detail, partitioning: { method: 'hash', expression: 'id', subMethod: null, subExpression: null, estimatedRows: 10, dataBytes: 16384, partitions: [{ name: 'p0', bound: null, catchAll: false, estimatedRows: 10, dataBytes: 16384, subpartitions: [] }] } } })

    const wrapper = mount(TablePane, { props: { id: 'abc', name: 'orders' }, global: { plugins: [pinia, router] } })
    await flushPromises()

    expect(wrapper.get('section.partitions h2').text()).toBe('Partitions')
    expect(wrapper.get('section.partitions').text()).toContain('HASH')
  })

  it('shows no partitions section for a table without partitions', async () => {
    const { router, pinia } = await mountPane()
    mocked.mockResolvedValueOnce({ table: { ...detail, partitioning: null } })

    const wrapper = mount(TablePane, { props: { id: 'abc', name: 'orders' }, global: { plugins: [pinia, router] } })
    await flushPromises()

    expect(wrapper.find('section.partitions').exists()).toBe(false)
  })

  it('makes a table editable, but leaves a view read-only', async () => {
    const { router, pinia } = await mountPane()
    mocked.mockResolvedValueOnce({ table: detail })
    const table = mount(TablePane, { props: { id: 'abc', name: 'orders' }, global: { plugins: [pinia, router] } })
    await flushPromises()
    expect(table.findComponent(TableEditor).exists()).toBe(true)
    expect(table.findComponent(TableStructure).exists()).toBe(false)

    mocked.mockResolvedValueOnce({ table: { ...detail, name: 'v_totals', kind: 'view', viewDefinition: 'SELECT 1' } })
    const view = mount(TablePane, { props: { id: 'abc', name: 'v_totals' }, global: { plugins: [pinia, router] } })
    await flushPromises()
    expect(view.findComponent(TableEditor).exists()).toBe(false)
    expect(view.findComponent(TableStructure).exists()).toBe(true)
  })

  it('follows a renamed table, and refreshes the lists of tables', async () => {
    const { router, pinia } = await mountPane()
    mocked.mockResolvedValueOnce({ table: detail })
    const wrapper = mount(TablePane, { props: { id: 'abc', name: 'orders' }, global: { plugins: [pinia, router] } })
    await flushPromises()
    mocked.mockReset()
    mocked.mockResolvedValue({ tables: [] })

    wrapper.findComponent(TableEditor).vm.$emit('applied', { ...detail, name: 'sales', fingerprint: 'a'.repeat(64) }, 'orders')
    await flushPromises()

    expect(router.currentRoute.value.params.name).toBe('sales')
    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/tables?limit=100')
  })

  it('goes back to the console once the table is dropped', async () => {
    const { router, pinia } = await mountPane()
    mocked.mockResolvedValueOnce({ table: detail })
    const wrapper = mount(TablePane, { props: { id: 'abc', name: 'orders' }, global: { plugins: [pinia, router] } })
    await flushPromises()
    mocked.mockReset()
    mocked.mockResolvedValue({ tables: [] })

    wrapper.findComponent(TableEditor).vm.$emit('applied', null, 'orders')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('workspace')
    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/tables?limit=100')
  })

  it('reads the table again when the editor asks for a reload', async () => {
    const { router, pinia } = await mountPane()
    mocked.mockResolvedValueOnce({ table: detail })
    const wrapper = mount(TablePane, { props: { id: 'abc', name: 'orders' }, global: { plugins: [pinia, router] } })
    await flushPromises()
    mocked.mockReset()
    mocked.mockImplementation((_method?: string, url = ''): Promise<unknown> => Promise.resolve(url.includes('/tables/orders') ? { table: { ...detail, fingerprint: 'c'.repeat(64) } } : { tables: [] }))

    wrapper.findComponent(TableEditor).vm.$emit('reload')
    await flushPromises()

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/tables/orders')
    expect(wrapper.findComponent(TableEditor).props('table').fingerprint).toBe('c'.repeat(64))
  })

  it('shows how much room the data, the indexes and both together take', async () => {
    const { router, pinia } = await mountPane()
    mocked.mockResolvedValueOnce({ table: { ...detail, dataBytes: 3 * 1024 * 1024, indexBytes: 1024 * 1024 } })

    const wrapper = mount(TablePane, { props: { id: 'abc', name: 'orders' }, global: { plugins: [pinia, router] } })
    await flushPromises()

    expect(wrapper.get('.sizes').text()).toContain('Données 3 Mo')
    expect(wrapper.get('.sizes').text()).toContain('Index 1 Mo')
    expect(wrapper.get('.sizes').text()).toContain('Total 4 Mo')
  })

  it('shows no sizes when the engine gives none', async () => {
    const { router, pinia } = await mountPane()
    mocked.mockResolvedValueOnce({ table: detail })

    const wrapper = mount(TablePane, { props: { id: 'abc', name: 'orders' }, global: { plugins: [pinia, router] } })
    await flushPromises()

    expect(wrapper.find('.sizes').exists()).toBe(false)
  })

  it('goes to the data view of the table', async () => {
    const { router, pinia } = await mountPane()
    mocked.mockResolvedValueOnce({ table: detail })
    const wrapper = mount(TablePane, { props: { id: 'abc', name: 'orders' }, global: { plugins: [pinia, router] } })
    await flushPromises()

    await wrapper.get('button.preview').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('table-data')
    expect(router.currentRoute.value.params.name).toBe('orders')
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
