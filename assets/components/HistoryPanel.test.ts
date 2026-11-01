import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import type { HistoryEntry } from '../api/types'
import HistoryPanel from './HistoryPanel.vue'

const entry = (id: string, extra: Partial<HistoryEntry> = {}): HistoryEntry => ({
  id, occurredAt: '2026-11-01T10:00:00+00:00', actor: 'web', outcome: 'success', sql: 'select * from orders', mode: 'read_only', rowCount: 12, durationMs: 41, reason: null, ...extra,
})

describe('HistoryPanel', () => {
  it('lists the statements with their outcome, the door and the timing', () => {
    const wrapper = mount(HistoryPanel, { props: { entries: [entry('1'), entry('2', { actor: 'mcp', sql: 'select 2' })], hasMore: false, loading: false, error: null } })

    const rows = wrapper.findAll('li.entry')
    expect(rows).toHaveLength(2)
    expect(rows[0]?.get('pre').text()).toBe('select * from orders')
    expect(rows[0]?.text()).toContain('12 lignes')
    expect(rows[0]?.text()).toContain('41 ms')
    expect(rows[0]?.text()).toContain('Interface')
    expect(rows[1]?.text()).toContain('MCP')
  })

  it('hands back the statement the user picks', async () => {
    const wrapper = mount(HistoryPanel, { props: { entries: [entry('1'), entry('2', { sql: 'select 2' })], hasMore: false, loading: false, error: null } })

    await wrapper.findAll('button.pick')[1]?.trigger('click')

    expect(wrapper.emitted('pick')).toEqual([['select 2']])
  })

  it('flags what did not run', () => {
    const wrapper = mount(HistoryPanel, {
      props: { entries: [entry('1', { outcome: 'refused', sql: 'drop table t', reason: 'not_read_only', rowCount: null, durationMs: null }), entry('2', { outcome: 'failed', reason: 'timeout' })], hasMore: false, loading: false, error: null },
    })

    const rows = wrapper.findAll('li.entry')
    expect(rows[0]?.classes()).toContain('refused')
    expect(rows[0]?.text()).toContain('Refusée')
    expect(rows[0]?.text()).toContain('lecture seule')
    expect(rows[1]?.classes()).toContain('failed')
    expect(rows[1]?.text()).toContain('délai')
  })

  it('uses the singular for one row', () => {
    const wrapper = mount(HistoryPanel, { props: { entries: [entry('1', { rowCount: 1 })], hasMore: false, loading: false, error: null } })

    expect(wrapper.text()).toContain('1 ligne')
    expect(wrapper.text()).not.toContain('1 lignes')
  })

  it('says when nothing was run yet', () => {
    const wrapper = mount(HistoryPanel, { props: { entries: [], hasMore: false, loading: false, error: null } })

    expect(wrapper.text()).toContain('Aucune requête')
  })

  it('offers more and asks for them', async () => {
    const wrapper = mount(HistoryPanel, { props: { entries: [entry('1')], hasMore: true, loading: false, error: null } })

    await wrapper.get('button.more').trigger('click')

    expect(wrapper.emitted('more')).toHaveLength(1)
  })

  it('shows a loading failure', () => {
    const wrapper = mount(HistoryPanel, { props: { entries: [], hasMore: false, loading: false, error: 'Impossible de joindre le serveur TerrilDB.' } })

    expect(wrapper.get('[role="alert"]').text()).toContain('serveur')
  })
})
