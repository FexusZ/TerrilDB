import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import type { HealthReport } from '../api/health'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'
import ServerHealthPane from './ServerHealthPane.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const ok = { availability: 'available', reason: null, privilege: null } as const

const report: HealthReport = {
  status: ok,
  seconds: 5.01,
  startedAt: '2026-11-12T22:00:00.000Z',
  endedAt: '2026-11-12T22:00:05.010Z',
  discarded: null,
  indicators: [
    { key: 'queries_per_second', value: 120, unit: 'per_second', level: 'ok', threshold: null },
    { key: 'buffer_pool_hit_percent', value: 95, unit: 'percent', level: 'warning', threshold: 99 },
    { key: 'aborted_connects_percent', value: null, unit: 'percent', level: 'unknown', threshold: 5 },
  ],
}

async function mountPane(answer: HealthReport | ApiError = report) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useConnectionsStore().items = [{ id: 'abc', name: 'Production', engine: 'mysql', createdAt: '', updatedAt: '' }]
  mocked.mockImplementation((...args: unknown[]) => {
    if (!String(args[1] ?? args[0]).includes('/diagnostics/health')) return Promise.resolve({ tables: [] })

    return answer instanceof ApiError ? Promise.reject(answer) : Promise.resolve(answer)
  })
  await useWorkspaceStore().open('abc')
  mocked.mockClear()
  const wrapper = mount(ServerHealthPane, { props: { id: 'abc' }, global: { plugins: [pinia] } })

  return { wrapper, pinia }
}

describe('ServerHealthPane', () => {
  beforeEach(() => mocked.mockReset())

  it('measures nothing until asked, then reads the chosen interval', async () => {
    const { wrapper } = await mountPane()
    expect(mocked).not.toHaveBeenCalled()

    await wrapper.find('select[name="seconds"]').setValue('10')
    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/health?seconds=10')
  })

  it('shows each indicator with its level and says unknown instead of zero', async () => {
    const { wrapper } = await mountPane()
    await wrapper.find('button').trigger('click')
    await flushPromises()

    const cards = wrapper.findAll('[data-testid="indicator"]')
    expect(cards).toHaveLength(3)
    expect(cards[0]?.text()).toContain('120 /s')
    expect(cards[1]?.attributes('data-level')).toBe('warning')
    expect(cards[1]?.text()).toContain('95 %')
    expect(cards[2]?.text()).toContain('inconnu')
    expect(wrapper.text()).toContain('repères')
  })

  it('explains a discarded measurement', async () => {
    const { wrapper } = await mountPane({ ...report, discarded: 'counters_reset', indicators: [] })
    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('redémarré')
    expect(wrapper.findAll('[data-testid="indicator"]')).toHaveLength(0)
  })

  it('says why a source cannot be read', async () => {
    const { wrapper } = await mountPane({ ...report, status: { availability: 'permission_denied', reason: 'insufficient_privilege', privilege: 'PROCESS' }, indicators: [] })
    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('PROCESS')
  })

  it('shows the failure of the read', async () => {
    const { wrapper } = await mountPane(new ApiError(501, 'unsupported_operation', {}))
    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })
})
