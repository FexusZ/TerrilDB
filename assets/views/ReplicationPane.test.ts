import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import type { DiagnosticStatus, ReplicationReport } from '../api/types'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'
import ReplicationPane from './ReplicationPane.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const ok: DiagnosticStatus = { availability: 'available', reason: null, privilege: null }
const settings = { readOnly: true, superReadOnly: true, binlogFormat: 'ROW', gtid: true }
const thresholds = { warning: 30, critical: 300 }

const none: ReplicationReport = { status: ok, role: 'none', replicaCount: 0, settings, channels: [], alerts: [], thresholds }

const broken: ReplicationReport = {
  status: ok,
  role: 'replica',
  replicaCount: 0,
  settings,
  channels: [{
    name: '', receiver: 'running', applier: 'stopped', lagSeconds: null, received: 'u:1-11', applied: 'u:1-10', pendingApply: true,
    receiverError: null, applierError: { code: 1062, message: 'Duplicate entry 42' },
  }],
  alerts: [{ code: 'applier_stopped', severity: 'critical', channel: '' }, { code: 'applier_error', severity: 'critical', channel: '' }],
  thresholds,
}

async function mountPane(report: ReplicationReport | ApiError) {
  mocked.mockResolvedValue({ tables: [] })
  const pinia = createPinia()
  setActivePinia(pinia)
  useConnectionsStore().items = [{ id: 'abc', name: 'Production', engine: 'mysql', createdAt: '', updatedAt: '' }]
  await useWorkspaceStore().open('abc')
  mocked.mockReset()
  mocked.mockResolvedValue({ tables: [] })
  if (report instanceof ApiError) mocked.mockRejectedValueOnce(report)
  else mocked.mockResolvedValueOnce(report)
  const wrapper = mount(ReplicationPane, { props: { id: 'abc' }, global: { plugins: [pinia] } })
  await flushPromises()

  return wrapper
}

describe('ReplicationPane', () => {
  beforeEach(() => mocked.mockReset())

  it('reads the replication of the connection', async () => {
    await mountPane(none)

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/replication')
  })

  it('says no replication is configured, without error or alert', async () => {
    const wrapper = await mountPane(none)

    expect(wrapper.text()).toContain('Pas de réplication configurée')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="alerts"]').exists()).toBe(false)
  })

  it('shows the threads, the received and applied sets and the last error of a broken replica', async () => {
    const wrapper = await mountPane(broken)

    const text = wrapper.text()
    expect(text).toContain('Réplica')
    expect(text).toContain('arrêté')
    expect(text).toContain('u:1-11')
    expect(text).toContain('u:1-10')
    expect(text).toContain('Duplicate entry 42')
    expect(text).toContain('1062')
    expect(wrapper.findAll('[data-testid="alerts"] li')).toHaveLength(2)
  })

  it('warns that nothing is executed from this page', async () => {
    const wrapper = await mountPane(broken)

    expect(wrapper.text()).toContain('Rien n’est exécuté')
  })

  it('says why the source cannot be read instead of showing an empty page', async () => {
    const wrapper = await mountPane({ ...none, status: { availability: 'permission_denied', reason: 'insufficient_privilege', privilege: 'REPLICATION CLIENT' } })

    expect(wrapper.text()).toContain('REPLICATION CLIENT')
    expect(wrapper.text()).not.toContain('Pas de réplication configurée')
  })

  it('shows an engine that has no replication reading as unsupported', async () => {
    const wrapper = await mountPane(new ApiError(501, 'unsupported_operation'))

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('warns that a lag of zero can be misleading', async () => {
    const wrapper = await mountPane({ ...broken, channels: [{ ...broken.channels[0]!, applier: 'running', lagSeconds: 0 }] })

    expect(wrapper.text()).toContain('trompeur')
  })
})
