import { describe, expect, it } from 'vitest'
import type { OperationsCheck, PlanFindingInfo, SchemaFinding } from '../api/types'
import { describeOperationsCheck, describePlanFinding, describeSchemaFinding, planKindLabel } from './optimizer'

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

  it('words stale statistics with what changed, and the cost of recomputing them', () => {
    const wording = describeSchemaFinding(finding({ rule: 'stale_statistics', severity: 'info', table: 'orders', detail: '40000 lignes modifiées depuis la dernière analyse (jamais analysée depuis le début des compteurs)' }))

    expect(wording.title).toBe('Les statistiques de « orders » sont périmées (40000 lignes modifiées depuis la dernière analyse (jamais analysée depuis le début des compteurs)).')
    expect(wording.help).toContain('plans')
    expect(wording.help).toContain('autovacuum')
  })

  it('words dead tuples without promising the file shrinks', () => {
    const wording = describeSchemaFinding(finding({ rule: 'dead_tuples', severity: 'info', table: 'events', detail: '30000 tuples morts pour 50000 vivants (dernier vacuum : 2026-09-01 02:00:00)' }))

    expect(wording.title).toContain('« events » garde beaucoup de lignes mortes')
    expect(wording.help).toContain('VACUUM FULL')
    expect(wording.help).toContain('exclusif')
    expect(wording.help).toContain('estimations')
  })

  it('words reclaimable space with the real cost of the rebuild', () => {
    const wording = describeSchemaFinding(finding({ rule: 'reclaimable_space', severity: 'info', table: 'logs', detail: 'environ 100 Mio libres dans le fichier de la table (83 % de sa taille)' }))

    expect(wording.title).toContain('« logs » a de la place libre à récupérer (environ 100 Mio')
    expect(wording.help).toContain('réécrit')
    expect(wording.help).toContain('espace temporaire')
  })

  it('words an object whose creator account is gone', () => {
    const wording = describeSchemaFinding(finding({ rule: 'orphan_definer', severity: 'warning', table: 'v_totals', index: 'v_totals', detail: 'vue créée par « gone@% », compte inexistant' }))

    expect(wording.title).toBe('« v_totals » : vue créée par « gone@% », compte inexistant.')
    expect(wording.help).toContain('1449')
  })

  it('says why an operations check could not judge', () => {
    const check = (extra: Partial<OperationsCheck>): OperationsCheck => ({ rule: 'dead_tuples', state: 'unavailable', reason: null, detail: null, ...extra })

    expect(describeOperationsCheck(check({ reason: 'permission_denied' }))).toBe('Tuples morts : non vérifiable, le compte n’a pas le droit de lire ces informations.')
    expect(describeOperationsCheck(check({ rule: 'orphan_definer', reason: 'unreadable' }))).toBe('Créateurs d’objets : non vérifiable, ces informations n’ont pas pu être lues.')
    expect(describeOperationsCheck(check({ rule: 'reclaimable_space' }))).toContain('Place libre')
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
