import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import type { InvitationInfo } from '../api/types'
import { useSessionStore } from '../stores/session'
import InvitationsView from './InvitationsView.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const pending: InvitationInfo = { id: 'i1', note: 'Pour Marie', status: 'pending', createdAt: '2026-11-01T10:00:00+00:00', expiresAt: '2026-11-08T10:00:00+00:00', usedAt: null, usedBy: null }
const used: InvitationInfo = { id: 'i2', note: 'Pour Paul', status: 'used', createdAt: '2026-10-25T10:00:00+00:00', expiresAt: '2026-11-01T10:00:00+00:00', usedAt: '2026-10-26T10:00:00+00:00', usedBy: 'paul' }
const admin = { id: '1', username: 'alice', email: 'a@exemple.test', role: 'admin' as const, totpEnabled: false, recoveryCodesLeft: 0, createdAt: '2026-10-01T10:00:00+00:00' }

async function mountView(list: InvitationInfo[] | ApiError = [pending, used], emailEnabled = false) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().user = admin
  if (list instanceof ApiError) {
    mocked.mockRejectedValueOnce(list)
  } else {
    mocked.mockResolvedValueOnce({ invitations: list, emailEnabled })
  }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['connections', 'security', 'login', 'register', 'invitations'].map((name) => ({ path: `/${name}`, name, component: { template: '<p />' } })),
  })
  await router.push('/invitations')
  const wrapper = mount(InvitationsView, { global: { plugins: [pinia, router] } })
  await flushPromises()

  return wrapper
}

describe('InvitationsView', () => {
  beforeEach(() => mocked.mockReset())

  it('lists the invitations with their state', async () => {
    const wrapper = await mountView()

    expect(mocked).toHaveBeenCalledWith('GET', '/web/invitations')
    const rows = wrapper.findAll('li.invitation')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('Pour Marie')
    expect(rows[0]!.text()).toContain('En attente')
    expect(rows[1]!.text()).toContain('Utilisée par paul')
  })

  it('creates an invitation and shows its link once', async () => {
    const wrapper = await mountView([])
    mocked.mockResolvedValueOnce({ token: 'inv_secret', invitation: pending })

    await wrapper.get('#invitation-note').setValue('Pour Marie')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/invitations', { note: 'Pour Marie', lifetimeDays: 7 })
    expect(wrapper.get('.fresh code').text()).toContain('/register?invitation=inv_secret')
    expect(wrapper.findAll('li.invitation')).toHaveLength(1)

    await wrapper.get('button.copied').trigger('click')
    expect(wrapper.find('.fresh').exists()).toBe(false)
  })

  it('only offers the e-mail field when the server can send mail', async () => {
    const without = await mountView([], false)
    const withMail = await mountView([], true)

    expect(without.find('#invitation-email').exists()).toBe(false)
    expect(withMail.find('#invitation-email').exists()).toBe(true)
  })

  it('sends the address along and says the mail went out, keeping the link', async () => {
    const wrapper = await mountView([], true)
    mocked.mockResolvedValueOnce({ token: 'inv_secret', invitation: pending, emailSent: true })

    await wrapper.get('#invitation-email').setValue('marie@exemple.test')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/invitations', { note: '', lifetimeDays: 7, email: 'marie@exemple.test' })
    expect(wrapper.get('.fresh').text()).toContain('e-mail a été envoyé')
    expect(wrapper.get('.fresh code').text()).toContain('inv_secret')
    expect((wrapper.get('#invitation-email').element as HTMLInputElement).value).toBe('')
  })

  it('tells the administrator to pass the link on when the mail failed', async () => {
    const wrapper = await mountView([], true)
    mocked.mockResolvedValueOnce({ token: 'inv_secret', invitation: pending, emailSent: false })

    await wrapper.get('#invitation-email').setValue('marie@exemple.test')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('.fresh').text()).toContain('n’a pas pu être envoyé')
  })

  it('does not mention any mail when no address was given', async () => {
    const wrapper = await mountView([], true)
    mocked.mockResolvedValueOnce({ token: 'inv_secret', invitation: pending, emailSent: false })

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/invitations', { note: '', lifetimeDays: 7 })
    expect(wrapper.get('.fresh').text()).not.toContain('e-mail')
  })

  it('revokes an invitation after confirmation', async () => {
    const wrapper = await mountView()
    mocked.mockResolvedValueOnce(undefined)

    await wrapper.get('li.invitation button.revoke').trigger('click')
    await wrapper.get('[role="dialog"] button.confirm').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('DELETE', '/web/invitations/i1')
    expect(wrapper.findAll('li.invitation')).toHaveLength(1)
  })

  it('shows the error when the list cannot be read', async () => {
    const wrapper = await mountView(new ApiError(403, 'forbidden'))

    expect(wrapper.get('[role="alert"]').text()).toContain('administrateur')
  })
})
