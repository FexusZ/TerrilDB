import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import type { QueryResult } from '../api/types'
import ResultTable from './ResultTable.vue'

const base: QueryResult = { columns: ['country', 'commandes', 'actif', 'note'], rows: [['FR', 1284, true, null], ['DE', 702, false, 'ok']], rowCount: 2, truncated: false, affectedRows: null, durationMs: 41 }

describe('ResultTable', () => {
  it('shows the columns and the rows', () => {
    const wrapper = mount(ResultTable, { props: { result: base } })

    expect(wrapper.findAll('th').map((cell) => cell.text())).toEqual(['country', 'commandes', 'actif', 'note'])
    expect(wrapper.findAll('tbody tr')).toHaveLength(2)
    // L’espace des milliers de fr-FR est insécable : on la normalise pour comparer.
    expect(wrapper.findAll('tbody tr')[0]?.findAll('td').map((cell) => cell.text().replace(/\s/g, ' '))).toEqual(['FR', '1 284', 'true', 'NULL'])
  })

  it('aligns numbers to the right and marks NULL', () => {
    const wrapper = mount(ResultTable, { props: { result: base } })

    const cells = wrapper.findAll('tbody tr')[0]?.findAll('td') ?? []
    expect(cells[1]?.classes()).toContain('num')
    expect(cells[0]?.classes()).not.toContain('num')
    expect(cells[3]?.classes()).toContain('null')
  })

  it('keeps big integers sent as strings readable and left as they are', () => {
    const wrapper = mount(ResultTable, { props: { result: { ...base, columns: ['big'], rows: [['9223372036854775807']], rowCount: 1 } } })

    expect(wrapper.get('tbody td').text()).toBe('9223372036854775807')
  })

  it('gives the full value of a long cell as a tooltip', () => {
    const long = 'x'.repeat(300)
    const wrapper = mount(ResultTable, { props: { result: { ...base, columns: ['c'], rows: [[long]], rowCount: 1 } } })

    expect(wrapper.get('tbody td').attributes('title')).toBe(long)
  })

  it('summarizes the run', () => {
    const wrapper = mount(ResultTable, { props: { result: base } })

    expect(wrapper.get('.summary').text()).toContain('2 lignes')
    expect(wrapper.get('.summary').text()).toContain('41 ms')
  })

  it('says when the result was cut at the row limit', () => {
    const wrapper = mount(ResultTable, { props: { result: { ...base, truncated: true } } })

    expect(wrapper.get('[role="status"]').text()).toContain('limité')
  })

  it('reports the rows touched by a write instead of a table', () => {
    const wrapper = mount(ResultTable, { props: { result: { columns: [], rows: [], rowCount: 0, truncated: false, affectedRows: 3, durationMs: 7 } } })

    expect(wrapper.find('table').exists()).toBe(false)
    expect(wrapper.text()).toContain('3 lignes modifiées')
  })

  it('uses the singular for one row', () => {
    const wrapper = mount(ResultTable, { props: { result: { columns: [], rows: [], rowCount: 0, truncated: false, affectedRows: 1, durationMs: 7 } } })

    expect(wrapper.text()).toContain('1 ligne modifiée')
  })

  it('says so when a query returns nothing', () => {
    const wrapper = mount(ResultTable, { props: { result: { ...base, rows: [], rowCount: 0 } } })

    expect(wrapper.get('.empty').text()).toContain('Aucune ligne')
    expect(wrapper.findAll('th')).toHaveLength(4)
  })
})
