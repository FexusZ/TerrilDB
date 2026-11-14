import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import type { ApiTokenInfo, ConnectionSummary } from '../api/types'
import { useSessionStore } from '../stores/session'
import TokensView from './TokensView.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const connection = (id: string, name: string): ConnectionSummary => ({ id, name, engine: 'postgresql', createdAt: '', updatedAt: '' })
const token = (id: string, name: string, extra: Partial<ApiTokenInfo> = {}): ApiTokenInfo => ({
  id, name, connectionIds: ['c1'], allConnections: false, writeData: false, writeSchema: false, createConnections: false, diagnostic: false, singleStep: false, createdAt: '2026-11-01T10:00:00+00:00', expiresAt: '2099-12-01T10:00:00+00:00', lastUsedAt: null, ...extra,
})

async function mountView(tokens: ApiTokenInfo[] = [], connections: ConnectionSummary[] = [connection('c1', 'Production'), connection('c2', 'Recette')]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().user = { id: '1', username: 'alice', email: 'a@exemple.test', totpEnabled: false, recoveryCodesLeft: 0, role: 'admin', recoveryPhrase: false, createdAt: '2026-11-01T10:00:00+00:00' }
  mocked.mockResolvedValueOnce({ connections })
  mocked.mockResolvedValueOnce({ tokens })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['connections', 'connection-new', 'security', 'login', 'tokens', 'workspace-home'].map((name) => ({ path: `/${name}`, name, component: { template: '<p />' } })),
  })
  await router.push('/tokens')
  const wrapper = mount(TokensView, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()

  return wrapper
}

describe('TokensView', () => {
  beforeEach(() => mocked.mockReset())

  it('explains what a token is for, and its limits', async () => {
    const wrapper = await mountView()

    expect(wrapper.get('h1').text()).toContain('Jetons')
    expect(wrapper.text()).toContain('lecture seule')
    expect(wrapper.text()).toContain('mot de passe')
    expect(wrapper.text()).toContain('/mcp')
    expect(wrapper.text()).toContain('/api/v1')
    wrapper.unmount()
  })

  it('lists the existing tokens with their connections, expiry and last use', async () => {
    const wrapper = await mountView([token('a', 'Assistant', { lastUsedAt: '2026-11-02T08:00:00+00:00' }), token('b', 'CI', { connectionIds: ['c1', 'c2'] })])

    const rows = wrapper.findAll('li.token')
    expect(rows).toHaveLength(2)
    expect(rows[0]?.text()).toContain('Assistant')
    expect(rows[0]?.text()).toContain('Production')
    expect(rows[0]?.text()).toMatch(/Dernière utilisation .*2026/)
    expect(rows[0]?.text()).toMatch(/Expire le .*2099/)
    expect(rows[1]?.text()).toContain('Production, Recette')
    expect(rows[1]?.text()).toContain('Jamais utilisé')
    wrapper.unmount()
  })

  it('marks an expired token', async () => {
    const wrapper = await mountView([token('a', 'Old', { expiresAt: '2020-01-01T00:00:00+00:00' })])

    expect(wrapper.get('li.token').text()).toContain('Expiré')
    wrapper.unmount()
  })

  it('lets you create a token before any connection exists', async () => {
    const wrapper = await mountView([], [])

    expect(wrapper.find('form.create').exists()).toBe(true)
    expect(wrapper.text()).toContain('Aucune connexion pour l’instant')
    wrapper.unmount()
  })

  it('is read-only by default and offers three rights to tick', async () => {
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('lecture seule par défaut')
    for (const id of ['#token-write-data', '#token-write-schema', '#token-create-connections']) {
      expect((wrapper.get(id).element as HTMLInputElement).checked).toBe(false)
    }
    wrapper.unmount()
  })

  it('sends the ticked rights, and only those', async () => {
    const wrapper = await mountView()
    await wrapper.get('#token-name').setValue('Assistant')
    await wrapper.get('input[value="c1"]').setValue(true)
    await wrapper.get('#token-write-data').setValue(true)
    await wrapper.get('#token-create-connections').setValue(true)
    mocked.mockResolvedValueOnce({ token: 'tdb_x', tokenInfo: token('n', 'Assistant', { writeData: true, createConnections: true }) })

    await wrapper.get('form.create').trigger('submit')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/tokens', { name: 'Assistant', connectionIds: ['c1'], lifetimeDays: 30, writeData: true, createConnections: true })
    wrapper.unmount()
  })

  it('offers the diagnostic right, unticked, and sends it only when ticked', async () => {
    const wrapper = await mountView()
    expect((wrapper.get('#token-diagnostic').element as HTMLInputElement).checked).toBe(false)
    await wrapper.get('#token-name').setValue('Supervision')
    await wrapper.get('input[value="c1"]').setValue(true)
    await wrapper.get('#token-diagnostic').setValue(true)
    mocked.mockResolvedValueOnce({ token: 'tdb_x', tokenInfo: token('n', 'Supervision', { diagnostic: true }) })

    await wrapper.get('form.create').trigger('submit')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/tokens', { name: 'Supervision', connectionIds: ['c1'], lifetimeDays: 30, diagnostic: true })
    wrapper.unmount()
  })

  it('shows that a token reads the server diagnostics while staying read-only', async () => {
    const wrapper = await mountView([token('a', 'Supervision', { diagnostic: true })])

    const text = wrapper.get('li.token').text()
    expect(text).toContain('Lit la supervision du serveur')
    expect(text).toContain('Lecture seule')
    wrapper.unmount()
  })

  it('accepts a token with no connection when it can create them', async () => {
    const wrapper = await mountView([], [])
    await wrapper.get('#token-name').setValue('Assistant')
    await wrapper.get('#token-create-connections').setValue(true)
    mocked.mockResolvedValueOnce({ token: 'tdb_x', tokenInfo: token('n', 'Assistant', { connectionIds: [], createConnections: true }) })

    await wrapper.get('form.create').trigger('submit')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/tokens', { name: 'Assistant', connectionIds: [], lifetimeDays: 30, createConnections: true })
    expect(wrapper.find('form.create [role="alert"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('accepts a token for all connections even when there are none yet', async () => {
    const wrapper = await mountView([], [])
    await wrapper.get('#token-name').setValue('Assistant')
    await wrapper.get('#token-all').setValue(true)
    mocked.mockResolvedValueOnce({ token: 'tdb_x', tokenInfo: token('n', 'Assistant', { allConnections: true, connectionIds: [] }) })

    await wrapper.get('form.create').trigger('submit')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/tokens', { name: 'Assistant', allConnections: true, lifetimeDays: 30 })
    wrapper.unmount()
  })

  it('shows the rights a token holds, and read-only when it holds none', async () => {
    const wrapper = await mountView([
      token('a', 'Lecteur'),
      token('b', 'Plein', { writeData: true, writeSchema: true, createConnections: true }),
    ])

    const rows = wrapper.findAll('li.token')
    expect(rows[0]?.text()).toContain('Lecture seule')
    expect(rows[1]?.text()).toContain('Modifie les données')
    expect(rows[1]?.text()).toContain('Modifie la structure')
    expect(rows[1]?.text()).toContain('Crée des connexions')
    expect(rows[1]?.text()).not.toContain('Lecture seule')
    wrapper.unmount()
  })

  it('creates a token for the chosen connections and shows its text once', async () => {
    const wrapper = await mountView()
    await wrapper.get('#token-name').setValue('Assistant')
    await wrapper.get('input[value="c2"]').setValue(true)
    await wrapper.get('#token-lifetime').setValue('90')
    mocked.mockResolvedValueOnce({ token: 'tdb_0123_secret', tokenInfo: token('n', 'Assistant', { connectionIds: ['c2'] }) })

    await wrapper.get('form.create').trigger('submit')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/tokens', { name: 'Assistant', connectionIds: ['c2'], lifetimeDays: 90 })
    expect(wrapper.get('.fresh code').text()).toBe('tdb_0123_secret')
    expect(wrapper.get('.fresh').text()).toContain('ne sera plus affiché')

    await wrapper.get('button.copied').trigger('click')
    expect(wrapper.find('.fresh').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('tdb_0123_secret')
    expect(wrapper.findAll('li.token')).toHaveLength(1)
    wrapper.unmount()
  })

  it('creates a token for all connections, without sending a list', async () => {
    const wrapper = await mountView()
    await wrapper.get('#token-name').setValue('Assistant')
    await wrapper.get('#token-all').setValue(true)
    expect(wrapper.find('input[value="c1"]').exists()).toBe(false)
    mocked.mockResolvedValueOnce({ token: 'tdb_0123_secret', tokenInfo: token('n', 'Assistant', { allConnections: true, connectionIds: ['c1', 'c2'] }) })

    await wrapper.get('form.create').trigger('submit')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/tokens', { name: 'Assistant', allConnections: true, lifetimeDays: 30 })
    expect(wrapper.get('.fresh code').text()).toBe('tdb_0123_secret')
    wrapper.unmount()
  })

  it('says a token covers all connections and offers to refresh only those', async () => {
    const wrapper = await mountView([token('a', 'Tout', { allConnections: true, connectionIds: ['c1'] }), token('b', 'Fixe')])

    const rows = wrapper.findAll('li.token')
    expect(rows[0]?.text()).toContain('Toutes mes connexions')
    expect(rows[0]?.find('button.refresh').exists()).toBe(true)
    expect(rows[1]?.find('button.refresh').exists()).toBe(false)
    wrapper.unmount()
  })

  it('refreshes a token and shows its new connections', async () => {
    const wrapper = await mountView([token('a', 'Tout', { allConnections: true, connectionIds: ['c1'] })])
    mocked.mockResolvedValueOnce({ tokenInfo: token('a', 'Tout', { allConnections: true, connectionIds: ['c1', 'c2'] }) })

    await wrapper.get('button.refresh').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/tokens/a/refresh')
    expect(wrapper.get('li.token').text()).toContain('Production, Recette')
    wrapper.unmount()
  })

  it('shows a refresh failure', async () => {
    const wrapper = await mountView([token('a', 'Tout', { allConnections: true })])
    mocked.mockRejectedValueOnce(new ApiError(401, 'locked'))

    await wrapper.get('button.refresh').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('reconnect')
    wrapper.unmount()
  })

  it('creates a token that never expires', async () => {
    const wrapper = await mountView()
    await wrapper.get('#token-name').setValue('Assistant')
    await wrapper.get('input[value="c1"]').setValue(true)
    await wrapper.get('#token-lifetime').setValue('never')
    mocked.mockResolvedValueOnce({ token: 'tdb_0123_secret', tokenInfo: token('n', 'Assistant', { expiresAt: null }) })

    await wrapper.get('form.create').trigger('submit')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/tokens', { name: 'Assistant', connectionIds: ['c1'], neverExpires: true })
    wrapper.unmount()
  })

  it('shows a token without expiry as such, still refreshable', async () => {
    const wrapper = await mountView([token('a', 'Pour toujours', { expiresAt: null, allConnections: true })])

    const row = wrapper.get('li.token')
    expect(row.text()).toContain('N’expire jamais')
    expect(row.text()).not.toContain('Expiré')
    expect(row.find('button.refresh').exists()).toBe(true)
    wrapper.unmount()
  })

  it('refuses to create a token without any connection chosen', async () => {
    const wrapper = await mountView()
    await wrapper.get('#token-name').setValue('Assistant')

    await wrapper.get('form.create').trigger('submit')
    await flushPromises()

    expect(wrapper.get('form.create [role="alert"]').text()).toContain('au moins une connexion')
    expect(mocked).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('shows the server refusal', async () => {
    const wrapper = await mountView()
    await wrapper.get('#token-name').setValue('Assistant')
    await wrapper.get('input[value="c1"]').setValue(true)
    mocked.mockRejectedValueOnce(new ApiError(401, 'locked'))

    await wrapper.get('form.create').trigger('submit')
    await flushPromises()

    expect(wrapper.get('form.create [role="alert"]').text()).toContain('reconnect')
    wrapper.unmount()
  })

  it('asks before revoking, then revokes', async () => {
    const wrapper = await mountView([token('a', 'Assistant')])

    await wrapper.get('button.revoke').trigger('click')
    expect(wrapper.get('[role="dialog"]').text()).toContain('Assistant')
    mocked.mockResolvedValueOnce(undefined)
    await wrapper.get('button.confirm').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('DELETE', '/web/tokens/a')
    expect(wrapper.findAll('li.token')).toHaveLength(0)
    wrapper.unmount()
  })

  it('keeps the token when the revocation is cancelled', async () => {
    const wrapper = await mountView([token('a', 'Assistant')])

    await wrapper.get('button.revoke').trigger('click')
    await wrapper.get('button.cancel').trigger('click')

    expect(wrapper.findAll('li.token')).toHaveLength(1)
    wrapper.unmount()
  })

  it('offers the single-step writing option, unticked, and sends it only when ticked', async () => {
    const wrapper = await mountView()
    expect((wrapper.get('#token-single-step').element as HTMLInputElement).checked).toBe(false)
    await wrapper.get('#token-name').setValue('Assistant')
    await wrapper.get('input[value="c1"]').setValue(true)
    await wrapper.get('#token-write-data').setValue(true)
    await wrapper.get('#token-single-step').setValue(true)
    mocked.mockResolvedValueOnce({ token: 'tdb_x', tokenInfo: token('n', 'Assistant', { writeData: true, singleStep: true }) })

    await wrapper.get('form.create').trigger('submit')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/tokens', { name: 'Assistant', connectionIds: ['c1'], lifetimeDays: 30, writeData: true, singleStep: true })
    wrapper.unmount()
  })

  it('tells a writing token that writes in a single step from one that needs a preparation', async () => {
    const wrapper = await mountView([token('a', 'Direct', { writeData: true, singleStep: true }), token('b', 'Prudent', { writeData: true })])

    const rows = wrapper.findAll('li.token')
    expect(rows[0]?.text()).toContain('Écrit en une seule étape')
    expect(rows[1]?.text()).not.toContain('une seule étape')
    wrapper.unmount()
  })
})
