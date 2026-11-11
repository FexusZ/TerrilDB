import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import type { DiagnosticStatus, LockReport, SessionReport } from '../api/types'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'
import ServerActivityPane from './ServerActivityPane.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const ok: DiagnosticStatus = { availability: 'available', reason: null, privilege: null }

const sessions: SessionReport = {
  status: ok,
  truncated: false,
  sessions: [
    { id: '10', user: 'app', client: '10.0.0.5', database: 'shop', state: 'Sleep', seconds: 300, query: null, current: false },
    { id: '11', user: 'admin', client: '10.0.0.6', database: 'shop', state: 'Query', seconds: 2, query: 'SELECT 1', current: true },
  ],
}

const locks: LockReport = {
  status: ok,
  truncated: false,
  transactions: [
    { session: '10', user: 'app', state: 'RUNNING', seconds: 300, rowsLocked: 3, rowsModified: 1, sleeping: true, query: null },
    { session: '12', user: 'app', state: 'LOCK WAIT', seconds: 5, rowsLocked: 1, rowsModified: 0, sleeping: false, query: 'UPDATE orders SET status = 1' },
  ],
  blocking: [
    {
      id: '10', user: 'app', query: null, seconds: null, kind: null, mode: null, object: null,
      blocked: [{ id: '12', user: 'app', query: 'UPDATE orders SET status = 1', seconds: 5, kind: 'row', mode: 'X', object: 'shop.orders', blocked: [] }],
    },
  ],
}

function answer(overrides: { sessions?: SessionReport | ApiError; locks?: LockReport | ApiError } = {}): void {
  mocked.mockImplementation((_method, path) => {
    const value = path.includes('/diagnostics/sessions') ? (overrides.sessions ?? sessions) : path.includes('/diagnostics/locks') ? (overrides.locks ?? locks) : { tables: [] }
    return value instanceof ApiError ? Promise.reject(value) : Promise.resolve(value)
  })
}

async function mountPane(scope: { database: string; schema: string } | null = null) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useConnectionsStore().items = [{ id: 'abc', name: 'Production', engine: 'mysql', createdAt: '', updatedAt: '' }]
  await useWorkspaceStore().open('abc')
  if (scope !== null) {
    useWorkspaceStore().database = scope.database
    useWorkspaceStore().schema = scope.schema
  }
  mocked.mockClear()
  const wrapper = mount(ServerActivityPane, { props: { id: 'abc' }, global: { plugins: [pinia] } })
  await flushPromises()

  return wrapper
}

describe('ServerActivityPane', () => {
  beforeEach(() => {
    mocked.mockReset()
    answer()
  })

  it('reads the sessions and the locks of the explored database', async () => {
    await mountPane({ database: 'shop', schema: 'public' })

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/sessions?database=shop&schema=public')
    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/locks?database=shop&schema=public')
  })

  it('lists the sessions and marks the one of TerrilDB', async () => {
    const wrapper = await mountPane()

    const rows = wrapper.findAll('[data-testid="sessions"] tbody tr')
    expect(rows).toHaveLength(2)
    expect(rows[0]?.text()).toContain('app')
    expect(rows[0]?.text()).toContain('5 min')
    expect(rows[1]?.text()).toContain('SELECT 1')
    expect(rows[1]?.text()).toContain('TerrilDB')
  })

  it('shows who blocks whom, with the lock, the object and how long', async () => {
    const wrapper = await mountPane()

    const lines = wrapper.findAll('[data-testid="blocking"] li')
    expect(lines).toHaveLength(2)
    expect(lines[0]?.text()).toContain('Session 10')
    expect(lines[0]?.text()).toContain('bloque')
    expect(lines[1]?.text()).toContain('Session 12')
    expect(lines[1]?.text()).toContain('attend depuis 5 s')
    expect(lines[1]?.text()).toContain('shop.orders')
    expect(lines[1]?.text()).toContain('X')
    expect(lines[1]?.text()).toContain('UPDATE orders SET status = 1')
  })

  it('never offers to stop a session', async () => {
    const wrapper = await mountPane()

    expect(wrapper.text()).not.toMatch(/KILL|terminate/i)
    expect(wrapper.findAll('button').map((button) => button.text())).toEqual(['Actualiser'])
  })

  it('says there is no wait when the source is readable and empty', async () => {
    answer({ locks: { ...locks, transactions: [], blocking: [] } })

    const wrapper = await mountPane()

    expect(wrapper.find('[data-testid="blocking"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Aucune attente de verrou.')
    expect(wrapper.text()).toContain('Aucune transaction ouverte.')
  })

  it('does not call an unreadable source empty', async () => {
    answer({ locks: { status: { availability: 'disabled', reason: 'performance_schema_off', privilege: null }, truncated: false, transactions: [], blocking: [] } })

    const wrapper = await mountPane()

    expect(wrapper.text()).toContain('performance_schema est éteint')
    expect(wrapper.text()).not.toContain('Aucune attente de verrou.')
    expect(wrapper.text()).not.toContain('Aucune transaction ouverte.')
  })

  it('names the missing privilege for the sessions', async () => {
    answer({ sessions: { status: { availability: 'permission_denied', reason: 'insufficient_privilege', privilege: 'PROCESS' }, truncated: false, sessions: [] } })

    const wrapper = await mountPane()

    expect(wrapper.text()).toContain('il lui manque PROCESS')
    expect(wrapper.find('[data-testid="sessions"]').exists()).toBe(false)
  })

  it('highlights the transactions older than the threshold, above all the sleeping ones', async () => {
    const wrapper = await mountPane()

    const rows = wrapper.findAll('[data-testid="transactions"] tbody tr')
    expect(rows[0]?.classes()).toContain('long')
    expect(rows[0]?.text()).toContain('en sommeil')
    expect(rows[1]?.classes()).not.toContain('long')

    await wrapper.find('input[name="threshold"]').setValue('3')
    expect(wrapper.findAll('[data-testid="transactions"] tbody tr')[1]?.classes()).toContain('long')
  })

  it('reloads everything on demand', async () => {
    const wrapper = await mountPane()
    mocked.mockClear()

    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenCalledTimes(2)
  })

  it('keeps the other section when one read fails', async () => {
    answer({ locks: new ApiError(501, 'unsupported_operation', {}) })

    const wrapper = await mountPane()

    expect(wrapper.find('[data-testid="sessions"]').exists()).toBe(true)
    expect(wrapper.find('[role="alert"]').text()).toContain('Cette opération n’existe pas pour ce moteur.')
  })

  it('says the list is cut when the server has more', async () => {
    answer({ sessions: { ...sessions, truncated: true } })

    const wrapper = await mountPane()

    expect(wrapper.text()).toContain('200 premières')
  })
})
