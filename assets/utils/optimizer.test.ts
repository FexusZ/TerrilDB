import { describe, expect, it } from 'vitest'
import type { PlanFindingInfo, SchemaFinding } from '../api/types'
import { describePlanFinding, describeSchemaFinding, planKindLabel } from './optimizer'

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

  it('words the partition rules', () => {
    const exhausted = describeSchemaFinding(finding({ rule: 'partition_range_exhausted', table: 'events', columns: ['created_on'], detail: '2025-01-01' }))
    expect(exhausted.title).toBe('La plage de partitions de « events » sur created_on s’arrête au 2025-01-01, sans partition pour la suite.')
    expect(exhausted.help).toContain('refuse')

    const many = describeSchemaFinding(finding({ rule: 'too_many_partitions', severity: 'info', table: 'events', detail: '1500' }))
    expect(many.title).toBe('La table « events » a 1500 partitions.')
    expect(many.help).toContain('1000')
  })

  it('words a big full scan, with its condition when there is one', () => {
    const base: PlanFindingInfo = { rule: 'full_scan', relation: 'orders', rows: 11420, condition: '(status = 1)' }

    expect(describePlanFinding(base)).toMatch(/Parcours complet de « orders » \(~\s?11\s?420 lignes\)/)
    expect(describePlanFinding(base)).toContain('(status = 1)')
    expect(describePlanFinding({ ...base, condition: null })).not.toContain('condition')
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
