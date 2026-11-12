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
          { path: 'activity', name: 'server-activity', component: { template: '<p class="child">activité</p>' } },
          { path: 'replication', name: 'replication', component: { template: '<p class="child">réplication</p>' } },
          { path: 'audit', name: 'audit', component: { template: '<p class="child">audit</p>' } },
          { path: 'key', name: 'key', component: { template: '<p class="child">clé</p>' } },
          { path: 'diagram', name: 'diagram', component: { template: '<p class="child">diagramme</p>' } },
          { path: 'data/:name(.+)', name: 'table-data', component: { template: '<p class="child">data</p>' } },
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
    Promise.resolve(method === 'DELETE' ? undefined : url === '/web/connections' ? { connections: [summary] } : url.includes('/databases') ? { databases } : url.includes('/schemas') ? { schemas: [{ name: 'public', system: false }, { name: 'sales', system: false }] } : { tables: [] })
}

describe('WorkspaceView', () => {
  beforeEach(() => mocked.mockReset())

  it('loads the connection and its tables, and shows the editor pane', async () => {
    mocked.mockResolvedValueOnce({ connections: [summary] })
    mocked.mockResolvedValueOnce({ tables: [{ name: 'orders', kind: 'table', estimatedRows: 3800 }] })

    const { wrapper } = await mountView()

    expect(wrapper.get('.current').text()).toContain('Production')
    expect(wrapper.get('.child').text()).toBe('éditeur')
    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/tables?limit=100')
  })

  it('reads keys, not tables, on a Redis connection, and shows no panel of its own', async () => {
    mocked.mockResolvedValueOnce({ connections: [{ ...summary, engine: 'redis' }] })
    mocked.mockResolvedValueOnce({ keys: [{ name: 'user:1', type: 'string', ttl: null }], nextCursor: null, total: 1 })

    const { wrapper } = await mountView()

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/keys?pattern=*&count=100')
    expect(mocked).not.toHaveBeenCalledWith('GET', '/web/connections/abc/tables?limit=100')
    expect(wrapper.find('aside').exists()).toBe(false)
    expect(wrapper.find('nav[aria-label="Clés"]').exists()).toBe(false)
    expect(wrapper.find('select.databases').exists()).toBe(false)
    expect(mocked).not.toHaveBeenCalledWith('GET', '/web/connections/abc/databases')
  })

  it('has no database or schema selector of its own: the connections bar is where one chooses', async () => {
    mocked.mockImplementation(answer([{ name: 'shop', system: false }, { name: 'reporting', system: false }]))

    const { wrapper } = await mountView()

    expect(wrapper.find('select.databases').exists()).toBe(false)
    expect(wrapper.find('select.schemas').exists()).toBe(false)
  })

  it('opens the first database itself when the connection names none, so the console has somewhere to run', async () => {
    mocked.mockImplementation((method?: string, url = ''): Promise<unknown> =>
      Promise.resolve(url === '/web/connections' ? { connections: [summary] } : url.includes('/databases') ? { default: null, databases: [{ name: 'shop', system: false }] } : url.includes('/schemas') ? { schemas: [] } : { tables: [] }),
    )

    await mountView()

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/tables?limit=100&database=shop')
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

  it('explores the schema named in the address, so a table of another schema opens there', async () => {
    mocked.mockImplementation(answer([{ name: 'shop', system: false }]))

    await mountView('/connections/abc/tables/invoices?schema=sales')

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/tables?limit=100&schema=sales')
  })

  it('says so when the connection does not exist', async () => {
    mocked.mockResolvedValueOnce({ connections: [summary] })

    const { wrapper } = await mountView('/connections/other')

    expect(wrapper.get('[role="alert"]').text()).toContain('introuvable')
  })

  it('reports an unreachable target above the content instead of breaking the page', async () => {
    mocked.mockResolvedValueOnce({ connections: [summary] })
    mocked.mockRejectedValueOnce(new ApiError(502, 'target_unreachable'))

    const { wrapper } = await mountView()

    expect(wrapper.get('[role="alert"]').text()).toContain('joindre')
    expect(wrapper.get('.current').text()).toContain('Production')
  })

  it('shows the page only once the workspace is open on this connection and its database, so a table never loads against nothing', async () => {
    const pending: ((value: unknown) => void)[] = []
    mocked.mockImplementation((method?: string, url = ''): Promise<unknown> => {
      if (url === '/web/connections') {
        return Promise.resolve({ connections: [summary] })
      }
      if (url.includes('/tables?')) {
        return new Promise((resolve) => { pending.push(resolve) })
      }

      return Promise.resolve(url.includes('/schemas') ? { schemas: [] } : { databases: [], default: 'shop', tables: [] })
    })

    const { wrapper } = await mountView('/connections/abc/tables/orders?database=shop')
    expect(wrapper.find('.child').exists()).toBe(false)

    // Les lectures de tables se suivent (ouverture, puis changement de base) : on les libère au fur et à mesure.
    for (let round = 0; round < 3; round++) {
      pending.splice(0).forEach((release) => release({ tables: [] }))
      await flushPromises()
    }
    expect(wrapper.find('.child').exists()).toBe(true)
  })

  it('links to the console, the diagram, the schema audit and the server activity of a SQL connection', async () => {
    mocked.mockImplementation(answer())

    const { wrapper } = await mountView()

    const links = wrapper.findAll('nav[aria-label="Vues de la connexion"] a')
    expect(links.map((link) => link.text())).toEqual(['Console', 'Diagramme', 'Audit du schéma', 'Activité', 'Réplication'])
    expect(links.map((link) => link.attributes('href'))).toEqual(['/connections/abc', '/connections/abc/diagram', '/connections/abc/audit', '/connections/abc/activity', '/connections/abc/replication'])
  })

  it('puts the connection views on one line and the open tables, like browser tabs, on the next', async () => {
    mocked.mockImplementation(answer())
    const { wrapper } = await mountView('/connections/abc/tables/orders?database=shop')

    expect(wrapper.findAll('nav[aria-label="Vues de la connexion"] a').map((link) => link.text())).toEqual(['Console', 'Diagramme', 'Audit du schéma', 'Activité', 'Réplication'])
    const tabs = wrapper.findAll('[role="tablist"][aria-label="Tables ouvertes"] .tab')
    expect(tabs.map((tab) => tab.get('a.label').text())).toEqual(['orders'])
    expect(tabs[0]?.classes()).toContain('on')
    expect(tabs[0]?.get('a.label').attributes('href')).toBe('/connections/abc/tables/orders?database=shop')
  })

  it('gives the active table its own line of views, Structure and Données', async () => {
    mocked.mockImplementation(answer())
    const { wrapper, router } = await mountView('/connections/abc/tables/orders?database=shop')

    let links = wrapper.findAll('nav[aria-label="Vues de la table orders"] a')
    expect(links.map((link) => link.text())).toEqual(['Structure', 'Données'])
    expect(links[0]?.classes()).toContain('on')
    expect(links[1]?.attributes('href')).toBe('/connections/abc/data/orders?database=shop')

    await links[1]?.trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('table-data')
    links = wrapper.findAll('nav[aria-label="Vues de la table orders"] a')
    expect(links[1]?.classes()).toContain('on')
    // L'onglet rouvre la table là où on l'a laissée.
    expect(wrapper.get('.tab a.label').attributes('href')).toBe('/connections/abc/data/orders?database=shop')
  })

  it('keeps several tables open at once and keeps them when you leave for the console or the diagram', async () => {
    mocked.mockImplementation(answer())
    const { wrapper, router } = await mountView('/connections/abc/tables/orders')

    await router.push('/connections/abc/tables/invoices')
    await flushPromises()
    await router.push('/connections/abc/diagram')
    await flushPromises()

    const tabs = wrapper.findAll('[aria-label="Tables ouvertes"] .tab')
    expect(tabs.map((tab) => tab.get('a.label').text())).toEqual(['orders', 'invoices'])
    expect(tabs.map((tab) => tab.classes().includes('on'))).toEqual([false, false])
    expect(wrapper.find('nav[aria-label^="Vues de la table"]').exists()).toBe(false)
  })

  it('does not open a table twice', async () => {
    mocked.mockImplementation(answer())
    const { wrapper, router } = await mountView('/connections/abc/tables/orders')

    await router.push('/connections/abc/data/orders')
    await router.push('/connections/abc/tables/orders')
    await flushPromises()

    expect(wrapper.findAll('[aria-label="Tables ouvertes"] .tab')).toHaveLength(1)
  })

  it('closes a tab and lands on the one next to it, then on the console when none is left', async () => {
    mocked.mockImplementation(answer())
    const { wrapper, router } = await mountView('/connections/abc/tables/orders')
    await router.push('/connections/abc/tables/invoices')
    await flushPromises()

    await wrapper.get('button[aria-label="Fermer invoices"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.params.name).toBe('orders')
    expect(wrapper.findAll('[aria-label="Tables ouvertes"] .tab')).toHaveLength(1)

    await wrapper.get('button[aria-label="Fermer orders"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('workspace')
    expect(wrapper.find('[aria-label="Tables ouvertes"]').exists()).toBe(false)
  })

  it('closing the data tab of the last table leaves no orphan query tab behind', async () => {
    mocked.mockImplementation(answer())
    const { wrapper, router } = await mountView('/connections/abc/data/orders')
    const { useWorkspaceStore } = await import('../stores/workspace')
    const store = useWorkspaceStore()
    store.openTableData('orders', 'SELECT 1')
    store.tabs = store.tabs.filter((tab) => tab.table === 'orders')

    await wrapper.get('button[aria-label="Fermer orders"]').trigger('click')
    await flushPromises()

    expect(store.tabs.some((tab) => tab.table === 'orders')).toBe(false)
    expect(store.tabs).toHaveLength(1)
    expect(router.currentRoute.value.name).toBe('workspace')
  })

  it('closing a tab you are not on leaves you where you are', async () => {
    mocked.mockImplementation(answer())
    const { wrapper, router } = await mountView('/connections/abc/tables/orders')
    await router.push('/connections/abc/tables/invoices')
    await flushPromises()

    await wrapper.get('button[aria-label="Fermer orders"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.params.name).toBe('invoices')
  })

  it('shows no table tabs before any table has been opened', async () => {
    mocked.mockImplementation(answer())

    const { wrapper } = await mountView('/connections/abc/diagram')

    expect(wrapper.find('[aria-label="Tables ouvertes"]').exists()).toBe(false)
  })

  it('tints the views menu with the colour of the open connection, and not at all when it has none', async () => {
    mocked.mockImplementation((method?: string, url = ''): Promise<unknown> => Promise.resolve(url === '/web/connections' ? { connections: [{ ...summary, color: 'violet' }] } : url.includes('/schemas') ? { schemas: [] } : { databases: [], default: 'shop', tables: [] }))
    const colored = await mountView()
    expect(colored.wrapper.get('nav.views').attributes('data-color')).toBe('violet')

    mocked.mockImplementation(answer())
    const plain = await mountView()
    expect(plain.wrapper.get('nav.views').attributes('data-color')).toBeUndefined()
  })

  it('offers no diagram or audit on a key-value connection, which has no schema to draw', async () => {
    mocked.mockResolvedValueOnce({ connections: [{ ...summary, engine: 'redis' }] })
    mocked.mockResolvedValueOnce({ keys: [], nextCursor: null, total: 0 })

    const { wrapper } = await mountView()

    expect(wrapper.find('nav[aria-label="Vues de la connexion"]').exists()).toBe(false)
  })

  it('has no tables panel: the content comes first and the connections bar sits on the right', async () => {
    mocked.mockImplementation(answer())

    const { wrapper } = await mountView()

    const children = Array.from(wrapper.get('.body').element.children)
    expect(children.map((child) => child.tagName.toLowerCase() + (child.classList.contains('content') ? '.content' : ''))).toEqual(['div.content', 'nav'])
  })
})
