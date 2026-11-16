import { describe, expect, it } from 'vitest'
import type { AuditCheck, PlanFindingInfo, SchemaFinding } from '../api/types'
import { describeCheck, describePlanFinding, describeSchemaFinding, planKindLabel } from './optimizer'

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

    const counter = describeSchemaFinding(finding({ rule: 'counter_near_limit', severity: 'critical', table: 'events', columns: ['id'], detail: 'int : 2100000000 sur 2147483647 (97 %)' }))
    expect(counter.title).toBe('Le compteur de « events » sur id approche de la limite de son type (int : 2100000000 sur 2147483647 (97 %)).')
    expect(counter.help).toContain('doublon')
    expect(counter.help).toContain('réécrit')
  })

  it('words a text primary key that looks like an UUID', () => {
    const text = describeSchemaFinding(finding({ rule: 'text_primary_key', table: 'accounts', columns: ['id'], detail: 'char(36)' }))
    expect(text.title).toBe('La clé primaire de « accounts » (id) est un UUID rangé en texte (char(36)).')
    expect(text.help).toContain('BINARY(16)')
    expect(text.help).toContain('À vérifier')
  })

  it('words a money column stored as a float, flagged as a guess', () => {
    const money = describeSchemaFinding(finding({ rule: 'float_amount', severity: 'info', table: 'invoices', columns: ['price'], detail: 'double' }))
    expect(money.title).toBe('La colonne « price » de « invoices » est un nombre à virgule flottante (double) alors que son nom évoque une somme d’argent.')
    expect(money.help).toContain('DECIMAL')
    expect(money.help).toContain('À vérifier')
  })

  it('words an index never read, with the observed duration and the reset caveat', () => {
    const unused = describeSchemaFinding(finding({ rule: 'unused_index', severity: 'info', table: 'orders', columns: ['a'], index: 'idx_a', detail: 'aucune lecture en 12 jours' }))

    expect(unused.title).toBe('L’index « idx_a » de « orders » n’a servi à aucune lecture (aucune lecture en 12 jours).')
    expect(unused.help).toContain('redémarrage')
    expect(unused.help).toContain('invisible')
    expect(unused.help).toContain('écritures')
  })

  it('says why an operations check could not judge', () => {
    const check = (extra: Partial<AuditCheck>): AuditCheck => ({ rule: 'unused_index', state: 'unavailable', reason: null, detail: null, ...extra })

    expect(describeCheck(check({ reason: 'performance_schema_off' }))).toBe('Index jamais lus : non vérifiable, performance_schema est désactivé sur ce serveur.')
    expect(describeCheck(check({ reason: 'permission_denied' }))).toContain('droit')
    expect(describeCheck(check({ reason: 'instrument_off' }))).toContain('instrument')
    expect(describeCheck(check({ reason: 'unreadable' }))).toContain('n’ont pas pu être lus')
    expect(describeCheck(check({ state: 'too_short', detail: '5400' }))).toBe('Index jamais lus : non vérifiable, la collecte des compteurs ne date que de 1 h 30 min (il en faut 7 jours).')
    expect(describeCheck(check({ state: 'too_short', detail: '90000' }))).toContain('1 j 1 h')
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
    const check = (extra: Partial<AuditCheck>): AuditCheck => ({ rule: 'dead_tuples', state: 'unavailable', reason: null, detail: null, ...extra })

    expect(describeCheck(check({ reason: 'permission_denied' }))).toBe('Tuples morts : non vérifiable, le compte n’a pas le droit de lire ces informations.')
    expect(describeCheck(check({ rule: 'orphan_definer', reason: 'unreadable' }))).toBe('Créateurs d’objets : non vérifiable, ces informations n’ont pas pu être lues.')
    expect(describeCheck(check({ rule: 'reclaimable_space' }))).toContain('Place libre')
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
