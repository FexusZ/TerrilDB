import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import type { AccountEntry, AccountReport, ConnectionEncryption, DiagnosticStatus } from '../api/types'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'
import AccountsPane from './AccountsPane.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const ok: DiagnosticStatus = { availability: 'available', reason: null, privilege: null }

const account = (overrides: Partial<AccountEntry>): AccountEntry => ({
  user: 'app', host: '%', name: 'app@%', plugin: 'caching_sha2_password', passwordSet: true, passwordExpired: false, locked: false, tls: 'none', isRole: false, current: false,
  roles: [], defaultRoles: [], globalPrivileges: [], databasePrivileges: {}, grantOptionOn: [], limits: {}, ...overrides,
})

const report: AccountReport = {
  status: ok,
  truncated: false,
  privilegesIncomplete: false,
  activatesAllRoles: false,
  accounts: [
    account({ user: 'admin', host: 'localhost', name: 'admin@localhost', current: true, globalPrivileges: ['FILE', 'SELECT'] }),
    account({ databasePrivileges: { shop: ['SELECT', 'INSERT'] }, roles: ['reader'], tls: 'any' }),
  ],
  findings: [
    { rule: 'dangerous_privilege', severity: 'high', account: 'admin@localhost', detail: 'FILE', currentAccount: true },
    { rule: 'wildcard_host', severity: 'medium', account: 'app@%', detail: null, currentAccount: false },
  ],
}
const encryption: ConnectionEncryption = { status: ok, encrypted: false, protocol: null, cipher: null }

function answer(overrides: { accounts?: AccountReport | ApiError; encryption?: ConnectionEncryption | ApiError } = {}): void {
  mocked.mockImplementation((_method, path) => {
    const value = path.includes('/diagnostics/accounts') ? (overrides.accounts ?? report) : path.includes('/diagnostics/encryption') ? (overrides.encryption ?? encryption) : { tables: [] }
    return value instanceof ApiError ? Promise.reject(value) : Promise.resolve(value)
  })
}

async function mountPane() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useConnectionsStore().items = [{ id: 'abc', name: 'Production', engine: 'mysql', createdAt: '', updatedAt: '' }]
  await useWorkspaceStore().open('abc')
  mocked.mockClear()
  const wrapper = mount(AccountsPane, { props: { id: 'abc' }, global: { plugins: [pinia] } })
  await flushPromises()

  return wrapper
}

describe('AccountsPane', () => {
  beforeEach(() => {
    mocked.mockReset()
    answer()
  })

  it('reads the accounts and the encryption of the connection', async () => {
    await mountPane()

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/accounts')
    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/encryption')
  })

  it('lists the findings, the most serious first, with a sentence each', async () => {
    const wrapper = await mountPane()

    const lines = wrapper.findAll('[data-testid="findings"] li')
    expect(lines).toHaveLength(2)
    expect(lines[0]?.text()).toContain('Élevée')
    expect(lines[0]?.text()).toContain('admin@localhost')
    expect(lines[0]?.text()).toContain('FILE')
    expect(lines[1]?.text()).toContain('app@%')
  })

  it('lists the accounts with their plugin, roles and privileges, and marks the one of TerrilDB', async () => {
    const wrapper = await mountPane()

    const rows = wrapper.findAll('[data-testid="accounts"] tbody tr')
    expect(rows).toHaveLength(2)
    expect(rows[0]?.text()).toContain('TerrilDB')
    expect(rows[0]?.text()).toContain('FILE')
    expect(rows[1]?.text()).toContain('reader')
    expect(rows[1]?.text()).toContain('shop')
    expect(rows[1]?.text()).toContain('caching_sha2_password')
  })

  it('says whether the connection of TerrilDB is encrypted', async () => {
    const wrapper = await mountPane()

    expect(wrapper.text()).toContain('n’est pas chiffrée')
  })

  it('names the missing privilege instead of an empty list', async () => {
    answer({ accounts: { ...report, status: { availability: 'permission_denied', reason: 'insufficient_privilege', privilege: 'SELECT ON mysql' }, accounts: [], findings: [] } })

    const wrapper = await mountPane()

    expect(wrapper.text()).toContain('SELECT ON mysql')
    expect(wrapper.find('[data-testid="accounts"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Aucune constatation')
  })

  it('keeps the accounts when the encryption cannot be read', async () => {
    answer({ encryption: new ApiError(502, 'diagnostics_failed', {}) })

    const wrapper = await mountPane()

    expect(wrapper.findAll('[data-testid="accounts"] tbody tr')).toHaveLength(2)
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('says the check is incomplete instead of clean when the privileges were cut', async () => {
    answer({ accounts: { ...report, privilegesIncomplete: true, findings: [] } })

    const wrapper = await mountPane()

    expect(wrapper.text()).toContain('incomplète')
    expect(wrapper.text()).not.toContain('Aucune constatation')
  })

  it('says so when there is nothing to report', async () => {
    answer({ accounts: { ...report, findings: [] } })

    const wrapper = await mountPane()

    expect(wrapper.text()).toContain('Aucune constatation')
  })

  it('is read-only: no button other than refresh', async () => {
    const wrapper = await mountPane()

    expect(wrapper.findAll('button').map((b) => b.text())).toEqual(['Actualiser'])
  })
})
