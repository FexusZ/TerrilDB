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

  it('lists the slots of a PostgreSQL primary and flags the one that holds WAL', async () => {
    const wrapper = await mountPane({
      ...none,
      alerts: [{ code: 'slot_inactive', severity: 'warning', channel: 'orphan' }],
      slots: {
        status: ok,
        slots: [
          { name: 'orphan', type: 'physical', active: false, temporary: false, retainedBytes: 50331704, walStatus: 'reserved', safeWalSize: null },
          { name: 'replica_slot', type: 'physical', active: true, temporary: false, retainedBytes: 0, walStatus: 'reserved', safeWalSize: 1048576 },
        ],
      },
      limits: { slotRetainedWarningBytes: 1073741824, slotRetainedCriticalBytes: 10737418240, tableQueueWarning: 100 },
    })

    const text = wrapper.text()
    expect(text).toContain('Slots de réplication')
    expect(text).toContain('orphan')
    expect(text).toContain('48 Mio')
    expect(text).toContain('inactif')
    expect(text).toContain('1 Gio')
    expect(wrapper.findAll('[data-testid="alerts"] li')).toHaveLength(1)
  })

  it('says there is no slot, then reads the replicas of the source', async () => {
    const wrapper = await mountPane({
      ...none,
      role: 'source',
      replicaCount: 1,
      slots: { status: ok, slots: [] },
      replicas: { status: ok, replicas: [{ rank: 1, state: 'streaming', connected: true, syncState: 'async', writeLagSeconds: 0.001, flushLagSeconds: 0.002, replayLagSeconds: 2, behindBytes: 0 }] },
    })

    const text = wrapper.text()
    expect(text).toContain('Aucun slot')
    expect(text).toContain('streaming')
    expect(text).toContain('async')
    expect(text).toContain('2 s')
  })

  it('shows why a section cannot be read without hiding the others', async () => {
    const wrapper = await mountPane({
      ...none,
      binlog: { status: { availability: 'disabled', reason: 'binary_log_off', privilege: null }, enabled: false, fileCount: null, totalBytes: null, expireSeconds: null, gtidExecuted: null, gtidPurged: null },
      semiSync: { status: { availability: 'disabled', reason: 'semi_sync_not_loaded', privilege: null }, source: 'not_loaded', replica: 'not_loaded', clients: null },
    })

    const text = wrapper.text()
    expect(text).toContain('Journaux binaires')
    expect(text).toContain('journal binaire est éteint')
    expect(text).toContain('Semi-synchrone')
    expect(text).toContain('greffon non chargé')
  })

  it('shows binary logs with their size, expiry and GTID sets', async () => {
    const wrapper = await mountPane({
      ...none,
      binlog: { status: ok, enabled: true, fileCount: 2, totalBytes: 600000000, expireSeconds: 2592000, gtidExecuted: 'u:1-166347', gtidPurged: null },
      semiSync: { status: ok, source: 'fallen_back', replica: 'off', clients: 0 },
    })

    const text = wrapper.text()
    expect(text).toContain('572 Mio')
    expect(text).toContain('30 j')
    expect(text).toContain('u:1-166347')
    expect(text).toContain('repassé en asynchrone')
  })

  it('lists the replicated tables of ClickHouse and says so when there are none', async () => {
    const tables = await mountPane({
      ...none,
      role: 'peer',
      replicatedTables: { status: ok, truncated: false, tables: [{ database: 'd', table: 'rt', readonly: false, sessionExpired: false, absoluteDelaySeconds: 3, queueSize: 4, insertsInQueue: 5, lostParts: 0 }] },
    })
    expect(tables.text()).toContain('Tables répliquées entre pairs')
    expect(tables.text()).toContain('d.rt')

    const empty = await mountPane({ ...none, replicatedTables: { status: ok, truncated: false, tables: [] } })
    expect(empty.text()).toContain('Aucune table répliquée')
  })

  it('shows the link of a Redis replica without any address', async () => {
    const wrapper = await mountPane({
      ...none,
      role: 'replica',
      keyValue: { linkStatus: 'down', lastIoSecondsAgo: null, syncInProgress: false, backlogSize: 1048576, masterReplOffset: 840 },
    })

    const text = wrapper.text()
    expect(text).toContain('Lien avec la source')
    expect(text).toContain('coupé')
    expect(text).toContain('1 Mio')
    expect(text).toContain('840')
  })
})
