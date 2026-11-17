import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import type { ConfigAuditReport, ConfigFinding, DiagnosticStatus } from '../api/types'
import ConfigAuditPanel from './ConfigAuditPanel.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const ok: DiagnosticStatus = { availability: 'available', reason: null, privilege: null }
const finding = (extra: Partial<ConfigFinding> = {}): ConfigFinding => ({
  rule: 'fsync_off',
  severity: 'high',
  variable: 'fsync',
  value: 'off',
  recommended: 'on',
  suggestion: "ALTER SYSTEM SET fsync = 'on'; SELECT pg_reload_conf();",
  explanation: 'Sans fsync, une panne peut corrompre la base.',
  ...extra,
})
const report = (extra: Partial<ConfigAuditReport> = {}): ConfigAuditReport => ({ status: ok, engine: 'postgresql', evaluated: 11, rulesTotal: 11, findings: [finding()], ...extra })

function answer(value: ConfigAuditReport): void {
  mocked.mockResolvedValue(value)
}

async function mountPanel() {
  const wrapper = mount(ConfigAuditPanel, { props: { id: 'abc', database: 'shop', schema: null } })
  await wrapper.get('button').trigger('click')
  await flushPromises()

  return wrapper
}

describe('ConfigAuditPanel', () => {
  beforeEach(() => mocked.mockReset())

  it('reads nothing until asked', () => {
    const wrapper = mount(ConfigAuditPanel, { props: { id: 'abc', database: null, schema: null } })

    expect(mocked).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Audit de la configuration')
  })

  it('reads the audit of the explored database on demand', async () => {
    answer(report())

    await mountPanel()

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/config-audit?database=shop')
  })

  it('shows each finding with its severity, explanation, recommendation and a command to read', async () => {
    answer(report())

    const wrapper = await mountPanel()

    const row = wrapper.get('[data-testid="config-findings"] li')
    expect(row.text()).toContain('Grave')
    expect(row.text()).toContain('fsync')
    expect(row.text()).toContain('Sans fsync, une panne peut corrompre la base.')
    expect(row.text()).toContain('off')
    expect(row.text()).toContain('on')
    expect(row.get('.cmd').text()).toBe("ALTER SYSTEM SET fsync = 'on'; SELECT pg_reload_conf();")
    expect(wrapper.text()).toContain('rien n’est exécuté')
  })

  it('says how many rules could be judged, and that nothing found is not proof', async () => {
    answer(report({ findings: [], evaluated: 8, rulesTotal: 11 }))

    const wrapper = await mountPanel()

    expect(wrapper.text()).toContain('Aucune constatation')
    expect(wrapper.text()).toContain('8 règles sur 11')
  })

  it('shows text as text, never as markup', async () => {
    answer(report({ findings: [finding({ explanation: '<img src=x onerror=alert(1)>', value: '<b>x</b>' })] }))

    const wrapper = await mountPanel()

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('b').exists()).toBe(false)
    expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>')
  })

  it('explains an unreadable source instead of showing an empty list', async () => {
    answer(report({ status: { availability: 'permission_denied', reason: 'insufficient_privilege', privilege: 'SELECT ON system.server_settings' }, findings: [], evaluated: 0 }))

    const wrapper = await mountPanel()

    expect(wrapper.text()).toContain('SELECT ON system.server_settings')
    expect(wrapper.find('[data-testid="config-findings"]').exists()).toBe(false)
  })

  it('says an engine has no rule', async () => {
    answer(report({ status: { availability: 'unsupported', reason: 'no_rules', privilege: null }, engine: 'clickhouse', findings: [], evaluated: 0, rulesTotal: 0 }))

    const wrapper = await mountPanel()

    expect(wrapper.text()).toContain('Aucune règle d’audit pour ce moteur')
  })

  it('shows a failure', async () => {
    mocked.mockRejectedValueOnce(new ApiError(502, 'diagnostics_failed'))

    const wrapper = await mountPanel()

    expect(wrapper.get('[role="alert"]').text()).not.toBe('')
  })
})
