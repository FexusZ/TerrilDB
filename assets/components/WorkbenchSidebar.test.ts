import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { ConnectionSummary } from '../api/types'
import { useConnectionsStore } from '../stores/connections'
import WorkbenchSidebar from './WorkbenchSidebar.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const connection: ConnectionSummary = { id: 'c1', name: 'Production', engine: 'postgresql', createdAt: '', updatedAt: '' }

async function mountSidebar() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useConnectionsStore().items = [connection]
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['connections', 'connection-new', 'connection-edit', 'workspace', 'table', 'key'].map((name) => ({ path: `/${name}/:id?/:name?`, name, component: { template: '<p />' } })),
  })
  await router.push('/connections')
  const wrapper = mount(WorkbenchSidebar, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()

  return wrapper
}

describe('WorkbenchSidebar', () => {
  beforeEach(() => localStorage.clear())

  it('holds the tree of connections and the way to add or manage them', async () => {
    const wrapper = await mountSidebar()

    expect(wrapper.find('[role="tree"]').exists()).toBe(true)
    expect(wrapper.get('a.add').attributes('href')).toBe('/connection-new')
    expect(wrapper.get('a.manage').attributes('href')).toBe('/connections')
    wrapper.unmount()
  })

  it('can be folded away and brought back, and remembers it', async () => {
    const wrapper = await mountSidebar()
    const toggle = wrapper.get('button.fold')
    expect(toggle.attributes('aria-expanded')).toBe('true')

    await toggle.trigger('click')

    expect(wrapper.find('[role="tree"]').exists()).toBe(false)
    expect(wrapper.get('button.fold').attributes('aria-expanded')).toBe('false')
    expect(wrapper.get('button.fold').attributes('aria-label')).toContain('Afficher')
    expect(localStorage.getItem('terrildb.sidebar')).toBe('folded')

    await wrapper.get('button.fold').trigger('click')
    expect(wrapper.find('[role="tree"]').exists()).toBe(true)
    expect(localStorage.getItem('terrildb.sidebar')).toBe('open')
    wrapper.unmount()
  })

  it('starts folded when it was left folded', async () => {
    localStorage.setItem('terrildb.sidebar', 'folded')

    const wrapper = await mountSidebar()

    expect(wrapper.find('[role="tree"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('still works when the browser refuses local storage', async () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('denied') })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('denied') })

    const wrapper = await mountSidebar()
    await wrapper.get('button.fold').trigger('click')

    expect(wrapper.find('[role="tree"]').exists()).toBe(false)
    vi.restoreAllMocks()
    wrapper.unmount()
  })

  it('passes on the deletion of a connection', async () => {
    const wrapper = await mountSidebar()

    wrapper.findComponent({ name: 'ConnectionTree' }).vm.$emit('deleted', 'c1')

    expect(wrapper.emitted('deleted')).toEqual([['c1']])
    wrapper.unmount()
  })
})
