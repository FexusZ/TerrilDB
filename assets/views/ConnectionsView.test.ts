import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import type { ConnectionSummary } from '../api/types'
import { useSessionStore } from '../stores/session'
import ConnectionsView from './ConnectionsView.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const make = (id: string, name: string, engine: ConnectionSummary['engine']): ConnectionSummary => ({ id, name, engine, createdAt: '2026-10-01T10:00:00+00:00', updatedAt: '2026-10-31T10:00:00+00:00' })
const connections = [make('a', 'Boutique, production', 'postgresql'), make('b', 'Boutique, recette', 'mysql'), make('c', 'Catalogue produits', 'mariadb')]

function namedRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: ['connections', 'connection-new', 'connection-edit', 'workspace', 'security', 'login', 'workspace-home'].map((name) => ({
      path: name === 'connection-edit' ? '/connections/:id/edit' : name === 'workspace' ? '/connections/:id' : `/${name}`,
      name,
      component: { template: '<p />' },
    })),
  })
}

async function mountView(list: ConnectionSummary[] = connections) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().user = { id: '1', username: 'alice', email: 'a@exemple.test', totpEnabled: false, recoveryCodesLeft: 0, role: 'admin', recoveryPhrase: false, createdAt: '2026-11-01T10:00:00+00:00' }
  mocked.mockResolvedValueOnce({ connections: list })
  const router = namedRouter()
  await router.push('/connections')
  const wrapper = mount(ConnectionsView, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()

  return { wrapper, router }
}

describe('ConnectionsView', () => {
  beforeEach(() => mocked.mockReset())

  it('lists the connections with their engine and a reminder that they are encrypted', async () => {
    const { wrapper } = await mountView()

    const cards = wrapper.findAll('article')
    expect(cards).toHaveLength(3)
    expect(cards[0]?.text()).toContain('Boutique, production')
    expect(cards[0]?.text()).toContain('PostgreSQL')
    expect(cards[0]?.text()).toContain('Chiffrée')
    expect(cards[0]?.text()).toMatch(/Modifiée le .*2026/)
    expect(wrapper.text()).toContain('3 bases enregistrées')
    wrapper.unmount()
  })

  it('opens a connection in the workspace and edits it', async () => {
    const { wrapper } = await mountView()
    const card = wrapper.findAll('article')[0]

    expect(card?.get('a.open').attributes('href')).toBe('/connections/a')
    expect(card?.get('a.edit').attributes('href')).toBe('/connections/a/edit')
    wrapper.unmount()
  })

  it('filters by engine', async () => {
    const { wrapper } = await mountView()

    await wrapper.get('button[data-engine="mysql"]').trigger('click')

    expect(wrapper.findAll('article').map((card) => card.text())).toEqual([expect.stringContaining('Boutique, recette')])
    expect(wrapper.get('button[data-engine="mysql"]').attributes('aria-pressed')).toBe('true')

    await wrapper.get('button[data-engine="all"]').trigger('click')
    expect(wrapper.findAll('article')).toHaveLength(3)
    wrapper.unmount()
  })

  it('filters ClickHouse connections too', async () => {
    const { wrapper } = await mountView([...connections, make('d', 'Analytique', 'clickhouse')])

    await wrapper.get('button[data-engine="clickhouse"]').trigger('click')

    expect(wrapper.findAll('article').map((card) => card.text())).toEqual([expect.stringContaining('Analytique')])
    expect(wrapper.get('article .badge').text()).toBe('Ch')
    wrapper.unmount()
  })

  it('filters Redis connections too', async () => {
    const { wrapper } = await mountView([...connections, make('e', 'Cache', 'redis')])

    await wrapper.get('button[data-engine="redis"]').trigger('click')

    expect(wrapper.findAll('article').map((card) => card.text())).toEqual([expect.stringContaining('Cache')])
    expect(wrapper.get('article .badge').text()).toBe('Rd')
    wrapper.unmount()
  })

  it('searches by name, ignoring case and accents', async () => {
    const { wrapper } = await mountView()

    await wrapper.get('input[type="search"]').setValue('CATALOGUE')

    expect(wrapper.findAll('article')).toHaveLength(1)
    await wrapper.get('input[type="search"]').setValue('zzz')
    expect(wrapper.findAll('article')).toHaveLength(0)
    expect(wrapper.text()).toContain('Aucune connexion ne correspond')
    wrapper.unmount()
  })

  it('invites to add a first database when there is none', async () => {
    const { wrapper } = await mountView([])

    expect(wrapper.text()).toContain('Aucune connexion')
    expect(wrapper.find('a[href="/connection-new"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('asks before deleting, then deletes', async () => {
    const { wrapper } = await mountView()

    await wrapper.findAll('article')[1]?.get('button.delete').trigger('click')
    expect(wrapper.get('[role="dialog"]').text()).toContain('Boutique, recette')
    expect(mocked).toHaveBeenCalledTimes(1)

    mocked.mockResolvedValueOnce(undefined)
    await wrapper.get('button.confirm').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('DELETE', '/web/connections/b')
    expect(wrapper.findAll('article')).toHaveLength(2)
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('keeps the connection when the deletion is cancelled', async () => {
    const { wrapper } = await mountView()

    await wrapper.findAll('article')[1]?.get('button.delete').trigger('click')
    await wrapper.get('button.cancel').trigger('click')

    expect(wrapper.findAll('article')).toHaveLength(3)
    expect(mocked).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('says so when the list cannot be loaded', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    mocked.mockRejectedValueOnce(new ApiError(0, 'network'))
    const router = namedRouter()
    const wrapper = mount(ConnectionsView, { global: { plugins: [pinia, router] } })
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('serveur')
  })
})
