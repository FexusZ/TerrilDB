import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
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

  it('filters by name, ignoring case', async () => {
    const wrapper = await mountTree()

    await wrapper.get('input[type="search"]').setValue('ORDER')

    expect(wrapper.findAll('a.node').map((node) => node.get('span').text())).toEqual(['order_items', 'orders', 'v_order_totals'])
    expect(wrapper.get('.group').text()).toContain('Tables · 2')
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
})
