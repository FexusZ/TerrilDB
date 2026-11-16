import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import type { AdminJournalEvent, AdminTokenInfo } from '../api/types'
import { useSessionStore } from '../stores/session'
import AdministrationView from './AdministrationView.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const admin = { id: 'a1', username: 'alice', email: 'a@exemple.test', role: 'admin' as const, recoveryPhrase: false, totpEnabled: true, recoveryCodesLeft: 0, createdAt: '2026-10-01T10:00:00+00:00' }
const perms = { writeData: false, writeSchema: false, createConnections: false, diagnostic: false, singleStep: false }
const lecture: AdminTokenInfo = { id: 't1', name: 'Lecture <b>', owner: { id: 'b2', username: 'bob', disabled: false }, permissions: { ...perms, diagnostic: true }, allConnections: false, connectionCount: 2, status: 'active', createdAt: '2026-11-01T08:00:00+00:00', expiresAt: '2027-11-01T08:00:00+00:00', lastUsedAt: null }
const ancien: AdminTokenInfo = { id: 't2', name: 'Ancien', owner: { id: 'c3', username: 'carol', disabled: true }, permissions: { ...perms, writeData: true, readSchema: true }, allConnections: true, connectionCount: 5, status: 'expired', createdAt: '2026-01-01T08:00:00+00:00', expiresAt: null, lastUsedAt: '2026-02-01T08:00:00+00:00' }
const event = (id: string, action: AdminJournalEvent['action']): AdminJournalEvent => ({ id, occurredAt: '2026-11-14T09:00:00+00:00', action, actor: { id: 'a1', username: 'alice' }, subjectId: 'b2', owner: action === 'admin.token_revoked' ? 'b2' : null })

async function mountView(tokens: AdminTokenInfo[] = [lecture, ancien], journal: { events: AdminJournalEvent[]; next: string | null } = { events: [event('e1', 'admin.token_revoked'), event('e2', 'account.disabled')], next: null }) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().user = admin
  mocked.mockResolvedValueOnce({ tokens }).mockResolvedValueOnce(journal)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['connections', 'security', 'login', 'register', 'invitations', 'accounts', 'administration', 'workspace-home'].map((name) => ({ path: `/${name}`, name, component: { template: '<p />' } })),
  })
  await router.push('/administration')
  const wrapper = mount(AdministrationView, { global: { plugins: [pinia, router] } })
  await flushPromises()

  return wrapper
}

describe('AdministrationView', () => {
  beforeEach(() => mocked.mockReset())

  it('lists the tokens of every account with owner, rights and state', async () => {
    const wrapper = await mountView()

    expect(mocked).toHaveBeenCalledWith('GET', '/web/admin/tokens')
    const rows = wrapper.findAll('section.tokens tbody tr')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('bob')
    expect(rows[0]!.text()).toContain('2 connexions')
    expect(rows[0]!.text()).toContain('Supervision')
    expect(rows[0]!.text()).toContain('Jamais utilisé')
    expect(rows[0]!.text()).toContain('Actif')
    expect(rows[1]!.text()).toContain('Expiré')
    expect(rows[1]!.text()).toContain('Toutes les connexions')
    expect(rows[1]!.text()).toContain('compte désactivé')
    expect(rows[1]!.text()).toContain('Jamais')
  })

  it('shows a right it does not know by its name', async () => {
    const wrapper = await mountView()

    expect(wrapper.findAll('section.tokens tbody tr')[1]!.text()).toContain('readSchema')
  })

  it('renders a token name as text, never as markup', async () => {
    const wrapper = await mountView()

    expect(wrapper.find('section.tokens b').exists()).toBe(false)
    expect(wrapper.get('section.tokens').text()).toContain('Lecture <b>')
  })

  it('revokes only after a confirmation, and removes the row', async () => {
    const wrapper = await mountView()
    mocked.mockResolvedValueOnce(undefined).mockResolvedValueOnce({ events: [], next: null })

    await wrapper.findAll('section.tokens tbody tr')[0]!.get('button').trigger('click')
    expect(mocked).not.toHaveBeenCalledWith('DELETE', '/web/admin/tokens/t1')
    expect(wrapper.text()).toContain('Révoquer ce jeton ?')
    expect(wrapper.text()).toContain('irréversible')
    await wrapper.get('[role="alertdialog"] button.danger, [role="dialog"] button.danger').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenCalledWith('DELETE', '/web/admin/tokens/t1')
    expect(wrapper.findAll('section.tokens tbody tr')).toHaveLength(1)
  })

  it('does nothing when the confirmation is cancelled', async () => {
    const wrapper = await mountView()

    await wrapper.findAll('section.tokens tbody tr')[0]!.get('button').trigger('click')
    const cancel = wrapper.findAll('button').find((button) => button.text() === 'Annuler')!
    await cancel.trigger('click')

    expect(mocked).toHaveBeenCalledTimes(2)
    expect(wrapper.findAll('section.tokens tbody tr')).toHaveLength(2)
  })

  it('shows the server refusal in French', async () => {
    const wrapper = await mountView()
    mocked.mockRejectedValueOnce(new ApiError(404, 'not_found'))

    await wrapper.findAll('section.tokens tbody tr')[0]!.get('button').trigger('click')
    await wrapper.get('[role="alertdialog"] button.danger, [role="dialog"] button.danger').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text().length).toBeGreaterThan(0)
  })

  it('lists the administration journal and loads the next page by cursor', async () => {
    const wrapper = await mountView(undefined, { events: [event('e1', 'admin.token_revoked'), event('e2', 'account.disabled')], next: 'e2' })

    const items = wrapper.findAll('section.journal li')
    expect(items).toHaveLength(2)
    expect(items[0]!.text()).toContain('alice')
    expect(items[0]!.text()).toContain('Jeton d’un autre compte révoqué')
    expect(items[1]!.text()).toContain('Compte désactivé')

    mocked.mockResolvedValueOnce({ events: [event('e3', 'account.enabled')], next: null })
    await wrapper.get('section.journal button.more').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/admin/journal?before=e2')
    expect(wrapper.findAll('section.journal li')).toHaveLength(3)
    expect(wrapper.find('section.journal button.more').exists()).toBe(false)
  })

  it('shows an empty journal and tokens calmly', async () => {
    const wrapper = await mountView([], { events: [], next: null })

    expect(wrapper.get('section.tokens').text()).toContain('Aucun jeton')
    expect(wrapper.get('section.journal').text()).toContain('Aucune action')
  })
})
