import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import type { LogsReport } from '../api/logs'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'
import ServerLogsPane from './ServerLogsPane.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const ok = { availability: 'available', reason: null, privilege: null } as const

const report: LogsReport = {
  errorLog: {
    status: ok,
    truncated: false,
    entries: [
      { loggedAt: '2026-11-14T10:00:00.000Z', level: 'warning', subsystem: 'Server', code: 'MY-010055', message: 'Access denied for user ?@?' },
      { loggedAt: '2026-11-14T09:00:00.000Z', level: 'system', subsystem: 'Server', code: 'MY-010931', message: 'ready for connections' },
    ],
  },
  slowQueries: { status: { availability: 'disabled', reason: 'log_output_without_table', privilege: null }, truncated: false, thresholdSeconds: 10, queries: [] },
}

async function mountPane(answer: LogsReport | ApiError = report) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useConnectionsStore().items = [{ id: 'abc', name: 'Production', engine: 'mysql', createdAt: '', updatedAt: '' }]
  mocked.mockImplementation((...args: unknown[]) => {
    if (!String(args[1] ?? args[0]).includes('/diagnostics/logs')) return Promise.resolve({ tables: [] })

    return answer instanceof ApiError ? Promise.reject(answer) : Promise.resolve(answer)
  })
  await useWorkspaceStore().open('abc')
  mocked.mockClear()
  const wrapper = mount(ServerLogsPane, { props: { id: 'abc' }, global: { plugins: [pinia] } })
  await flushPromises()

  return wrapper
}

describe('ServerLogsPane', () => {
  beforeEach(() => mocked.mockReset())

  it('reads the logs when opened', async () => {
    await mountPane()

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/logs')
  })

  it('lists the error log lines with their level', async () => {
    const wrapper = await mountPane()

    const lines = wrapper.findAll('[data-testid="error-line"]')
    expect(lines).toHaveLength(2)
    expect(lines[0]?.attributes('data-level')).toBe('warning')
    expect(lines[0]?.text()).toContain('Access denied for user ?@?')
    expect(lines[0]?.text()).toContain('MY-010055')
  })

  it('says why the slow log is not read and shows the activation command to read, never to run', async () => {
    const wrapper = await mountPane()

    expect(wrapper.find('[data-testid="slow-note"]').text()).toContain('log_output')
    const hint = wrapper.find('[data-testid="slow-hint"]')
    expect(hint.text()).toContain("SET GLOBAL log_output = 'TABLE'")
    expect(wrapper.find('[data-testid="slow-hint"] button').exists()).toBe(false)
  })

  it('shows the slow queries with their duration when readable', async () => {
    const wrapper = await mountPane({
      ...report,
      slowQueries: { status: ok, truncated: false, thresholdSeconds: 0.1, queries: [{ startedAt: '2026-11-14T10:00:00.000Z', user: 'app', database: 'shop', durationMs: 1523.4, lockMs: 0, rowsSent: 3, rowsExamined: 70000, query: 'SELECT * FROM t WHERE a = ?', queryReason: null }] },
    })

    const rows = wrapper.findAll('[data-testid="slow-query"]')
    expect(rows).toHaveLength(1)
    expect(rows[0]?.text()).toContain('1 523 ms')
    expect(rows[0]?.text()).toContain('SELECT * FROM t WHERE a = ?')
  })

  it('says a file log is not read, instead of showing an empty list', async () => {
    const unsupported = { availability: 'unsupported', reason: 'log_is_a_file', privilege: null } as const
    const wrapper = await mountPane({ errorLog: { status: unsupported, truncated: false, entries: [] }, slowQueries: { status: unsupported, truncated: false, thresholdSeconds: null, queries: [] } })

    expect(wrapper.find('[data-testid="error-note"]').text()).toContain('fichier')
    expect(wrapper.findAll('[data-testid="error-line"]')).toHaveLength(0)
  })

  it('warns about confidentiality', async () => {
    const wrapper = await mountPane()

    expect(wrapper.text()).toContain('masqués')
  })

  it('shows the failure of the read', async () => {
    const wrapper = await mountPane(new ApiError(501, 'unsupported_operation', {}))

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })
})
