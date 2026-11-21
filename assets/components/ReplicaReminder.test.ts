import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import ReplicaReminder from './ReplicaReminder.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)

describe('ReplicaReminder', () => {
  beforeEach(() => mocked.mockReset())

  it('shows nothing when no replica has appeared', async () => {
    mocked.mockResolvedValueOnce({ available: false })

    const wrapper = mount(ReplicaReminder, { props: { id: 'c1' } })
    await flushPromises()

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/c1/replica')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('offers to read on the replica that appeared, and does nothing by itself', async () => {
    mocked.mockResolvedValueOnce({ available: true })

    const wrapper = mount(ReplicaReminder, { props: { id: 'c1' } })
    await flushPromises()

    expect(wrapper.get('[role="status"]').text()).toContain('réplica')
    expect(mocked).toHaveBeenCalledTimes(1)
  })

  it('enables the replica reading when asked, then goes away and tells the page', async () => {
    mocked.mockResolvedValueOnce({ available: true })
    const wrapper = mount(ReplicaReminder, { props: { id: 'c1' } })
    await flushPromises()
    mocked.mockResolvedValueOnce(undefined)

    await wrapper.get('button.enable').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/c1/replica/enable')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    expect(wrapper.emitted('enabled')).toHaveLength(1)
  })

  it('postpones by a week on « Plus tard »', async () => {
    mocked.mockResolvedValueOnce({ available: true })
    const wrapper = mount(ReplicaReminder, { props: { id: 'c1' } })
    await flushPromises()
    mocked.mockResolvedValueOnce(undefined)

    await wrapper.get('button.later').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/c1/replica/later')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('says why enabling failed and keeps the offer', async () => {
    mocked.mockResolvedValueOnce({ available: true })
    const wrapper = mount(ReplicaReminder, { props: { id: 'c1' } })
    await flushPromises()
    mocked.mockRejectedValueOnce(new ApiError(422, 'replica_unavailable', { reason: 'no_replica' }))

    await wrapper.get('button.enable').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('aucun réplica')
    expect(wrapper.find('button.enable').exists()).toBe(true)
  })

  it('stays silent when the check itself fails (the reminder is optional)', async () => {
    mocked.mockRejectedValueOnce(new ApiError(502, 'target_unreachable'))

    const wrapper = mount(ReplicaReminder, { props: { id: 'c1' } })
    await flushPromises()

    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('checks again for another connection', async () => {
    mocked.mockResolvedValue({ available: false })
    const wrapper = mount(ReplicaReminder, { props: { id: 'c1' } })
    await flushPromises()

    await wrapper.setProps({ id: 'c2' })
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/c2/replica')
  })
})
