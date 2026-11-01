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
  useSessionStore().user = { id: '1', username: 'alice', email: 'a@exemple.test', totpEnabled: false, recoveryCodesLeft: 0, createdAt: '2026-11-01T10:00:00+00:00' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/connections', name: 'connections', component: { template: '<p />' } },
      { path: '/security', name: 'security', component: { template: '<p />' } },
      { path: '/login', name: 'login', component: { template: '<p />' } },
      {
        path: '/connections/:id',
        component: WorkspaceView,
        props: true,
        children: [
          { path: '', name: 'workspace', component: { template: '<p class="child">éditeur</p>' } },
          { path: 'audit', name: 'audit', component: { template: '<p class="child">audit</p>' } },
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

describe('WorkspaceView', () => {
  beforeEach(() => mocked.mockReset())

  it('loads the connection and its tables, and shows the editor pane', async () => {
    mocked.mockResolvedValueOnce({ connections: [summary] })
    mocked.mockResolvedValueOnce({ tables: [{ name: 'orders', kind: 'table', estimatedRows: 3800 }] })

    const { wrapper } = await mountView()

    expect(wrapper.get('.current').text()).toContain('Production')
    expect(wrapper.get('a.node').text()).toContain('orders')
    expect(wrapper.get('.child').text()).toBe('éditeur')
    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/tables')
  })

  it('highlights the table that is open', async () => {
    mocked.mockResolvedValueOnce({ connections: [summary] })
    mocked.mockResolvedValueOnce({ tables: [{ name: 'orders', kind: 'table', estimatedRows: 1 }] })

    const { wrapper } = await mountView('/connections/abc/tables/orders')

    expect(wrapper.get('a.node').classes()).toContain('cur')
    expect(wrapper.get('.child').text()).toBe('table')
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
