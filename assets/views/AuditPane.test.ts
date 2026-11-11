import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import type { SchemaAudit } from '../api/types'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'
import AuditPane from './AuditPane.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn(), apiDownload: vi.fn() }))

const mocked = vi.mocked(api)
const audit: SchemaAudit = {
  tablesAudited: 5,
  truncated: false,
  findings: [
    { rule: 'foreign_key_without_index', severity: 'warning', table: 'orders', columns: ['customer_id'], index: null, coveredBy: null, suggestion: 'CREATE INDEX idx_orders_customer_id ON "orders" ("customer_id");', detail: null },
    { rule: 'redundant_index', severity: 'info', table: 'orders', columns: ['a'], index: 'idx_a', coveredBy: 'idx_ab', suggestion: 'DROP INDEX "idx_a";', detail: null },
    { rule: 'missing_primary_key', severity: 'warning', table: 'log', columns: [], index: null, coveredBy: null, suggestion: null, detail: null },
  ],
}

async function mountPane(result: SchemaAudit | ApiError = audit, scope: { database: string; schema: string } | null = null) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useConnectionsStore().items = [{ id: 'abc', name: 'Production', engine: 'postgresql', createdAt: '', updatedAt: '' }]
  mocked.mockResolvedValueOnce({ tables: [] })
  await useWorkspaceStore().open('abc')
  if (scope !== null) {
    useWorkspaceStore().database = scope.database
    useWorkspaceStore().schema = scope.schema
  }
  if (result instanceof ApiError) {
    mocked.mockRejectedValueOnce(result)
  } else {
    mocked.mockResolvedValueOnce(result)
  }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/connections/:id', name: 'workspace', component: { template: '<p />' } },
      { path: '/connections/:id/audit', name: 'audit', component: { template: '<p />' } },
    ],
  })
  await router.push('/connections/abc/audit')
  const wrapper = mount(AuditPane, { props: { id: 'abc' }, global: { plugins: [pinia, router] } })
  await flushPromises()

  return { wrapper, router, store: useWorkspaceStore() }
}

describe('AuditPane', () => {
  beforeEach(() => mocked.mockReset())

  it('loads the audit of the connection', async () => {
    await mountPane()

    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/abc/audit')
  })

  it('audits the database and schema being explored, not only the connection default', async () => {
    await mountPane(audit, { database: 'reporting', schema: 'sales' })

    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/abc/audit?database=reporting&schema=sales')
  })

  it('groups the findings by severity, warnings first, with their explanation', async () => {
    const { wrapper } = await mountPane()

    const sections = wrapper.findAll('section.group')
    expect(sections[0]?.get('h2').text()).toContain('À corriger')
    expect(sections[0]?.findAll('li.finding')).toHaveLength(2)
    expect(sections[1]?.get('h2').text()).toContain('À savoir')
    expect(sections[1]?.findAll('li.finding')).toHaveLength(1)
    expect(wrapper.text()).toContain('La clé étrangère de « orders » sur customer_id n’a pas d’index.')
    expect(wrapper.text()).toContain('cascade')
  })

  it('shows the suggested statement and what it was checked against', async () => {
    const { wrapper } = await mountPane()

    expect(wrapper.get('pre').text()).toBe('CREATE INDEX idx_orders_customer_id ON "orders" ("customer_id");')
    expect(wrapper.text()).toContain('5 tables examinées')
  })

  it('opens a suggestion in the editor without running it', async () => {
    const { wrapper, router, store } = await mountPane()
    mocked.mockClear()

    await wrapper.get('button.open').trigger('click')
    await flushPromises()

    expect(store.activeTab.sql).toBe('CREATE INDEX idx_orders_customer_id ON "orders" ("customer_id");')
    expect(router.currentRoute.value.name).toBe('workspace')
    expect(mocked).not.toHaveBeenCalled()
  })

  it('offers no editor button for a finding without a statement', async () => {
    const { wrapper } = await mountPane()

    const noPrimaryKey = wrapper.findAll('li.finding').find((item) => item.text().includes('clé primaire'))
    expect(noPrimaryKey?.find('button.open').exists()).toBe(false)
  })

  it('congratulates a clean schema', async () => {
    const { wrapper } = await mountPane({ tablesAudited: 5, truncated: false, findings: [] })

    expect(wrapper.get('.clean').text()).toContain('Rien à signaler')
  })

  it('says when the audit stopped early', async () => {
    const { wrapper } = await mountPane({ ...audit, truncated: true })

    expect(wrapper.get('.truncated').text()).toContain('300')
  })

  it('reports a failure', async () => {
    const { wrapper } = await mountPane(new ApiError(502, 'target_unreachable'))

    expect(wrapper.get('[role="alert"]').text()).toContain('joindre')
  })
})
