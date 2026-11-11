import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import type { DiagnosticStatus, ServerVariable, VariableReport } from '../api/types'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'
import ServerVariablesPane from './ServerVariablesPane.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const ok: DiagnosticStatus = { availability: 'available', reason: null, privilege: null }
const variable = (extra: Partial<ServerVariable> = {}): ServerVariable => ({ name: 'max_connections', value: '200', default: '151', changed: true, masked: false, source: 'CONFIG', restart: null, scope: 'global', unit: null, ...extra })

const report: VariableReport = {
  status: ok,
  truncated: false,
  variables: [
    variable(),
    variable({ name: 'autocommit', value: 'ON', default: 'ON', changed: false, source: 'COMPILED' }),
    variable({ name: 'ssl_key', value: null, default: null, masked: true, source: 'GLOBAL' }),
    variable({ name: 'shared_buffers', value: '16384', default: '1024', unit: '8kB', restart: 'required' }),
  ],
}

function answer(value: VariableReport | ApiError = report): void {
  mocked.mockImplementation((_method, path) => (path.includes('/diagnostics/variables') ? (value instanceof ApiError ? Promise.reject(value) : Promise.resolve(value)) : Promise.resolve({ tables: [] })))
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
  const wrapper = mount(ServerVariablesPane, { props: { id: 'abc' }, global: { plugins: [pinia] } })
  await flushPromises()

  return wrapper
}

describe('ServerVariablesPane', () => {
  beforeEach(() => {
    mocked.mockReset()
    answer()
  })

  it('reads the variables of the explored database on opening', async () => {
    await mountPane({ database: 'shop', schema: 'public' })

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/variables?limit=100&database=shop&schema=public')
  })

  it('lists each variable with its value, its default and where it comes from', async () => {
    const wrapper = await mountPane()

    const rows = wrapper.findAll('[data-testid="variables"] tbody tr')
    expect(rows).toHaveLength(4)
    expect(rows[0]?.text()).toContain('max_connections')
    expect(rows[0]?.text()).toContain('200')
    expect(rows[0]?.text()).toContain('151')
    expect(rows[0]?.text()).toContain('fichier')
    expect(rows[3]?.text()).toContain('16384 8kB')
    expect(rows[3]?.text()).toContain('Redémarrage nécessaire')
  })

  it('stresses what differs from the default', async () => {
    const wrapper = await mountPane()

    const rows = wrapper.findAll('[data-testid="variables"] tbody tr')
    expect(rows[0]?.classes()).toContain('changed')
    expect(rows[1]?.classes()).not.toContain('changed')
  })

  it('shows a secret as masked, never a value', async () => {
    const wrapper = await mountPane()

    const row = wrapper.findAll('[data-testid="variables"] tbody tr')[2]
    expect(row?.text()).toContain('masquée')
  })

  it('searches by name and asks for the changed ones only', async () => {
    const wrapper = await mountPane()
    mocked.mockClear()

    await wrapper.find('input[name="search"]').setValue('buffer')
    await wrapper.find('input[name="changed"]').setValue(true)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(mocked).toHaveBeenCalledTimes(1)
    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/variables?q=buffer&changed=1&limit=100')
  })

  it('never offers to change a value', async () => {
    const wrapper = await mountPane()

    expect(wrapper.text()).toContain('Lecture seule')
    expect(wrapper.text()).not.toMatch(/SET GLOBAL|SET PERSIST/)
    expect(wrapper.findAll('button').map((button) => button.text())).toEqual(['Chercher'])
  })

  it('says when nothing matches, only for a readable source', async () => {
    answer({ status: ok, truncated: false, variables: [] })

    const wrapper = await mountPane()

    expect(wrapper.text()).toContain('Aucune variable ne correspond.')
  })

  it('does not call an unreadable source empty', async () => {
    answer({ status: { availability: 'disabled', reason: 'performance_schema_off', privilege: null }, truncated: false, variables: [] })

    const wrapper = await mountPane()

    expect(wrapper.text()).toContain('performance_schema est éteint')
    expect(wrapper.text()).not.toContain('Aucune variable ne correspond.')
    expect(wrapper.find('[data-testid="variables"]').exists()).toBe(false)
  })

  it('names the missing privilege', async () => {
    answer({ status: { availability: 'permission_denied', reason: 'insufficient_privilege', privilege: 'SELECT ON system.server_settings' }, truncated: false, variables: [] })

    const wrapper = await mountPane()

    expect(wrapper.text()).toContain('il lui manque SELECT ON system.server_settings')
  })

  it('says the list is cut when the server has more', async () => {
    answer({ ...report, truncated: true })

    const wrapper = await mountPane()

    expect(wrapper.text()).toContain('Seules les 100 premières')
  })

  it('shows a failure instead of an empty list', async () => {
    answer(new ApiError(422, 'invalid_input', {}))

    const wrapper = await mountPane()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="variables"]').exists()).toBe(false)
  })
})
