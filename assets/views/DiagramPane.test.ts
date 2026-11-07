import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import type { SchemaDiagram } from '../api/types'
import { useWorkspaceStore } from '../stores/workspace'
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

async function mountPane(result: SchemaDiagram | ApiError = diagram, scope: { database: string; schema: string } | null = null) {
  const pinia = createPinia()
  setActivePinia(pinia)
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

  it('draws the database and schema being explored, not only the connection default', async () => {
    await mountPane(diagram, { database: 'reporting', schema: 'sales' })

    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/abc/diagram?database=reporting&schema=sales')
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

  describe('zoom', () => {
    const width = (wrapper: Awaited<ReturnType<typeof mountPane>>['wrapper']): number => Number(wrapper.get('svg').attributes('width'))

    it('starts at 100 % and zooms in and out with the buttons, keeping the drawing proportions', async () => {
      const { wrapper } = await mountPane()
      const full = width(wrapper)
      const height = Number(wrapper.get('svg').attributes('height'))
      expect(wrapper.get('[data-testid="zoom-level"]').text()).toBe('100 %')

      await wrapper.get('button[aria-label="Zoom avant"]').trigger('click')
      expect(width(wrapper)).toBeGreaterThan(full)
      expect(width(wrapper) / Number(wrapper.get('svg').attributes('height'))).toBeCloseTo(full / height, 1)
      expect(wrapper.get('[data-testid="zoom-level"]').text()).toBe('125 %')

      await wrapper.get('button[aria-label="Zoom arrière"]').trigger('click')
      await wrapper.get('button[aria-label="Zoom arrière"]').trigger('click')
      expect(width(wrapper)).toBeLessThan(full)
      expect(wrapper.get('[data-testid="zoom-level"]').text()).toBe('80 %')
    })

    it('goes back to 100 % in one click', async () => {
      const { wrapper } = await mountPane()
      const full = width(wrapper)
      await wrapper.get('button[aria-label="Zoom avant"]').trigger('click')
      await wrapper.get('button[aria-label="Taille réelle"]').trigger('click')

      expect(width(wrapper)).toBe(full)
    })

    it('stays between 25 % and 300 %, and disables the button that would go beyond', async () => {
      const { wrapper } = await mountPane()
      for (let click = 0; click < 30; click++) {
        await wrapper.get('button[aria-label="Zoom arrière"]').trigger('click')
      }
      expect(wrapper.get('[data-testid="zoom-level"]').text()).toBe('25 %')
      expect(wrapper.get('button[aria-label="Zoom arrière"]').attributes('disabled')).toBeDefined()

      for (let click = 0; click < 40; click++) {
        await wrapper.get('button[aria-label="Zoom avant"]').trigger('click')
      }
      expect(wrapper.get('[data-testid="zoom-level"]').text()).toBe('300 %')
      expect(wrapper.get('button[aria-label="Zoom avant"]').attributes('disabled')).toBeDefined()
    })

    it('fits the whole drawing in the visible width', async () => {
      const { wrapper } = await mountPane()
      const full = width(wrapper)
      Object.defineProperty(wrapper.get('.canvas').element, 'clientWidth', { value: full / 2, configurable: true })

      await wrapper.get('button[aria-label="Ajuster à la largeur"]').trigger('click')

      expect(width(wrapper)).toBeLessThanOrEqual(full / 2)
      expect(width(wrapper)).toBeGreaterThan(full / 2 - 2)
    })

    it('zooms with Ctrl + wheel only, so a plain wheel still scrolls', async () => {
      const { wrapper } = await mountPane()
      const full = width(wrapper)

      await wrapper.get('.canvas').trigger('wheel', { deltaY: -100 })
      expect(width(wrapper)).toBe(full)

      const zoomIn = new WheelEvent('wheel', { deltaY: -100, ctrlKey: true, cancelable: true, bubbles: true })
      wrapper.get('.canvas').element.dispatchEvent(zoomIn)
      await flushPromises()
      expect(width(wrapper)).toBeGreaterThan(full)
      expect(zoomIn.defaultPrevented).toBe(true)

      wrapper.get('.canvas').element.dispatchEvent(new WheelEvent('wheel', { deltaY: 100, ctrlKey: true, cancelable: true, bubbles: true }))
      await flushPromises()
      expect(width(wrapper)).toBe(full)
    })

    it('shows no zoom controls when there is nothing to draw', async () => {
      const { wrapper } = await mountPane({ truncated: false, tables: [], relations: [] })

      expect(wrapper.find('[data-testid="zoom-level"]').exists()).toBe(false)
    })
  })
})
