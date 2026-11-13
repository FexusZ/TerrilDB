import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import type { DigestReport } from '../api/types'
import TopQueriesPanel from './TopQueriesPanel.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const ok = { availability: 'available', reason: null, privilege: null } as const

const report: DigestReport = {
  status: ok,
  order: 'total_time',
  windowHours: null,
  digests: [
    { text: 'SELECT * FROM `orders` WHERE `status` = ?', textReason: null, database: 'shop', calls: 5000, totalMs: 90000, meanMs: 18, maxMs: 700, rowsExamined: 50000000, rowsReturned: 5000, ratio: 10000, noIndexUsed: 5000, tmpDiskTables: 0, sortMergePasses: 0, firstSeen: '2026-11-10T08:00:00Z', lastSeen: '2026-11-11T09:00:00Z' },
    { text: 'SELECT 1', textReason: null, database: null, calls: 3, totalMs: 0.4, meanMs: 0.13, maxMs: 0.2, rowsExamined: null, rowsReturned: null, ratio: null, noIndexUsed: null, tmpDiskTables: null, sortMergePasses: null, firstSeen: null, lastSeen: null },
  ],
}

function mountPanel(scope = { database: null as string | null, schema: null as string | null }) {
  return mount(TopQueriesPanel, { props: { id: 'abc', scope } })
}

describe('TopQueriesPanel', () => {
  beforeEach(() => {
    mocked.mockReset()
    mocked.mockResolvedValue(report)
  })

  it('says the window it read and withholds a text it could not mask', async () => {
    mocked.mockResolvedValue({ ...report, windowHours: 24, digests: [{ ...report.digests[0]!, text: null, textReason: 'redacted' }] })

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-testid="window"]').text()).toContain('24 dernières heures')
    expect(wrapper.text()).toContain('Texte non montré')
  })

  it('reads the costliest digests by total time on mount', async () => {
    mountPanel({ database: 'shop', schema: null })
    await flushPromises()

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/queries?order=total_time&limit=20&database=shop')
  })

  it('lists the digests with calls, durations and the examined over returned ratio', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    const rows = wrapper.findAll('[data-testid="digests"] tbody tr')
    expect(rows).toHaveLength(2)
    expect(rows[0]?.text()).toContain('SELECT * FROM `orders` WHERE `status` = ?')
    expect(rows[0]?.text()).toContain('5000')
    expect(rows[0]?.text()).toContain('1 min 30 s')
    expect(rows[0]?.text()).toContain('18 ms')
    expect(rows[0]?.text()).toContain('10 000')
    expect(rows[0]?.classes()).toContain('scan')
    expect(rows[1]?.classes()).not.toContain('scan')
  })

  it('reminds that the texts are digests, with the values masked, and that nothing is reset here', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.text()).toContain('valeurs')
    expect(wrapper.text()).toContain('masquées')
    expect(wrapper.text()).toContain('rien n’est remis à zéro')
  })

  it('reloads with another order and another limit', async () => {
    const wrapper = mountPanel()
    await flushPromises()
    mocked.mockClear()

    await wrapper.find('select[name="order"]').setValue('calls')
    await flushPromises()
    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/queries?order=calls&limit=20')

    await wrapper.find('select[name="limit"]').setValue('50')
    await flushPromises()
    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/abc/diagnostics/queries?order=calls&limit=50')
  })

  it('explains a switched-off source with the command to read, and does not call it an empty list', async () => {
    mocked.mockResolvedValue({ status: { availability: 'disabled', reason: 'extension_missing', privilege: null }, order: 'total_time', windowHours: null, digests: [] })

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.text()).toContain('pg_stat_statements')
    expect(wrapper.find('[data-testid="hint"]').text()).toContain('CREATE EXTENSION pg_stat_statements')
    expect(wrapper.find('[data-testid="digests"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Aucune requête')
  })

  it('names the missing privilege', async () => {
    mocked.mockResolvedValue({ status: { availability: 'permission_denied', reason: 'insufficient_privilege', privilege: 'SELECT ON performance_schema' }, order: 'total_time', windowHours: null, digests: [] })

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.text()).toContain('il lui manque SELECT ON performance_schema')
  })

  it('says when a readable source has nothing yet', async () => {
    mocked.mockResolvedValue({ status: ok, order: 'total_time', windowHours: null, digests: [] })

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.text()).toContain('Aucune requête enregistrée')
  })

  it('shows an engine error without hiding the controls', async () => {
    mocked.mockRejectedValueOnce(new ApiError(502, 'diagnostics_failed', {}))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.find('select[name="order"]').exists()).toBe(true)
  })

  it('never offers to reset the statistics', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.findAll('button').map((button) => button.text())).toEqual(['Actualiser'])
  })
})
