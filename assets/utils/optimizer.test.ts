import { describe, expect, it } from 'vitest'
import type { PlanFindingInfo, SchemaFinding } from '../api/types'
import { describePlanFinding, describeSchemaFinding, planFindingHelp, planKindLabel } from './optimizer'

const finding = (extra: Partial<SchemaFinding>): SchemaFinding => ({ rule: 'missing_primary_key', severity: 'warning', table: 'log', columns: [], index: null, coveredBy: null, suggestion: null, detail: null, ...extra })

describe('optimizer wording', () => {
  it('words each schema rule', () => {
    expect(describeSchemaFinding(finding({})).title).toBe('La table « log » n’a pas de clé primaire.')
    expect(describeSchemaFinding(finding({})).help).toContain('identifier')

    const fk = describeSchemaFinding(finding({ rule: 'foreign_key_without_index', table: 'orders', columns: ['customer_id', 'shop_id'] }))
    expect(fk.title).toBe('La clé étrangère de « orders » sur customer_id, shop_id n’a pas d’index.')
    expect(fk.help).toContain('cascade')

    const redundant = describeSchemaFinding(finding({ rule: 'redundant_index', severity: 'info', table: 'orders', index: 'idx_a', coveredBy: 'idx_ab' }))
    expect(redundant.title).toBe('L’index « idx_a » de « orders » est inutile : « idx_ab » le couvre déjà.')
    expect(redundant.help).toContain('écritures')

    const mismatch = describeSchemaFinding(finding({ rule: 'foreign_key_type_mismatch', table: 'orders', columns: ['customer_id'], detail: 'integer → numeric' }))
    expect(mismatch.title).toBe('La clé étrangère de « orders » sur customer_id n’a pas le type de la colonne qu’elle référence (integer → numeric).')
    expect(mismatch.help).toContain('convertit')
  })

  it('words a big full scan, with its condition when there is one', () => {
    const base: PlanFindingInfo = { rule: 'full_scan', relation: 'orders', rows: 11420, condition: '(status = 1)' }

    expect(describePlanFinding(base)).toMatch(/Parcours complet de « orders » \(~\s?11\s?420 lignes\)/)
    expect(describePlanFinding(base)).toContain('(status = 1)')
    expect(describePlanFinding({ ...base, condition: null })).not.toContain('condition')
  })

  it('words an estimate far from the real number of rows by interpolation', () => {
    const stale: PlanFindingInfo = { rule: 'stale_estimate', relation: 'orders', rows: 1, condition: null, actualRows: 3000, suggestion: 'ANALYZE "orders";' }

    const text = describePlanFinding(stale)

    expect(text).toContain('« orders »')
    expect(text).toMatch(/estimait ~\s?1 ligne/)
    expect(text).toMatch(/3\s?000 lignes/)
    expect(text).toContain('statistiques')
    expect(planFindingHelp(stale)).toContain('à relire')
    expect(describePlanFinding({ ...stale, rows: 20000, actualRows: 1000 })).toContain('estimait ~')
  })

  it('words a sort that spilled to disk', () => {
    const disk: PlanFindingInfo = { rule: 'sort_on_disk', relation: null, rows: 7000, condition: null, actualRows: 7000 }

    expect(describePlanFinding(disk)).toMatch(/Un tri de 7\s?000 lignes a débordé de la mémoire/)
    expect(planFindingHelp(disk)).toContain('index')
    expect(describePlanFinding({ ...disk, actualRows: null })).toContain('Un tri a débordé')
  })

  it('names the kinds of operation', () => {
    expect(planKindLabel('full_scan', 'Seq Scan')).toBe('Parcours complet')
    expect(planKindLabel('index_full_scan', 'index')).toBe('Parcours d’index complet')
    expect(planKindLabel('index_scan', 'eq_ref')).toBe('Recherche par index')
    expect(planKindLabel('join', 'Hash Join')).toBe('Jointure')
    expect(planKindLabel('sort', 'Sort')).toBe('Tri')
    expect(planKindLabel('aggregate', 'HashAggregate')).toBe('Agrégation')
    expect(planKindLabel('limit', 'Limit')).toBe('Limite')
  })

  it('shows the engine label for what it has no word for', () => {
    expect(planKindLabel('other', 'Memoize')).toBe('Memoize')
  })
})
