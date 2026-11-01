import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { KeyEntry } from '../api/types'
import KeyBrowser from './KeyBrowser.vue'

const keys: KeyEntry[] = [
  { name: 'user:1', type: 'string', ttl: null },
  { name: 'session:abc', type: 'hash', ttl: 3600 },
  { name: 'queue', type: 'list', ttl: 45 },
]

async function mountBrowser(props: Record<string, unknown> = {}) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/connections/:id', name: 'workspace', component: { template: '<p />' } },
      { path: '/connections/:id/key', name: 'key', component: { template: '<p />' } },
    ],
  })
  await router.push('/connections/abc')

  return mount(KeyBrowser, { props: { connectionId: 'abc', keys, total: 3, hasMore: false, loading: false, ...props }, global: { plugins: [router] } })
}

describe('KeyBrowser', () => {
  it('lists the keys with their type and expiry', async () => {
    const wrapper = await mountBrowser()

    const nodes = wrapper.findAll('a.node')
    expect(nodes).toHaveLength(3)
    expect(nodes[0]?.text()).toContain('user:1')
    expect(nodes[0]?.get('.type').text()).toBe('string')
    expect(nodes[0]?.find('small').exists()).toBe(false)
    expect(nodes[1]?.get('small').text()).toBe('1 h')
    expect(nodes[2]?.get('small').text()).toBe('45 s')
  })

  it('links each key to its page, whatever characters its name holds', async () => {
    const wrapper = await mountBrowser({ keys: [{ name: 'a/b?c=d&e', type: 'string', ttl: null }] })

    const href = wrapper.get('a.node').attributes('href') ?? ''
    expect(new URL(href, 'http://localhost').searchParams.get('name')).toBe('a/b?c=d&e')
  })

  it('shows the total number of keys of the database', async () => {
    const wrapper = await mountBrowser({ total: 12845 })

    expect(wrapper.get('.total').text()).toMatch(/12\s?845/)
  })

  it('searches by pattern when the form is submitted', async () => {
    const wrapper = await mountBrowser()

    await wrapper.get('input[type="search"]').setValue('user:*')
    await wrapper.get('form').trigger('submit')

    expect(wrapper.emitted('search')).toEqual([['user:*']])
  })

  it('searches everything when the pattern is emptied', async () => {
    const wrapper = await mountBrowser()

    await wrapper.get('input[type="search"]').setValue('  ')
    await wrapper.get('form').trigger('submit')

    expect(wrapper.emitted('search')).toEqual([['*']])
  })

  it('offers to load more only while there is more', async () => {
    const without = await mountBrowser()
    expect(without.find('button.more').exists()).toBe(false)

    const wrapper = await mountBrowser({ hasMore: true })
    await wrapper.get('button.more').trigger('click')

    expect(wrapper.emitted('more')).toHaveLength(1)
  })

  it('disables the button while loading', async () => {
    const wrapper = await mountBrowser({ hasMore: true, loading: true })

    expect(wrapper.get('button.more').attributes('disabled')).toBeDefined()
  })

  it('says so when nothing matches, and why the list cannot be shown', async () => {
    const empty = await mountBrowser({ keys: [], total: 0 })
    expect(empty.get('.note').text()).toContain('Aucune clé')

    const failed = await mountBrowser({ keys: [], error: 'Impossible de joindre la base.' })
    expect(failed.get('[role="alert"]').text()).toContain('joindre')
  })

  it('highlights the key that is open', async () => {
    const wrapper = await mountBrowser({ current: 'session:abc' })

    expect(wrapper.findAll('a.node').map((node) => node.classes().includes('cur'))).toEqual([false, true, false])
  })
})
