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
  id, name, connectionIds: ['c1'], createdAt: '2026-11-01T10:00:00+00:00', expiresAt: '2099-12-01T10:00:00+00:00', lastUsedAt: null, ...extra,
})

async function mountView(tokens: ApiTokenInfo[] = [], connections: ConnectionSummary[] = [connection('c1', 'Production'), connection('c2', 'Recette')]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().user = { id: '1', username: 'alice', email: 'a@exemple.test', totpEnabled: false, recoveryCodesLeft: 0, createdAt: '2026-11-01T10:00:00+00:00' }
  mocked.mockResolvedValueOnce({ connections })
  mocked.mockResolvedValueOnce({ tokens })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['connections', 'connection-new', 'security', 'login', 'tokens'].map((name) => ({ path: `/${name}`, name, component: { template: '<p />' } })),
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

  it('invites to create a connection first when there is none', async () => {
    const wrapper = await mountView([], [])

    expect(wrapper.find('form.create').exists()).toBe(false)
    expect(wrapper.text()).toContain('Créez d’abord une connexion')
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
})
