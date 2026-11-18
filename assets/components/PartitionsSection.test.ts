import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import type { PartitioningInfo } from '../api/types'
import PartitionsSection from './PartitionsSection.vue'

const partitioning: PartitioningInfo = {
  method: 'range',
  expression: 'created_on',
  subMethod: null,
  subExpression: null,
  estimatedRows: 1200,
  dataBytes: 32768,
  partitions: [
    { name: 'p2024', bound: "LESS THAN ('2025-01-01')", catchAll: false, estimatedRows: 1200, dataBytes: 16384, subpartitions: [] },
    { name: 'pmax', bound: 'LESS THAN (MAXVALUE)', catchAll: true, estimatedRows: null, dataBytes: null, subpartitions: [] },
  ],
}

describe('PartitionsSection', () => {
  it('describes the method, the expression and each partition with its bound, rows and size', () => {
    const wrapper = mount(PartitionsSection, { props: { partitioning } })

    expect(wrapper.find('h2').text()).toBe('Partitions')
    expect(wrapper.text()).toContain('RANGE')
    expect(wrapper.text()).toContain('created_on')
    expect(wrapper.text()).toContain('2 partitions')
    const rows = wrapper.findAll('tbody tr')
    expect(rows).toHaveLength(2)
    expect(rows[0]?.text()).toContain('p2024')
    expect(rows[0]?.text()).toContain("LESS THAN ('2025-01-01')")
    expect(rows[0]?.text()).toContain('~ 1')
    expect(rows[0]?.text()).toContain('16 Ko')
    expect(rows[1]?.text()).toContain('fourre-tout')
    expect(rows[1]?.text()).toContain('—')
  })

  it('lists the subpartitions under their partition and names the sub-method', () => {
    const wrapper = mount(PartitionsSection, {
      props: {
        partitioning: {
          ...partitioning,
          subMethod: 'hash',
          subExpression: 'id',
          partitions: [{ ...partitioning.partitions[0]!, subpartitions: [{ name: 'p2024sp0', estimatedRows: 600, dataBytes: 8192 }, { name: 'p2024sp1', estimatedRows: 600, dataBytes: 8192 }] }],
        },
      },
    })

    expect(wrapper.text()).toContain('sous-partitions HASH')
    expect(wrapper.text()).toContain('p2024sp1')
  })

  it('shows names and bounds as text, never as markup', () => {
    const hostile: PartitioningInfo = { ...partitioning, partitions: [{ name: '<img src=x onerror=alert(1)>', bound: '<b>x</b>', catchAll: false, estimatedRows: null, dataBytes: null, subpartitions: [] }] }
    const wrapper = mount(PartitionsSection, { props: { partitioning: hostile } })

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('tbody b').exists()).toBe(false)
    expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>')
  })

  it('says it is read-only', () => {
    expect(mount(PartitionsSection, { props: { partitioning } }).text()).toContain('lecture seule')
  })
})
