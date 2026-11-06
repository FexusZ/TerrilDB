import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'
import DatabasePicker from './DatabasePicker.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn(), apiDownload: vi.fn() }))

const mocked = vi.mocked(api)

async function mountPicker(databases: { name: string; system: boolean }[] | ApiError) {
  const pinia = createPinia()
  setActivePinia(pinia)
  mocked.mockResolvedValueOnce({ tables: [] })
  await useWorkspaceStore().open('c1')
  if (databases instanceof ApiError) {
    mocked.mockRejectedValueOnce(databases)
  } else {
    mocked.mockResolvedValueOnce({ default: 'shop', databases })
  }
  const wrapper = mount(DatabasePicker, { global: { plugins: [pinia] } })
  await flushPromises()

  return wrapper
}

describe('DatabasePicker', () => {
  beforeEach(() => mocked.mockReset())

  it('offers the databases of the server and tells which one is current', async () => {
    const wrapper = await mountPicker([{ name: 'shop', system: false }, { name: 'reporting', system: false }])

    const options = wrapper.findAll('option')
    expect(options.map((option) => option.text())).toEqual(['Base par défaut', 'shop', 'reporting'])
    expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('')
  })

  it('switches the workspace to the chosen database', async () => {
    const wrapper = await mountPicker([{ name: 'shop', system: false }, { name: 'reporting', system: false }])
    mocked.mockResolvedValueOnce({ tables: [] })

    await wrapper.get('select').setValue('reporting')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/c1/tables?limit=100&database=reporting')
    expect(useWorkspaceStore().database).toBe('reporting')
  })

  it('goes back to the default database', async () => {
    const wrapper = await mountPicker([{ name: 'shop', system: false }, { name: 'reporting', system: false }])
    mocked.mockResolvedValueOnce({ tables: [] })
    await wrapper.get('select').setValue('reporting')
    await flushPromises()
    mocked.mockResolvedValueOnce({ tables: [] })

    await wrapper.get('select').setValue('')
    await flushPromises()

    expect(useWorkspaceStore().database).toBeNull()
    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/c1/tables?limit=100')
  })

  it('stays hidden when the engine has no database list', async () => {
    const wrapper = await mountPicker(new ApiError(501, 'unsupported_operation'))

    expect(wrapper.find('select').exists()).toBe(false)
  })

  it('stays hidden when there is nothing to choose between', async () => {
    const wrapper = await mountPicker([{ name: 'shop', system: false }])

    expect(wrapper.find('select').exists()).toBe(false)
  })

  it('says why the list is missing when the server could not be reached', async () => {
    const wrapper = await mountPicker(new ApiError(502, 'target_unreachable'))

    expect(wrapper.get('[role="alert"]').text()).toContain('joindre')
  })

  it('offers no "default" entry when the connection names no database', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    mocked.mockResolvedValueOnce({ tables: [] })
    await useWorkspaceStore().open('c1')
    mocked.mockResolvedValueOnce({ default: null, databases: [{ name: 'shop', system: false }, { name: 'reporting', system: false }] })
    mocked.mockResolvedValueOnce({ tables: [] })
    const wrapper = mount(DatabasePicker, { global: { plugins: [pinia] } })
    await flushPromises()

    expect(wrapper.findAll('#database option').map((option) => option.text())).toEqual(['shop', 'reporting'])
    expect((wrapper.get('#database').element as HTMLSelectElement).value).toBe('shop')
  })

  it('offers the schemas of a PostgreSQL database next to the databases', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    useConnectionsStore().items = [{ id: 'c1', name: 'pg', engine: 'postgresql', createdAt: '', updatedAt: '' }]
    mocked.mockResolvedValueOnce({ tables: [] })
    await useWorkspaceStore().open('c1')
    mocked.mockResolvedValueOnce({ default: 'shop', databases: [{ name: 'shop', system: false }] })
    mocked.mockResolvedValueOnce({ schemas: [{ name: 'public', system: false }, { name: 'sales', system: false }] })
    const wrapper = mount(DatabasePicker, { global: { plugins: [pinia] } })
    await flushPromises()
    mocked.mockResolvedValueOnce({ tables: [] })

    expect(wrapper.findAll('#schema option').map((option) => option.text())).toEqual(['Schéma par défaut', 'public', 'sales'])
    await wrapper.get('#schema').setValue('sales')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/c1/tables?limit=100&schema=sales')
    expect(useWorkspaceStore().schema).toBe('sales')
  })
})
