import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import type { KeyDetail } from '../api/types'
import KeyPane from './KeyPane.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const hash: KeyDetail = { name: 'profile:1', type: 'hash', ttl: 120, size: 2, columns: ['field', 'value'], rows: [['name', 'Alice'], ['country', 'FR']], truncated: false }

function mountPane(props: Record<string, unknown> = {}) {
  setActivePinia(createPinia())

  return mount(KeyPane, { props: { id: 'abc', name: 'profile:1', ...props } })
}

describe('KeyPane', () => {
  beforeEach(() => mocked.mockReset())

  it('loads the key and shows its content as a table', async () => {
    mocked.mockResolvedValueOnce({ key: hash })

    const wrapper = mountPane()
    await flushPromises()

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/key?name=profile%3A1')
    expect(wrapper.get('h1').text()).toBe('profile:1')
    expect(wrapper.findAll('th').map((cell) => cell.text())).toEqual(['field', 'value'])
    expect(wrapper.findAll('tbody tr')).toHaveLength(2)
    expect(wrapper.text()).toContain('Alice')
  })

  it('tells the type, the size and the expiry', async () => {
    mocked.mockResolvedValueOnce({ key: hash })

    const wrapper = mountPane()
    await flushPromises()

    const chips = wrapper.findAll('.chip').map((chip) => chip.text())
    expect(chips).toContain('hash')
    expect(chips).toContain('2 éléments')
    expect(chips).toContain('expire dans 2 min')
  })

  it('says when a key never expires', async () => {
    mocked.mockResolvedValueOnce({ key: { ...hash, ttl: null } })

    const wrapper = mountPane()
    await flushPromises()

    expect(wrapper.findAll('.chip').map((chip) => chip.text())).toContain('sans expiration')
  })

  it('warns that only the first rows are shown', async () => {
    mocked.mockResolvedValueOnce({ key: { ...hash, size: 5000, truncated: true } })

    const wrapper = mountPane()
    await flushPromises()

    expect(wrapper.get('.truncated').text()).toContain('2 premières')
    expect(wrapper.get('.truncated').text()).toMatch(/5\s?000/)
  })

  it('reloads when another key is chosen', async () => {
    mocked.mockResolvedValueOnce({ key: hash })
    const wrapper = mountPane()
    await flushPromises()

    mocked.mockResolvedValueOnce({ key: { ...hash, name: 'user:1', type: 'string', columns: ['value'], rows: [['Alice']], size: 5 } })
    await wrapper.setProps({ name: 'user:1' })
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('user:1')
    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/abc/key?name=user%3A1')
  })

  it('says so when the key is gone', async () => {
    mocked.mockRejectedValueOnce(new ApiError(404, 'not_found'))

    const wrapper = mountPane()
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('introuvable')
  })
})
