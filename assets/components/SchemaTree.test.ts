import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { TableSummary } from '../api/types'
import SchemaTree from './SchemaTree.vue'

const tables: TableSummary[] = [
  { name: 'categories', kind: 'table', estimatedRows: 24 },
  { name: 'order_items', kind: 'table', estimatedRows: 11420 },
  { name: 'orders', kind: 'table', estimatedRows: null },
  { name: 'v_order_totals', kind: 'view', estimatedRows: null },
]

async function mountTree(props: Record<string, unknown> = {}) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/connections/:id', name: 'workspace', component: { template: '<p />' } },
      { path: '/connections/:id/audit', name: 'audit', component: { template: '<p />' } },
      { path: '/connections/:id/diagram', name: 'diagram', component: { template: '<p />' } },
      { path: '/connections/:id/tables/:name(.+)', name: 'table', component: { template: '<p />' } },
    ],
  })
  await router.push('/connections/abc')

  return mount(SchemaTree, { props: { connectionId: 'abc', tables, ...props }, global: { plugins: [router] } })
}

describe('SchemaTree', () => {
  it('groups tables and views with their counts', async () => {
    const wrapper = await mountTree()

    const groups = wrapper.findAll('.group')
    expect(groups[0]?.text()).toContain('Tables · 3')
    expect(groups[1]?.text()).toContain('Vues · 1')
    expect(wrapper.findAll('a.node')).toHaveLength(4)
  })

  it('shows estimated row counts with a tilde, and nothing when unknown', async () => {
    const wrapper = await mountTree()

    const nodes = wrapper.findAll('a.node')
    expect(nodes[0]?.text()).toContain('~ 24')
    expect(nodes[1]?.text()).toMatch(/~\s?11\s?420/)
    expect(nodes[2]?.find('small').exists()).toBe(false)
    expect(nodes[3]?.text()).toContain('vue')
  })

  it('links to the table page', async () => {
    const wrapper = await mountTree()

    expect(wrapper.findAll('a.node')[1]?.attributes('href')).toBe('/connections/abc/tables/order_items')
  })

  describe('with timers', () => {
    beforeEach(() => vi.useFakeTimers())
    afterEach(() => vi.useRealTimers())

    it('asks the server to search once typing pauses, instead of filtering what is loaded', async () => {
      const wrapper = await mountTree()

      await wrapper.get('input[type="search"]').setValue('ORD')
      await wrapper.get('input[type="search"]').setValue('ORDER')
      expect(wrapper.emitted('search')).toBeUndefined()
      vi.advanceTimersByTime(300)

      expect(wrapper.emitted('search')).toEqual([['ORDER']])
      expect(wrapper.findAll('a.node')).toHaveLength(4)
    })

    it('sends an empty search when the field is cleared', async () => {
      const wrapper = await mountTree()
      await wrapper.get('input[type="search"]').setValue('ORDER')
      vi.advanceTimersByTime(300)

      await wrapper.get('input[type="search"]').setValue('')
      vi.advanceTimersByTime(300)

      expect(wrapper.emitted('search')).toEqual([['ORDER'], ['']])
    })
  })

  it('counts what is loaded against the total of the database', async () => {
    const wrapper = await mountTree({ total: 1240 })

    expect(wrapper.get('.counter').text()).toMatch(/4\s*\/\s*1\s?240/)
  })

  it('offers to load the next hundred only when there are more', async () => {
    const without = await mountTree({ hasMore: false })
    expect(without.find('button.more').exists()).toBe(false)

    const wrapper = await mountTree({ hasMore: true })
    await wrapper.get('button.more').trigger('click')

    expect(wrapper.get('button.more').text()).toContain('100 suivantes')
    expect(wrapper.emitted('more')).toHaveLength(1)
  })

  it('disables the button and says so while a page loads', async () => {
    const wrapper = await mountTree({ hasMore: true, loading: true })

    expect(wrapper.get('button.more').attributes('disabled')).toBeDefined()
    expect(wrapper.get('button.more').text()).toContain('Chargement')
  })

  it('says when a search finds nothing, instead of claiming the database is empty', async () => {
    const wrapper = await mountTree({ tables: [], search: 'zzz' })

    expect(wrapper.text()).toContain('Aucune table ne correspond')
    expect(wrapper.text()).not.toContain('Aucune table dans cette base')
  })

  it('tells tables from views with an icon, not only by the group', async () => {
    const wrapper = await mountTree()

    expect(wrapper.findAll('a.node')[0]?.find('.icon.table').exists()).toBe(true)
    expect(wrapper.findAll('a.node')[3]?.find('.icon.view').exists()).toBe(true)
  })

  it('marks the open table', async () => {
    const wrapper = await mountTree({ current: 'orders' })

    const current = wrapper.findAll('a.node').filter((node) => node.classes().includes('cur'))
    expect(current).toHaveLength(1)
    expect(current[0]?.text()).toContain('orders')
    expect(current[0]?.attributes('aria-current')).toBe('page')
  })

  it('explains why there is nothing to show', async () => {
    const wrapper = await mountTree({ tables: [], error: 'Impossible de joindre la base.' })

    expect(wrapper.get('[role="alert"]').text()).toContain('joindre')
  })

  it('says when the database has no table', async () => {
    const wrapper = await mountTree({ tables: [] })

    expect(wrapper.text()).toContain('Aucune table')
  })

  it('reminds that row counts are estimates', async () => {
    const wrapper = await mountTree()

    expect(wrapper.text()).toContain('estimations')
  })

  it('leads to the schema audit', async () => {
    const wrapper = await mountTree()

    expect(wrapper.get('a.audit').attributes('href')).toBe('/connections/abc/audit')
  })

  it('leads to the entity-relation diagram', async () => {
    const wrapper = await mountTree()

    expect(wrapper.get('a.diagram').attributes('href')).toBe('/connections/abc/diagram')
  })

  it('still leads to the audit when the database has no table', async () => {
    const wrapper = await mountTree({ tables: [] })

    expect(wrapper.find('a.audit').exists()).toBe(true)
  })
})
