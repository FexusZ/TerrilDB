import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import type { AccountInfo } from '../api/types'
import { useSessionStore } from '../stores/session'
import AccountsView from './AccountsView.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const alice: AccountInfo = { id: 'a1', username: 'alice', role: 'admin', totpEnabled: true, lastLoginAt: '2026-11-12T08:00:00+00:00', disabled: false, current: true }
const bob: AccountInfo = { id: 'b2', username: 'bob', role: 'member', totpEnabled: false, lastLoginAt: null, disabled: false, current: false }
const carol: AccountInfo = { id: 'c3', username: 'carol', role: 'member', totpEnabled: false, lastLoginAt: '2026-10-01T08:00:00+00:00', disabled: true, current: false }
const admin = { id: 'a1', username: 'alice', email: 'a@exemple.test', role: 'admin' as const, recoveryPhrase: false, totpEnabled: true, recoveryCodesLeft: 0, createdAt: '2026-10-01T10:00:00+00:00' }

async function mountView(list: AccountInfo[] | ApiError = [alice, bob, carol]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().user = admin
  if (list instanceof ApiError) {
    mocked.mockRejectedValueOnce(list)
  } else {
    mocked.mockResolvedValueOnce({ accounts: list })
  }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['connections', 'security', 'login', 'register', 'invitations', 'accounts', 'workspace-home'].map((name) => ({ path: `/${name}`, name, component: { template: '<p />' } })),
  })
  await router.push('/accounts')
  const wrapper = mount(AccountsView, { global: { plugins: [pinia, router] } })
  await flushPromises()

  return wrapper
}

describe('AccountsView', () => {
  beforeEach(() => mocked.mockReset())

  it('lists the accounts with role, second factor, last login and state', async () => {
    const wrapper = await mountView()

    expect(mocked).toHaveBeenCalledWith('GET', '/web/accounts')
    const rows = wrapper.findAll('tbody tr')
    expect(rows).toHaveLength(3)
    expect(rows[0]!.text()).toContain('alice')
    expect(rows[0]!.text()).toContain('Administrateur')
    expect(rows[0]!.text()).toContain('Activé')
    expect(rows[1]!.text()).toContain('Membre')
    expect(rows[1]!.text()).toContain('Jamais')
    expect(rows[2]!.text()).toContain('Désactivé')
  })

  it('offers no action on the own account', async () => {
    const wrapper = await mountView()
    const rows = wrapper.findAll('tbody tr')

    expect(rows[0]!.find('button').exists()).toBe(false)
    expect(rows[1]!.get('button').text()).toBe('Désactiver')
    expect(rows[2]!.get('button').text()).toBe('Réactiver')
  })

  it('disables an account only after a confirmation', async () => {
    const wrapper = await mountView()
    mocked.mockResolvedValueOnce({ account: { ...bob, disabled: true } })

    await wrapper.findAll('tbody tr')[1]!.get('button').trigger('click')
    expect(mocked).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[role="dialog"]').text()).toContain('bob')
    await wrapper.get('.dialog .confirm').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/accounts/b2/disable')
    expect(wrapper.findAll('tbody tr')[1]!.text()).toContain('Désactivé')
  })

  it('enables an account again', async () => {
    const wrapper = await mountView()
    mocked.mockResolvedValueOnce({ account: { ...carol, disabled: false } })

    await wrapper.findAll('tbody tr')[2]!.get('button').trigger('click')
    await wrapper.get('.dialog .confirm').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/accounts/c3/enable')
    expect(wrapper.findAll('tbody tr')[2]!.text()).not.toContain('Désactivé')
  })

  it('shows the server refusal in French', async () => {
    const wrapper = await mountView()
    mocked.mockRejectedValueOnce(new ApiError(409, 'last_administrator'))

    await wrapper.findAll('tbody tr')[1]!.get('button').trigger('click')
    await wrapper.get('.dialog .confirm').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('dernier administrateur')
  })

  it('tells a member the page is reserved', async () => {
    const wrapper = await mountView(new ApiError(403, 'forbidden'))

    expect(wrapper.get('[role="alert"]').text()).toContain('administrateurs')
    expect(wrapper.find('table').exists()).toBe(false)
  })
})
