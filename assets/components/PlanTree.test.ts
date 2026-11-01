import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import type { PlanNodeInfo, PlanResult } from '../api/types'
import PlanTree from './PlanTree.vue'

const node = (extra: Partial<PlanNodeInfo>): PlanNodeInfo => ({ kind: 'other', label: 'Query', relation: null, index: null, rows: null, cost: null, condition: null, fullScan: false, children: [], ...extra })

const result = (root: PlanNodeInfo, findings: PlanResult['findings'] = [], totalCost: number | null = 12.5): PlanResult => ({ plan: { totalCost, root }, findings })

describe('PlanTree', () => {
  it('shows the operations as a tree', () => {
    const root = node({
      kind: 'join', label: 'Nested Loop',
      children: [
        node({ kind: 'index_scan', label: 'Index Scan', relation: 'orders', index: 'orders_pkey', rows: 750, cost: 119.78, condition: "(status = 'shipped')" }),
        node({ kind: 'full_scan', label: 'Seq Scan', relation: 'customers', rows: 1000, fullScan: true }),
      ],
    })

    const wrapper = mount(PlanTree, { props: { result: result(root) } })

    const nodes = wrapper.findAll('li.node')
    expect(nodes).toHaveLength(3)
    expect(nodes[0]?.text()).toContain('Jointure')
    expect(nodes[1]?.text()).toContain('Recherche par index')
    expect(nodes[1]?.text()).toContain('orders')
    expect(nodes[1]?.text()).toContain('orders_pkey')
    expect(nodes[1]?.text()).toMatch(/~\s?750 lignes/)
    expect(nodes[1]?.text()).toContain("(status = 'shipped')")
    expect(wrapper.findAll('ul.children')).toHaveLength(1)
  })

  it('flags a full scan', () => {
    const root = node({ kind: 'full_scan', label: 'Seq Scan', relation: 'order_items', rows: 7000, fullScan: true })

    const wrapper = mount(PlanTree, { props: { result: result(root) } })

    expect(wrapper.get('li.node').classes()).toContain('scan')
    expect(wrapper.get('li.node').text()).toContain('Parcours complet')
  })

  it('shows the estimated total cost, and says it is an estimate', () => {
    const wrapper = mount(PlanTree, { props: { result: result(node({}), [], 12.5) } })

    expect(wrapper.get('.summary').text()).toMatch(/Coût estimé : 12,5/)
    expect(wrapper.get('.summary').text()).toContain('estimation')
  })

  it('leaves the cost out when the engine gives none', () => {
    const wrapper = mount(PlanTree, { props: { result: result(node({}), [], null) } })

    expect(wrapper.get('.summary').text()).not.toContain('Coût estimé')
  })

  it('lists what deserves attention', () => {
    const wrapper = mount(PlanTree, { props: { result: result(node({}), [{ rule: 'full_scan', relation: 'orders', rows: 50000, condition: '(status = 1)' }]) } })

    const items = wrapper.findAll('li.finding')
    expect(items).toHaveLength(1)
    expect(items[0]?.text()).toContain('Parcours complet de « orders »')
  })

  it('says when nothing stands out', () => {
    const wrapper = mount(PlanTree, { props: { result: result(node({}), []) } })

    expect(wrapper.get('.fine').text()).toContain('Rien d’inquiétant')
  })
})
