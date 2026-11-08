import { describe, expect, it } from 'vitest'
import type { QueryResult, TableDetail } from '../api/types'
import { buildPayload, editability, emptyDraft, pendingCount, setCell } from './dataDraft'

function table(partial: Partial<TableDetail> = {}): TableDetail {
  return {
    name: 'orders', fingerprint: 'f', kind: 'table', estimatedRows: 3, viewDefinition: null,
    columns: [
      { name: 'id', type: 'integer', nullable: false, default: null, autoIncrement: true, comment: null },
      { name: 'status', type: 'text', nullable: false, default: null, autoIncrement: false, comment: null },
      { name: 'note', type: 'text', nullable: true, default: null, autoIncrement: false, comment: null },
    ],
    indexes: [{ name: 'orders_pkey', columns: ['id'], unique: true, primary: true }],
    foreignKeys: [],
    ...partial,
  }
}

const result = (columns: string[], rows: QueryResult['rows']): QueryResult => ({ columns, rows, rowCount: rows.length, truncated: false, affectedRows: null, durationMs: 1 })

describe('editability', () => {
  it('allows editing a table read whole, with its primary key', () => {
    expect(editability(table(), result(['id', 'status', 'note'], [])).editable).toBe(true)
  })

  it('allows a partial read as long as the primary key is in it', () => {
    expect(editability(table(), result(['id', 'status'], [])).editable).toBe(true)
  })

  it('refuses when the primary key is not selected', () => {
    const verdict = editability(table(), result(['status'], []))

    expect(verdict.editable).toBe(false)
    expect(verdict.reason).toContain('clé primaire')
  })

  it('needs every column of a table without key', () => {
    const bare = table({ indexes: [] })

    expect(editability(bare, result(['id', 'status', 'note'], [])).editable).toBe(true)
    expect(editability(bare, result(['id', 'status'], [])).editable).toBe(false)
  })

  it('refuses a read that computes columns, a truncated one, a view and a write result', () => {
    expect(editability(table(), result(['id', 'total'], [])).editable).toBe(false)
    expect(editability(table(), { ...result(['id', 'status', 'note'], []), truncated: true }).editable).toBe(false)
    expect(editability(table({ kind: 'view' }), result(['id', 'status', 'note'], [])).editable).toBe(false)
    expect(editability(table(), { ...result([], []), affectedRows: 2 }).editable).toBe(false)
  })
})

describe('draft', () => {
  const rows: QueryResult['rows'] = [[1, 'new', null], [2, 'paid', 'ok']]
  const read = result(['id', 'status', 'note'], rows)

  it('counts nothing for an untouched draft', () => {
    expect(pendingCount(emptyDraft())).toBe(0)
  })

  it('records an edit and forgets it when the value is put back', () => {
    const draft = emptyDraft()

    setCell(draft, read, 0, 'status', 'paid')
    expect(pendingCount(draft)).toBe(1)

    setCell(draft, read, 0, 'status', 'new')
    expect(pendingCount(draft)).toBe(0)
  })

  it('compares a typed original with the text that was typed', () => {
    const draft = emptyDraft()

    setCell(draft, read, 0, 'id', '1')

    expect(pendingCount(draft)).toBe(0)
  })

  it('builds the payload: changed columns with the whole original row, deletions and insertions', () => {
    const draft = emptyDraft()
    setCell(draft, read, 0, 'status', 'paid')
    setCell(draft, read, 0, 'note', null)
    setCell(draft, read, 1, 'note', 'changed')
    draft.deleted.add(1)
    draft.inserts.push({ status: 'x', note: null, id: '' })

    expect(buildPayload(read, draft)).toEqual({
      updates: [{ original: { id: 1, status: 'new', note: null }, changes: { status: 'paid' } }],
      inserts: [{ values: { status: 'x', note: null } }],
      deletes: [{ original: { id: 2, status: 'paid', note: 'ok' } }],
    })
    expect(pendingCount(draft)).toBe(3)
  })

  it('does not update a row that is also deleted', () => {
    const draft = emptyDraft()
    setCell(draft, read, 1, 'note', 'x')
    draft.deleted.add(1)

    expect(buildPayload(read, draft).updates).toEqual([])
  })

  it('ignores an inserted row left entirely blank', () => {
    const draft = emptyDraft()
    draft.inserts.push({ id: '', status: '', note: '' })

    expect(buildPayload(read, draft).inserts).toEqual([])
    expect(pendingCount(draft)).toBe(0)
  })
})
