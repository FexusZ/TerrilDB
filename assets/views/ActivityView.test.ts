import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import type { AuditEntry } from '../api/types'
import { useSessionStore } from '../stores/session'
import ActivityView from './ActivityView.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const entry = (id: string, action: string, extra: Partial<AuditEntry> = {}): AuditEntry => ({
  id, occurredAt: '2026-11-01T10:00:00+00:00', action, category: action.split('.')[0] ?? '', outcome: 'success', actor: 'web', ip: '203.0.113.7', subjectId: null, tokenId: null, details: {}, ...extra,
})

async function mountView(events: AuditEntry[] = [], next: string | null = null) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().user = { id: '1', username: 'alice', email: 'a@exemple.test', totpEnabled: false, recoveryCodesLeft: 0, role: 'admin', createdAt: '2026-11-01T10:00:00+00:00' }
  mocked.mockResolvedValueOnce({ events, next })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['connections', 'security', 'login', 'activity'].map((name) => ({ path: `/${name}`, name, component: { template: '<p />' } })),
  })
  await router.push('/activity')
  const wrapper = mount(ActivityView, { global: { plugins: [pinia, router] } })
  await flushPromises()

  return wrapper
}

describe('ActivityView', () => {
  beforeEach(() => mocked.mockReset())

  it('lists the events with a sentence, the door and the address', async () => {
    const wrapper = await mountView([entry('1', 'connection.created', { details: { name: 'Production' } }), entry('2', 'query.executed', { actor: 'api' })])

    const rows = wrapper.findAll('li.event')
    expect(rows).toHaveLength(2)
    expect(rows[0]?.text()).toContain('Connexion « Production » créée')
    expect(rows[0]?.text()).toContain('Interface')
    expect(rows[0]?.text()).toContain('203.0.113.7')
    expect(rows[1]?.text()).toContain('Requête exécutée')
    expect(rows[1]?.text()).toContain('API')
  })

  it('stresses what deserves attention', async () => {
    const wrapper = await mountView([entry('1', 'login.failure', { outcome: 'failed', details: { reason: 'invalid_credentials' } }), entry('2', 'login.success')])

    const rows = wrapper.findAll('li.event')
    expect(rows[0]?.classes()).toContain('failed')
    expect(rows[0]?.get('.outcome').text()).toBe('Échec')
    expect(rows[1]?.classes()).not.toContain('failed')
  })

  it('shows the statement of a query', async () => {
    const wrapper = await mountView([entry('1', 'query.refused', { outcome: 'refused', details: { sql: 'drop table orders', reason: 'not_read_only' } })])

    expect(wrapper.get('li.event pre').text()).toBe('drop table orders')
    expect(wrapper.get('li.event .outcome').text()).toBe('Refusé')
  })

  it('says when there is nothing to show', async () => {
    const wrapper = await mountView([])

    expect(wrapper.text()).toContain('Aucune activité')
  })

  it('filters by category', async () => {
    const wrapper = await mountView([entry('1', 'login.success')])
    mocked.mockResolvedValueOnce({ events: [entry('2', 'query.executed')], next: null })

    await wrapper.get('button[data-filter="query"]').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/activity?limit=50&category=query')
    expect(wrapper.get('button[data-filter="query"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.findAll('li.event')).toHaveLength(1)
  })

  it('loads more events on demand', async () => {
    const wrapper = await mountView([entry('1', 'login.success')], '1')
    mocked.mockResolvedValueOnce({ events: [entry('0', 'login.success')], next: null })

    await wrapper.get('button.more').trigger('click')
    await flushPromises()

    expect(wrapper.findAll('li.event')).toHaveLength(2)
    expect(wrapper.find('button.more').exists()).toBe(false)
  })

  it('reports a loading failure', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    mocked.mockRejectedValueOnce(new ApiError(0, 'network'))
    const router = createRouter({ history: createMemoryHistory(), routes: ['connections', 'security', 'login'].map((name) => ({ path: `/${name}`, name, component: { template: '<p />' } })) })
    const wrapper = mount(ActivityView, { global: { plugins: [pinia, router] } })
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('serveur')
  })
})
