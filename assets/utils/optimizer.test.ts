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

  it('words a big full scan, with its condition when there is one', () => {
    const base: PlanFindingInfo = { rule: 'full_scan', relation: 'orders', rows: 11420, condition: '(status = 1)' }

    expect(describePlanFinding(base)).toMatch(/Parcours complet de « orders » \(~\s?11\s?420 lignes\)/)
    expect(describePlanFinding(base)).toContain('(status = 1)')
    expect(describePlanFinding({ ...base, condition: null })).not.toContain('condition')
  })

  it('words the sort and wildcard findings with the right caution', () => {
    const sort = describePlanFinding({ rule: 'large_sort', relation: 'order_items', rows: 7000, condition: null })
    expect(sort).toMatch(/Tri de « order_items » \(~\s?7\s?000 lignes\)/)
    expect(sort).toContain('LIMIT')
    expect(sort).toContain('index')

    const like = describePlanFinding({ rule: 'leading_wildcard', relation: 'customers', rows: 1000, condition: "(email like '%a%')" })
    expect(like).toMatch(/joker/)
    expect(like).toContain("(email like '%a%')")
    expect(like).not.toContain('Un index sur les colonnes filtrées peut l’éviter')
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
