import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import type { DiagnosticStatus, ReplicationReport, TabletComparison } from '../api/types'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'
import TabletComparePane from './TabletComparePane.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const ok: DiagnosticStatus = { availability: 'available', reason: null, privilege: null }
const settings = { readOnly: true, superReadOnly: true, binlogFormat: 'ROW', gtid: true }
const thresholds = { warning: 30, critical: 300 }

const primaryReplication: ReplicationReport = { status: ok, role: 'source', replicaCount: 1, settings, channels: [], alerts: [], thresholds }
const replicaReplication: ReplicationReport = {
  status: ok, role: 'replica', replicaCount: 0, settings, thresholds,
  channels: [{ name: '', receiver: 'running', applier: 'running', lagSeconds: 12, received: null, applied: null, pendingApply: false, receiverError: null, applierError: null }],
  alerts: [{ code: 'lag_high', severity: 'warning', channel: '' }],
}

const comparison: TabletComparison = {
  tablets: [
    { alias: 'zone1-100', cell: 'zone1', keyspace: 'shop', shard: '0', type: 'PRIMARY', serving: true, failure: null, replication: primaryReplication, variablesStatus: ok, changedVariables: 4 },
    { alias: 'zone1-101', cell: 'zone1', keyspace: 'shop', shard: '0', type: 'REPLICA', serving: true, failure: null, replication: replicaReplication, variablesStatus: ok, changedVariables: 5 },
    { alias: 'zone1-102', cell: 'zone1', keyspace: 'shop', shard: '0', type: 'RDONLY', serving: false, failure: 'unreachable', replication: null, variablesStatus: null, changedVariables: null },
  ],
  differences: [
    { name: 'max_connections', unit: null, values: { 'zone1-100': '500', 'zone1-101': '300' } },
    { name: 'sort_buffer_size', unit: null, values: { 'zone1-100': '4194304', 'zone1-101': null } },
  ],
  masked: 2,
  truncated: false,
}

async function mountPane(answer: TabletComparison | ApiError) {
  mocked.mockResolvedValue({ tables: [] })
  const pinia = createPinia()
  setActivePinia(pinia)
  useConnectionsStore().items = [{ id: 'abc', name: 'Production', engine: 'mysql', createdAt: '', updatedAt: '' }]
  await useWorkspaceStore().open('abc')
  mocked.mockReset()
  if (answer instanceof ApiError) mocked.mockRejectedValueOnce(answer)
  else mocked.mockResolvedValueOnce(answer)
  const wrapper = mount(TabletComparePane, { props: { id: 'abc' }, global: { plugins: [pinia] } })
  await flushPromises()

  return wrapper
}

describe('TabletComparePane', () => {
  beforeEach(() => mocked.mockReset())

  it('reads the comparison of the connection', async () => {
    await mountPane(comparison)

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/tablets/compare')
  })

  it('shows every tablet with its role, service state and replication side by side', async () => {
    const wrapper = await mountPane(comparison)
    const rows = wrapper.findAll('[data-test="tablet-row"]')

    expect(rows).toHaveLength(3)
    expect(rows[0]?.text()).toContain('zone1-100')
    expect(rows[0]?.text()).toContain('primaire')
    expect(rows[1]?.text()).toContain('réplica')
    expect(rows[1]?.text()).toContain('12 s')
    expect(rows[1]?.text()).toContain('1 alerte')
    expect(rows[2]?.text()).toContain('hors service')
  })

  it('says a tablet could not be read instead of showing it as healthy', async () => {
    const wrapper = await mountPane(comparison)

    expect(wrapper.findAll('[data-test="tablet-row"]')[2]?.text()).toContain('illisible')
  })

  it('lists the variables that differ with each tablet value, and the default where unchanged', async () => {
    const wrapper = await mountPane(comparison)
    const rows = wrapper.findAll('[data-test="difference-row"]')

    expect(rows[0]?.text()).toContain('max_connections')
    expect(rows[0]?.text()).toContain('500')
    expect(rows[0]?.text()).toContain('300')
    expect(rows[1]?.text()).toContain('4194304')
    expect(rows[1]?.text()).toContain('défaut')
  })

  it('counts the masked variables without showing them', async () => {
    const wrapper = await mountPane(comparison)

    expect(wrapper.text()).toContain('2 variables masquées')
  })

  it('says when the tablets agree', async () => {
    const wrapper = await mountPane({ ...comparison, differences: [], masked: 0 })

    expect(wrapper.text()).toContain('Aucun écart')
  })

  it('says when not every tablet was read', async () => {
    const wrapper = await mountPane({ ...comparison, truncated: true })

    expect(wrapper.text()).toContain('pas tous lus')
  })

  it('shows the error when the connection is not a Vitess gateway', async () => {
    const wrapper = await mountPane(new ApiError(501, 'unsupported_operation', {}))

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })
})
