import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import type { SchemaDiagram } from '../api/types'
import DiagramPane from './DiagramPane.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const diagram: SchemaDiagram = {
  truncated: false,
  tables: [
    { name: 'customers', columns: [{ name: 'id', type: 'integer', nullable: false, primary: true }, { name: 'email', type: 'text', nullable: true, primary: false }] },
    { name: 'orders', columns: [{ name: 'id', type: 'integer', nullable: false, primary: true }, { name: 'customer_id', type: 'integer', nullable: false, primary: false }] },
  ],
  relations: [{ name: 'orders_customer_fk', table: 'orders', columns: ['customer_id'], referencedTable: 'customers', referencedColumns: ['id'] }],
}

async function mountPane(result: SchemaDiagram | ApiError = diagram) {
  const pinia = createPinia()
  setActivePinia(pinia)
  if (result instanceof ApiError) {
    mocked.mockRejectedValueOnce(result)
  } else {
    mocked.mockResolvedValueOnce(result)
  }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/connections/:id/diagram', name: 'diagram', component: { template: '<p />' } },
      { path: '/connections/:id/tables/:name(.+)', name: 'table', component: { template: '<p />' } },
    ],
  })
  await router.push('/connections/abc/diagram')
  const wrapper = mount(DiagramPane, { props: { id: 'abc' }, global: { plugins: [pinia, router] } })
  await flushPromises()

  return { wrapper, router }
}

describe('DiagramPane', () => {
  beforeEach(() => mocked.mockReset())

  it('loads the diagram of the connection', async () => {
    await mountPane()

    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/abc/diagram')
  })

  it('draws a box per table with its columns and types', async () => {
    const { wrapper } = await mountPane()

    const boxes = wrapper.findAll('g.table')
    expect(boxes.map((box) => box.get('.name').text())).toEqual(['customers', 'orders'])
    expect(boxes[0]!.text()).toContain('email')
    expect(boxes[0]!.text()).toContain('text')
  })

  it('marks the primary key columns', async () => {
    const { wrapper } = await mountPane()

    const customers = wrapper.findAll('g.table').find((box) => box.get('.name').text() === 'customers')!
    expect(customers.findAll('.key')).toHaveLength(1)
  })

  it('draws a line per relation, labelled for assistive technology', async () => {
    const { wrapper } = await mountPane()

    const lines = wrapper.findAll('path.relation')
    expect(lines).toHaveLength(1)
    expect(lines[0]!.get('title').text()).toBe('orders.customer_id → customers.id')
  })

  it('opens a table from its name', async () => {
    const { wrapper, router } = await mountPane()

    await wrapper.findAll('g.table')[0]!.get('.name').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('table')
    expect(router.currentRoute.value.params.name).toBe('customers')
  })

  it('says so when there is nothing to draw', async () => {
    const { wrapper } = await mountPane({ truncated: false, tables: [], relations: [] })

    expect(wrapper.text()).toContain('Aucune table à dessiner')
    expect(wrapper.find('svg').exists()).toBe(false)
  })

  it('warns when the diagram stopped at the first 300 tables', async () => {
    const { wrapper } = await mountPane({ ...diagram, truncated: true })

    expect(wrapper.get('.truncated').text()).toContain('300')
  })

  it('shows the error when the schema cannot be read', async () => {
    const { wrapper } = await mountPane(new ApiError(502, 'target_unreachable'))

    expect(wrapper.get('[role="alert"]').text()).not.toBe('')
    expect(wrapper.find('svg').exists()).toBe(false)
  })
})
