import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import type { ConnectionSummary } from '../api/types'
import { useConnectionsStore } from '../stores/connections'
import { useSessionStore } from '../stores/session'
import WorkspaceHomeView from './WorkspaceHomeView.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const connection: ConnectionSummary = { id: 'c1', name: 'Production', engine: 'postgresql', createdAt: '', updatedAt: '' }

async function mountHome(loaded: ConnectionSummary[] | ApiError | null = [connection]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().user = { id: '1', username: 'alice', email: 'a@exemple.test', totpEnabled: false, recoveryCodesLeft: 0, role: 'admin', recoveryPhrase: false, createdAt: '2026-11-01T10:00:00+00:00' }
  if (loaded instanceof ApiError) {
    mocked.mockRejectedValueOnce(loaded)
  } else if (loaded !== null) {
    mocked.mockResolvedValueOnce({ connections: loaded })
  } else {
    useConnectionsStore().items = [connection]
    useConnectionsStore().loaded = true
  }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['workspace-home', 'connections', 'connection-new', 'connection-edit', 'workspace', 'table', 'key', 'security', 'login'].map((name) => ({ path: `/${name}/:id?/:name?`, name, component: { template: '<p />' } })),
  })
  await router.push('/workspace-home')
  const wrapper = mount(WorkspaceHomeView, { global: { plugins: [pinia, router] } })
  await flushPromises()

  return wrapper
}

describe('WorkspaceHomeView', () => {
  beforeEach(() => mocked.mockReset())

  it('loads the connections and shows them in the permanent sidebar', async () => {
    const wrapper = await mountHome()

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections')
    expect(wrapper.get('[role="tree"]').text()).toContain('Production')
    expect(wrapper.get('main, .content').text()).toContain('Choisissez une connexion')
  })

  it('does not reload connections that are already known', async () => {
    await mountHome(null)

    expect(mocked).not.toHaveBeenCalled()
  })

  it('invites to add a first database when there is none', async () => {
    const wrapper = await mountHome([])

    expect(wrapper.get('.content').text()).toContain('Ajoutez')
    expect(wrapper.get('.content a.add-first').attributes('href')).toBe('/connection-new')
  })

  it('says why the connections could not be loaded', async () => {
    const wrapper = await mountHome(new ApiError(502, 'network'))

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('puts the connections bar on the left, before the content', async () => {
    const wrapper = await mountHome()

    const children = Array.from(wrapper.get('.body').element.children)
    expect(children[0]?.matches('nav.workbench')).toBe(true)
    expect(children[children.length - 1]?.matches('main.content')).toBe(true)
  })
})
