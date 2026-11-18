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

  describe('measured plan', () => {
    const measured = (root: PlanNodeInfo, findings: PlanResult['findings'] = [], extra: Partial<PlanResult> = {}): PlanResult => ({
      plan: { totalCost: 1, root, measured: true, executionTimeMs: 12.34 },
      findings,
      measure: { status: 'measured' },
      ...extra,
    })

    it('says the query was run and shows the real time', () => {
      const wrapper = mount(PlanTree, { props: { result: measured(node({ kind: 'limit', label: 'Limit', actualRows: 50, actualTimeMs: 0.5, loops: 1 })) } })

      expect(wrapper.get('.summary').text()).toContain('Plan mesuré')
      expect(wrapper.get('.summary').text()).toContain('réellement exécutée')
      expect(wrapper.get('.summary').text()).toMatch(/12,34 ms/)
      expect(wrapper.get('.summary').text()).not.toContain('n’a pas été exécutée')
    })

    it('shows the real rows next to the estimate, with loops, time and buffers', () => {
      const root = node({
        kind: 'index_scan', label: 'Index Scan', relation: 'customers', index: 'customers_pkey', rows: 1, actualRows: 1, actualTimeMs: 0.001, loops: 50,
        buffers: { sharedHit: 150, sharedRead: 2, tempRead: 0, tempWritten: 0 },
      })

      const wrapper = mount(PlanTree, { props: { result: measured(root) } })

      const row = wrapper.get('li.node').text()
      expect(row).toMatch(/réel 1 ligne/)
      expect(row).toMatch(/estimé ~\s?1/)
      expect(row).toContain('× 50')
      expect(row).toContain('0,001 ms')
      expect(row).toMatch(/150 pages en cache/)
      expect(row).toMatch(/2 lues sur disque/)
    })

    it('flags a node whose estimate is far off, and a sort on disk', () => {
      const root = node({ kind: 'sort', label: 'Sort', rows: 7000, actualRows: 7000, loops: 1, sortsOnDisk: true, children: [node({ kind: 'full_scan', label: 'Seq Scan', relation: 'o', rows: 1, actualRows: 3000, loops: 1, fullScan: true })] })

      const wrapper = mount(PlanTree, { props: { result: measured(root) } })

      const nodes = wrapper.findAll('li.node')
      expect(nodes[0]?.text()).toContain('tri sur disque')
      expect(nodes[1]?.classes()).toContain('off')
    })

    it('shows the findings of a measure with the suggestion to read', () => {
      const findings = [{ rule: 'stale_estimate', relation: 'orders', rows: 1, condition: null, actualRows: 3000, suggestion: 'ANALYZE "orders";' }]

      const wrapper = mount(PlanTree, { props: { result: measured(node({ relation: 'orders', rows: 1, actualRows: 3000 }), findings) } })

      expect(wrapper.get('li.finding').text()).toContain('statistiques')
      expect(wrapper.get('li.finding code').text()).toBe('ANALYZE "orders";')
      expect(wrapper.get('li.finding').text()).toContain('n’est jamais exécutée')
    })

    it('does not show a suggestion that does not exist', () => {
      const findings = [{ rule: 'stale_estimate', relation: 'o', rows: 1, condition: null, actualRows: 3000, suggestion: null }]

      const wrapper = mount(PlanTree, { props: { result: measured(node({}), findings) } })

      expect(wrapper.find('li.finding code').exists()).toBe(false)
    })

    it('keeps the estimated plan and says so when the engine cannot measure', () => {
      const wrapper = mount(PlanTree, { props: { result: { ...result(node({})), measure: { status: 'unsupported', reason: 'engine' } } } })

      expect(wrapper.get('.summary').text()).toContain('n’a pas été exécutée')
      expect(wrapper.get('.measure-note').text()).toContain('ne sait pas mesurer')
    })

    it('shows nothing of a measure on an estimated plan', () => {
      const wrapper = mount(PlanTree, { props: { result: result(node({ rows: 5 })) } })

      expect(wrapper.text()).not.toContain('réel')
      expect(wrapper.find('.measure-note').exists()).toBe(false)
    })
  })
})
