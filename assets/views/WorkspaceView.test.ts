import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import { useSessionStore } from '../stores/session'
import WorkspaceView from './WorkspaceView.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const summary = { id: 'abc', name: 'Production', engine: 'postgresql', createdAt: '', updatedAt: '' }

async function mountView(path = '/connections/abc') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().user = { id: '1', username: 'alice', email: 'a@exemple.test', totpEnabled: false, recoveryCodesLeft: 0, role: 'admin', recoveryPhrase: false, createdAt: '2026-11-01T10:00:00+00:00' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/connections', name: 'connections', component: { template: '<p />' } },
      { path: '/connections/new', name: 'connection-new', component: { template: '<p />' } },
      { path: '/connections/:id/edit', name: 'connection-edit', component: { template: '<p />' } },
      { path: '/workspace', name: 'workspace-home', component: { template: '<p class="home">accueil</p>' } },
      { path: '/security', name: 'security', component: { template: '<p />' } },
      { path: '/login', name: 'login', component: { template: '<p />' } },
      {
        path: '/connections/:id',
        component: WorkspaceView,
        props: true,
        children: [
          { path: '', name: 'workspace', component: { template: '<p class="child">éditeur</p>' } },
          { path: 'audit', name: 'audit', component: { template: '<p class="child">audit</p>' } },
          { path: 'key', name: 'key', component: { template: '<p class="child">clé</p>' } },
          { path: 'diagram', name: 'diagram', component: { template: '<p class="child">diagramme</p>' } },
          { path: 'tables/:name(.+)', name: 'table', component: { template: '<p class="child">table</p>' } },
        ],
      },
    ],
  })
  await router.push(path)
  const wrapper = mount({ template: '<RouterView />' }, { global: { plugins: [pinia, router] } })
  await flushPromises()

  return { wrapper, router }
}

/** Réponses du serveur par adresse. Vitest rappelle le mock sans argument comme crochet de nettoyage : l'adresse peut manquer. */
function answer(databases: { name: string; system: boolean }[] = []) {
  return (method?: string, url = ''): Promise<unknown> =>
    Promise.resolve(method === 'DELETE' ? undefined : url === '/web/connections' ? { connections: [summary] } : url.includes('/databases') ? { databases } : { tables: [] })
}

describe('WorkspaceView', () => {
  beforeEach(() => mocked.mockReset())

  it('loads the connection and its tables, and shows the editor pane', async () => {
    mocked.mockResolvedValueOnce({ connections: [summary] })
    mocked.mockResolvedValueOnce({ tables: [{ name: 'orders', kind: 'table', estimatedRows: 3800 }] })

    const { wrapper } = await mountView()

    expect(wrapper.get('.current').text()).toContain('Production')
    expect(wrapper.get('a.node').text()).toContain('orders')
    expect(wrapper.get('.child').text()).toBe('éditeur')
    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/tables?limit=100')
  })

  it('highlights the table that is open', async () => {
    mocked.mockResolvedValueOnce({ connections: [summary] })
    mocked.mockResolvedValueOnce({ tables: [{ name: 'orders', kind: 'table', estimatedRows: 1 }] })

    const { wrapper } = await mountView('/connections/abc/tables/orders')

    expect(wrapper.get('a.node').classes()).toContain('cur')
    expect(wrapper.get('.child').text()).toBe('table')
  })

  it('browses keys, not tables, on a Redis connection', async () => {
    mocked.mockResolvedValueOnce({ connections: [{ ...summary, engine: 'redis' }] })
    mocked.mockResolvedValueOnce({ keys: [{ name: 'user:1', type: 'string', ttl: null }], nextCursor: null, total: 1 })

    const { wrapper } = await mountView()

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/keys?pattern=*&count=100')
    expect(mocked).not.toHaveBeenCalledWith('GET', '/web/connections/abc/tables?limit=100')
    expect(wrapper.find('nav[aria-label="Clés"]').exists()).toBe(true)
    expect(wrapper.find('nav[aria-label="Schéma"]').exists()).toBe(false)
    expect(wrapper.find('select.databases').exists()).toBe(false)
    expect(mocked).not.toHaveBeenCalledWith('GET', '/web/connections/abc/databases')
    expect(wrapper.get('a.node').text()).toContain('user:1')
  })

  it('offers the database picker on a SQL connection, not on a key-value one', async () => {
    mocked.mockResolvedValueOnce({ connections: [summary] })
    mocked.mockResolvedValueOnce({ tables: [] })
    mocked.mockResolvedValueOnce({ databases: [{ name: 'shop', system: false }, { name: 'reporting', system: false }] })

    const { wrapper } = await mountView()

    expect(wrapper.find('select.databases').exists()).toBe(true)
    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/databases')
  })

  it('keeps the connections sidebar on every page of the workspace', async () => {
    for (const path of ['/connections/abc', '/connections/abc/audit', '/connections/abc/diagram', '/connections/abc/tables/orders']) {
      mocked.mockReset()
      mocked.mockImplementation(answer())

      const { wrapper } = await mountView(path)

      expect(wrapper.find('nav.workbench [role="tree"]').exists(), path).toBe(true)
      wrapper.unmount()
    }
  })

  it('goes back to the workspace home when the open connection is deleted from the tree', async () => {
    mocked.mockImplementation(answer())
    const { wrapper, router } = await mountView('/connections/abc')

    await wrapper.get('nav.workbench button.delete').trigger('click')
    await wrapper.get('button.confirm').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('workspace-home')
  })

  it('explores the database named in the address, so a table of another database opens there', async () => {
    mocked.mockImplementation(answer([{ name: 'shop', system: false }, { name: 'reporting', system: false }]))

    await mountView('/connections/abc/tables/events?database=reporting')

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/tables?limit=100&database=reporting')
  })

  it('says so when the connection does not exist', async () => {
    mocked.mockResolvedValueOnce({ connections: [summary] })

    const { wrapper } = await mountView('/connections/other')

    expect(wrapper.get('[role="alert"]').text()).toContain('introuvable')
  })

  it('reports an unreachable target in the sidebar instead of breaking the page', async () => {
    mocked.mockResolvedValueOnce({ connections: [summary] })
    mocked.mockRejectedValueOnce(new ApiError(502, 'target_unreachable'))

    const { wrapper } = await mountView()

    expect(wrapper.get('[role="alert"]').text()).toContain('joindre')
    expect(wrapper.get('.current').text()).toContain('Production')
  })
})
