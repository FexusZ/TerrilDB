import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import type { ConnectionSummary } from '../api/types'
import { useConnectionsStore } from '../stores/connections'
import { useTreeStore } from '../stores/tree'
import ConnectionTree from './ConnectionTree.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const connection = (id: string, name: string, engine: ConnectionSummary['engine'] = 'mysql'): ConnectionSummary => ({ id, name, engine, createdAt: '', updatedAt: '' })
const twoDatabases = { databases: [{ name: 'shop', system: false }, { name: 'reporting', system: false }] }
const page = (names: string[], nextCursor: string | null = null, total: number | null = names.length) => ({ tables: names.map((name) => ({ name, kind: 'table', estimatedRows: 3 })), nextCursor, total })

async function mountTree(connections: ConnectionSummary[] = [connection('c1', 'Production'), connection('c2', 'Recette')], path = '/') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useConnectionsStore().items = connections
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<p />' } },
      { path: '/connections/:id', name: 'workspace', component: { template: '<p />' } },
      { path: '/connections/:id/edit', name: 'connection-edit', component: { template: '<p />' } },
      { path: '/connections/:id/key', name: 'key', component: { template: '<p />' } },
      { path: '/connections/:id/tables/:name(.+)', name: 'table', component: { template: '<p />' } },
    ],
  })
  await router.push(path)
  const wrapper = mount(ConnectionTree, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()

  return { wrapper, router, tree: useTreeStore() }
}

const items = (wrapper: Awaited<ReturnType<typeof mountTree>>['wrapper']) => wrapper.findAll('[role="treeitem"]')
const labels = (wrapper: Awaited<ReturnType<typeof mountTree>>['wrapper']) => items(wrapper).map((item) => item.get('.label').text())

describe('ConnectionTree', () => {
  beforeEach(() => mocked.mockReset())

  it('lists every connection of the account as a closed node of a tree', async () => {
    const { wrapper } = await mountTree()

    expect(wrapper.get('[role="tree"]').attributes('aria-label')).toBe('Connexions')
    expect(labels(wrapper)).toEqual(['Production', 'Recette'])
    expect(items(wrapper)[0]?.attributes('aria-level')).toBe('1')
    expect(items(wrapper)[0]?.attributes('aria-expanded')).toBe('false')
    expect(items(wrapper)[0]?.get('.dot').attributes('aria-label')).toContain('fermée')
    expect(mocked).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('says when there is no connection yet', async () => {
    const { wrapper } = await mountTree([])

    expect(wrapper.text()).toContain('Aucune connexion')
    wrapper.unmount()
  })

  it('opens a connection with one click and closes it with the second', async () => {
    const { wrapper } = await mountTree()
    mocked.mockResolvedValueOnce(twoDatabases)

    await items(wrapper)[0]?.get('.row').trigger('click')
    await flushPromises()

    expect(labels(wrapper)).toEqual(['Production', 'shop', 'reporting', 'Recette'])
    expect(items(wrapper)[0]?.attributes('aria-expanded')).toBe('true')
    expect(items(wrapper)[0]?.get('.dot').attributes('aria-label')).toContain('ouverte')
    expect(items(wrapper)[1]?.attributes('aria-level')).toBe('2')

    await items(wrapper)[0]?.get('.row').trigger('click')
    await flushPromises()

    expect(labels(wrapper)).toEqual(['Production', 'Recette'])
    expect(items(wrapper)[0]?.get('.dot').attributes('aria-label')).toContain('fermée')
    wrapper.unmount()
  })

  it('shows the tables of a database under it, linking to the table in that database', async () => {
    const { wrapper } = await mountTree()
    mocked.mockResolvedValueOnce(twoDatabases)
    await items(wrapper)[0]?.get('.row').trigger('click')
    await flushPromises()
    mocked.mockResolvedValueOnce(page(['events', 'visits']))

    await items(wrapper)[2]?.get('.row').trigger('click')
    await flushPromises()

    expect(labels(wrapper)).toEqual(['Production', 'shop', 'reporting', 'events', 'visits', 'Recette'])
    expect(items(wrapper)[3]?.attributes('aria-level')).toBe('3')
    expect(items(wrapper)[3]?.get('a').attributes('href')).toBe('/connections/c1/tables/events?database=reporting')
    wrapper.unmount()
  })

  it('puts the tables straight under a connection that has one database, linking without a database', async () => {
    const { wrapper } = await mountTree()
    mocked.mockResolvedValueOnce({ databases: [{ name: 'shop', system: false }] }).mockResolvedValueOnce(page(['orders']))

    await items(wrapper)[0]?.get('.row').trigger('click')
    await flushPromises()

    expect(labels(wrapper)).toEqual(['Production', 'orders', 'Recette'])
    expect(items(wrapper)[1]?.attributes('aria-level')).toBe('2')
    expect(items(wrapper)[1]?.get('a').attributes('href')).toBe('/connections/c1/tables/orders')
    wrapper.unmount()
  })

  it('marks the table that is open in the workspace', async () => {
    const { wrapper } = await mountTree(undefined, '/connections/c1/tables/orders')
    mocked.mockResolvedValueOnce({ databases: [{ name: 'shop', system: false }] }).mockResolvedValueOnce(page(['items', 'orders']))

    await items(wrapper)[0]?.get('.row').trigger('click')
    await flushPromises()

    const current = items(wrapper).filter((item) => item.attributes('aria-current') === 'page')
    expect(current).toHaveLength(1)
    expect(current[0]?.get('.label').text()).toBe('orders')
    wrapper.unmount()
  })

  it('offers to load the next hundred tables, with the count of what is loaded', async () => {
    const { wrapper } = await mountTree()
    mocked.mockResolvedValueOnce({ databases: [{ name: 'shop', system: false }] }).mockResolvedValueOnce(page(['a', 'b'], 'b', 240))
    await items(wrapper)[0]?.get('.row').trigger('click')
    await flushPromises()
    mocked.mockResolvedValueOnce(page(['c'], null, null))

    const more = wrapper.get('[data-kind="more"]')
    expect(more.text()).toContain('100 suivantes')
    await more.get('.row').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/c1/tables?limit=100&cursor=b')
    expect(labels(wrapper)).toEqual(['Production', 'a', 'b', 'c', 'Recette'])
    expect(wrapper.find('[data-kind="more"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('browses the keys of a Redis connection inside the tree', async () => {
    const { wrapper } = await mountTree([connection('r1', 'Cache', 'redis')])
    mocked.mockResolvedValueOnce({ keys: [{ name: 'user:1', type: 'string', ttl: null }], nextCursor: null, total: 1 })

    await items(wrapper)[0]?.get('.row').trigger('click')
    await flushPromises()

    expect(labels(wrapper)).toEqual(['Cache', 'user:1'])
    expect(items(wrapper)[1]?.get('a').attributes('href')).toBe('/connections/r1/key?name=user:1')
    wrapper.unmount()
  })

  it('shows a generic message when a connection cannot be reached', async () => {
    const { wrapper } = await mountTree()
    mocked.mockRejectedValueOnce(new ApiError(502, 'target_unreachable'))

    await items(wrapper)[0]?.get('.row').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('joindre')
    expect(items(wrapper)[0]?.get('.dot').attributes('aria-label')).toContain('injoignable')
    expect(labels(wrapper)).toEqual(['Production', 'Recette'])
    wrapper.unmount()
  })

  describe('keyboard', () => {
    const press = async (wrapper: Awaited<ReturnType<typeof mountTree>>['wrapper'], index: number, key: string) => {
      await items(wrapper)[index]?.trigger('keydown', { key })
      await flushPromises()
    }
    const focused = () => document.activeElement?.getAttribute('data-key')

    it('keeps a single tab stop (roving tabindex)', async () => {
      const { wrapper } = await mountTree()

      expect(items(wrapper).filter((item) => item.attributes('tabindex') === '0')).toHaveLength(1)
      expect(items(wrapper)[1]?.attributes('tabindex')).toBe('-1')
      wrapper.unmount()
    })

    it('moves with the arrows, Home and End', async () => {
      const { wrapper } = await mountTree([connection('c1', 'A'), connection('c2', 'B'), connection('c3', 'C')])

      await press(wrapper, 0, 'ArrowDown')
      expect(focused()).toBe('c:c2')
      await press(wrapper, 1, 'End')
      expect(focused()).toBe('c:c3')
      await press(wrapper, 2, 'ArrowUp')
      expect(focused()).toBe('c:c2')
      await press(wrapper, 1, 'Home')
      expect(focused()).toBe('c:c1')
      wrapper.unmount()
    })

    it('opens with the right arrow, then goes into the children; closes with the left arrow, then back to the parent', async () => {
      const { wrapper } = await mountTree()
      mocked.mockResolvedValueOnce(twoDatabases)

      await press(wrapper, 0, 'ArrowRight')
      expect(items(wrapper)[0]?.attributes('aria-expanded')).toBe('true')
      await press(wrapper, 0, 'ArrowRight')
      expect(focused()).toBe('d:c1:shop')
      await press(wrapper, 1, 'ArrowLeft')
      expect(focused()).toBe('c:c1')
      await press(wrapper, 0, 'ArrowLeft')
      expect(items(wrapper)[0]?.attributes('aria-expanded')).toBe('false')
      wrapper.unmount()
    })

    it('activates with Enter: opens a connection, opens a table', async () => {
      const { wrapper, router } = await mountTree()
      mocked.mockResolvedValueOnce({ databases: [{ name: 'shop', system: false }] }).mockResolvedValueOnce(page(['orders']))

      await press(wrapper, 0, 'Enter')
      expect(labels(wrapper)).toEqual(['Production', 'orders', 'Recette'])
      await press(wrapper, 1, 'Enter')

      expect(router.currentRoute.value.fullPath).toBe('/connections/c1/tables/orders')
      wrapper.unmount()
    })
  })

  describe('actions on a connection', () => {
    it('opens a new console, the edit form', async () => {
      const { wrapper, router } = await mountTree()

      await items(wrapper)[0]?.get('button.console').trigger('click')
      await flushPromises()
      expect(router.currentRoute.value.fullPath).toBe('/connections/c1')

      await items(wrapper)[1]?.get('button.edit').trigger('click')
      await flushPromises()
      expect(router.currentRoute.value.fullPath).toBe('/connections/c2/edit')
      expect(mocked).not.toHaveBeenCalled()
      wrapper.unmount()
    })

    it('asks before deleting, then deletes and forgets the connection', async () => {
      const { wrapper, tree } = await mountTree()
      mocked.mockResolvedValueOnce(twoDatabases)
      await items(wrapper)[0]?.get('.row').trigger('click')
      await flushPromises()

      await items(wrapper)[0]?.get('button.delete').trigger('click')
      expect(wrapper.get('[role="dialog"]').text()).toContain('Production')
      mocked.mockResolvedValueOnce(undefined)
      await wrapper.get('button.confirm').trigger('click')
      await flushPromises()

      expect(mocked).toHaveBeenLastCalledWith('DELETE', '/web/connections/c1')
      expect(labels(wrapper)).toEqual(['Recette'])
      expect(tree.databasesOf('c1')).toBeNull()
      expect(wrapper.emitted('deleted')).toEqual([['c1']])
      wrapper.unmount()
    })

    it('keeps the connection when the deletion is cancelled', async () => {
      const { wrapper } = await mountTree()

      await items(wrapper)[0]?.get('button.delete').trigger('click')
      await wrapper.get('button.cancel').trigger('click')

      expect(labels(wrapper)).toEqual(['Production', 'Recette'])
      expect(mocked).not.toHaveBeenCalled()
      wrapper.unmount()
    })

    it('has keyboard shortcuts: n for a new console, e to edit, Delete to delete', async () => {
      const { wrapper, router } = await mountTree()

      await items(wrapper)[0]?.trigger('keydown', { key: 'n' })
      await flushPromises()
      expect(router.currentRoute.value.fullPath).toBe('/connections/c1')
      await items(wrapper)[0]?.trigger('keydown', { key: 'e' })
      await flushPromises()
      expect(router.currentRoute.value.fullPath).toBe('/connections/c1/edit')
      await items(wrapper)[0]?.trigger('keydown', { key: 'Delete' })
      expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
      wrapper.unmount()
    })
  })

  describe('PostgreSQL schemas', () => {
    it('shows base > schema > tables, linking to the table in its schema', async () => {
      const { wrapper } = await mountTree([connection('c1', 'Production', 'postgresql')])
      mocked.mockResolvedValueOnce({ default: 'shop', databases: [{ name: 'shop', system: false }, { name: 'reporting', system: false }] })
      await items(wrapper)[0]?.get('.row').trigger('click')
      await flushPromises()
      mocked.mockResolvedValueOnce({ schemas: [{ name: 'public', system: false }, { name: 'sales', system: false }] })
      await items(wrapper)[2]?.get('.row').trigger('click')
      await flushPromises()
      mocked.mockResolvedValueOnce(page(['invoices']))

      await items(wrapper)[4]?.get('.row').trigger('click')
      await flushPromises()

      expect(labels(wrapper)).toEqual(['Production', 'shop', 'reporting', 'public', 'sales', 'invoices'])
      expect(items(wrapper)[3]?.attributes('aria-level')).toBe('3')
      expect(items(wrapper)[5]?.attributes('aria-level')).toBe('4')
      expect(items(wrapper)[5]?.get('a').attributes('href')).toBe('/connections/c1/tables/invoices?database=reporting&schema=sales')
      wrapper.unmount()
    })

    it('puts the schemas straight under a connection that has one database', async () => {
      const { wrapper } = await mountTree([connection('c1', 'Production', 'postgresql')])
      mocked.mockResolvedValueOnce({ default: 'shop', databases: [{ name: 'shop', system: false }] })
      mocked.mockResolvedValueOnce({ schemas: [{ name: 'public', system: false }, { name: 'sales', system: false }] })

      await items(wrapper)[0]?.get('.row').trigger('click')
      await flushPromises()

      expect(labels(wrapper)).toEqual(['Production', 'public', 'sales'])
      expect(items(wrapper)[1]?.attributes('aria-level')).toBe('2')
      expect(items(wrapper)[1]?.attributes('aria-expanded')).toBe('false')
      wrapper.unmount()
    })

    it('marks the table that is open in its schema only', async () => {
      const { wrapper } = await mountTree([connection('c1', 'Production', 'postgresql')], '/connections/c1/tables/orders?schema=sales')
      mocked.mockResolvedValueOnce({ default: 'shop', databases: [{ name: 'shop', system: false }] })
      mocked.mockResolvedValueOnce({ schemas: [{ name: 'public', system: false }, { name: 'sales', system: false }] })
      await items(wrapper)[0]?.get('.row').trigger('click')
      await flushPromises()
      mocked.mockResolvedValueOnce(page(['orders']))
      await items(wrapper)[1]?.get('.row').trigger('click')
      await flushPromises()
      mocked.mockResolvedValueOnce(page(['orders']))
      await items(wrapper)[3]?.get('.row').trigger('click')
      await flushPromises()

      const current = items(wrapper).filter((item) => item.attributes('aria-current') === 'page')
      expect(current).toHaveLength(1)
      expect(current[0]?.attributes('data-key')).toBe('t:c1::sales:orders')
      wrapper.unmount()
    })
  })

  describe('colors and order', () => {
    const colored = (id: string, name: string, color: ConnectionSummary['color']): ConnectionSummary => ({ ...connection(id, name), color })
    const order = () => useConnectionsStore().items.map((item) => item.id)
    const drag = (event: string) => ({ dataTransfer: { setData: () => undefined, effectAllowed: '', dropEffect: '' }, type: event })

    it('marks a colored connection with its color, and leaves the others plain', async () => {
      const { wrapper } = await mountTree([colored('c1', 'Production', 'red'), connection('c2', 'Recette')])

      expect(items(wrapper)[0]?.find('.swatch').attributes('data-color')).toBe('red')
      expect(items(wrapper)[1]?.find('.swatch').exists()).toBe(false)
      wrapper.unmount()
    })

    it('moves a connection with Alt and the up or down arrow, keeping the focus on it', async () => {
      mocked.mockResolvedValue(undefined)
      const { wrapper } = await mountTree([connection('c1', 'A'), connection('c2', 'B'), connection('c3', 'C')])

      await items(wrapper)[0]?.trigger('keydown', { key: 'ArrowDown', altKey: true })
      await flushPromises()

      expect(order()).toEqual(['c2', 'c1', 'c3'])
      expect(mocked).toHaveBeenCalledWith('PUT', '/web/connections/order', { ids: ['c2', 'c1', 'c3'] })
      expect(document.activeElement?.getAttribute('data-key')).toBe('c:c1')
      await items(wrapper)[1]?.trigger('keydown', { key: 'ArrowUp', altKey: true })
      await flushPromises()
      expect(order()).toEqual(['c1', 'c2', 'c3'])
      wrapper.unmount()
    })

    it('has the connections draggable, and drops one onto the place of another', async () => {
      mocked.mockResolvedValue(undefined)
      const { wrapper } = await mountTree([connection('c1', 'A'), connection('c2', 'B'), connection('c3', 'C')])
      expect(items(wrapper)[0]?.get('.row').attributes('draggable')).toBe('true')

      await items(wrapper)[0]?.get('.row').trigger('dragstart', drag('dragstart'))
      await items(wrapper)[2]?.get('.row').trigger('dragover', drag('dragover'))
      await items(wrapper)[2]?.get('.row').trigger('drop', drag('drop'))
      await flushPromises()

      expect(order()).toEqual(['c2', 'c3', 'c1'])
      expect(mocked).toHaveBeenCalledWith('PUT', '/web/connections/order', { ids: ['c2', 'c3', 'c1'] })
      wrapper.unmount()
    })

    it('ignores a drop that did not start from a connection', async () => {
      const { wrapper } = await mountTree([connection('c1', 'A'), connection('c2', 'B')])

      await items(wrapper)[1]?.get('.row').trigger('drop', drag('drop'))
      await flushPromises()

      expect(order()).toEqual(['c1', 'c2'])
      expect(mocked).not.toHaveBeenCalled()
      wrapper.unmount()
    })
  })
})
