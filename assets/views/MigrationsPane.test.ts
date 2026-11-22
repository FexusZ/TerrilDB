import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import type { DiagnosticStatus, MigrationReport, VitessMigration } from '../api/types'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'
import MigrationsPane from './MigrationsPane.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const ok: DiagnosticStatus = { availability: 'available', reason: null, privilege: null }
const UUID = 'ffc701c7_e6a9_11f1_baef_9a5268ba1852'

function migration(overrides: Partial<VitessMigration> = {}): VitessMigration {
  return {
    uuid: UUID, keyspace: 'shop', shard: '0', table: 'orders', status: 'running', progress: 40, statement: 'alter table orders add column b int', strategy: 'vitess',
    addedAt: '2026-11-22 19:22:04', startedAt: null, completedAt: null, message: null, retries: 0, etaSeconds: null, postponeCompletion: true, postponeLaunch: false, tablet: 'test-0000000001',
    actions: [{ action: 'complete', statement: `ALTER VITESS_MIGRATION '${UUID}' COMPLETE` }, { action: 'cancel', statement: `ALTER VITESS_MIGRATION '${UUID}' CANCEL` }],
    ...overrides,
  }
}

async function mountPane(answer: MigrationReport | ApiError) {
  mocked.mockResolvedValue({ tables: [] })
  const pinia = createPinia()
  setActivePinia(pinia)
  useConnectionsStore().items = [{ id: 'abc', name: 'Production', engine: 'mysql', createdAt: '', updatedAt: '' }]
  await useWorkspaceStore().open('abc')
  mocked.mockReset()
  if (answer instanceof ApiError) mocked.mockRejectedValueOnce(answer)
  else mocked.mockResolvedValueOnce(answer)
  const wrapper = mount(MigrationsPane, { props: { id: 'abc' }, global: { plugins: [pinia] }, attachTo: document.body })
  await flushPromises()

  return wrapper
}

const report = (migrations: VitessMigration[], truncated = false): MigrationReport => ({ status: ok, truncated, migrations })

describe('MigrationsPane', () => {
  beforeEach(() => mocked.mockReset())

  it('reads the migrations of the connection', async () => {
    await mountPane(report([]))

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/migrations')
  })

  it('says there is nothing to follow when no migration exists', async () => {
    const wrapper = await mountPane(report([]))

    expect(wrapper.text()).toContain('Aucune migration')
  })

  it('shows each migration with its state, progress and table', async () => {
    const wrapper = await mountPane(report([migration()]))
    const row = wrapper.get('[data-test="migration-row"]')

    expect(row.text()).toContain('orders')
    expect(row.text()).toContain('en cours')
    expect(row.text()).toContain('40')
  })

  it('offers only the actions that make sense, labelled in French', async () => {
    const wrapper = await mountPane(report([migration(), migration({ uuid: 'aaaaaaaa_aaaa_aaaa_aaaa_aaaaaaaaaaaa', status: 'complete', actions: [] })]))
    const rows = wrapper.findAll('[data-test="migration-row"]')

    expect(rows[0]?.text()).toContain('Terminer')
    expect(rows[0]?.text()).toContain('Annuler')
    expect(rows[1]?.findAll('button')).toHaveLength(0)
  })

  it('asks for confirmation, showing the exact statement, before running an action', async () => {
    const wrapper = await mountPane(report([migration()]))
    mocked.mockRejectedValueOnce(new ApiError(409, 'confirmation_required', { reason: 'destructive_statement' }))

    await wrapper.findAll('[data-test="migration-action"]').find((button) => button.text().includes('Annuler'))?.trigger('click')
    await flushPromises()

    expect(document.body.textContent).toContain(`ALTER VITESS_MIGRATION '${UUID}' CANCEL`)
    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/abc/query', { sql: `ALTER VITESS_MIGRATION '${UUID}' CANCEL`, mode: 'read_write', confirmed: false })
    wrapper.unmount()
  })

  it('runs the action once confirmed, then reads the migrations again', async () => {
    const wrapper = await mountPane(report([migration()]))
    mocked.mockRejectedValueOnce(new ApiError(409, 'confirmation_required', { reason: 'destructive_statement' }))
    await wrapper.findAll('[data-test="migration-action"]').find((button) => button.text().includes('Annuler'))?.trigger('click')
    await flushPromises()
    mocked.mockResolvedValueOnce({ columns: [], rows: [], rowCount: 0 })
    mocked.mockResolvedValueOnce(report([migration({ status: 'cancelled', actions: [] })]))

    document.body.querySelector<HTMLButtonElement>('.confirm')!.click()
    await flushPromises()

    expect(mocked).toHaveBeenCalledWith('POST', '/web/connections/abc/query', { sql: `ALTER VITESS_MIGRATION '${UUID}' CANCEL`, mode: 'read_write', confirmed: true })
    expect(wrapper.get('[data-test="migration-row"]').text()).toContain('annulée')
    wrapper.unmount()
  })

  it('shows the engine refusal of an action instead of hiding it', async () => {
    const wrapper = await mountPane(report([migration()]))
    mocked.mockRejectedValueOnce(new ApiError(403, 'query_refused', { reason: 'write_not_permitted' }))

    await wrapper.findAll('[data-test="migration-action"]')[0]?.trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('says when not every migration is listed', async () => {
    const wrapper = await mountPane(report([migration()], true))

    expect(wrapper.text()).toContain('plus récentes')
  })

  it('shows the error when the connection is not a Vitess gateway', async () => {
    const wrapper = await mountPane(new ApiError(501, 'unsupported_operation', {}))

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })
})
