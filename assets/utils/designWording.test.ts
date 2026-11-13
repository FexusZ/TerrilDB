import { describe, expect, it } from 'vitest'
import type { SchemaFinding } from '../api/types'
import { describeSchemaFinding } from './optimizer'

const finding = (extra: Partial<SchemaFinding>): SchemaFinding => ({ rule: 'legacy_character_set', severity: 'warning', table: 'users', columns: [], index: null, coveredBy: null, suggestion: null, detail: null, ...extra })

describe('design rule wording', () => {
  it('words the legacy character set', () => {
    const w = describeSchemaFinding(finding({ columns: ['name', 'bio'] }))

    expect(w.title).toBe('« users » garde du texte en utf8 sur trois octets : name, bio.')
    expect(w.help).toContain('émojis')
    expect(w.help).toContain('utf8mb4')
  })
  it('words an implicit foreign key, naming the table it seems to point to', () => {
    const w = describeSchemaFinding(finding({ rule: 'implicit_foreign_key', severity: 'info', table: 'orders', columns: ['customer_id'], detail: 'customers(id)' }))

    expect(w.title).toBe('« orders » : customer_id semble désigner customers(id), mais aucune clé étrangère ne le dit.')
    expect(w.help).toContain('orphelines')
    expect(w.help).toContain('À vérifier')
  })
  it('words a collation mismatch between joined columns', () => {
    const w = describeSchemaFinding(finding({ rule: 'collation_mismatch', table: 'posts', columns: ['user_id'], detail: 'utf8mb4_general_ci ≠ utf8mb4_bin (users.id)' }))

    expect(w.title).toBe('« posts » : user_id et la colonne qu’elle désigne n’ont pas la même collation (utf8mb4_general_ci ≠ utf8mb4_bin (users.id)).')
    expect(w.help).toContain('1267')
  })
  it('words a twin table and a copy that lost its indexes', () => {
    const twin = describeSchemaFinding(finding({ rule: 'twin_table', severity: 'info', table: 'orders_bak', detail: 'orders' }))
    expect(twin.title).toBe('« orders_bak » a exactement les colonnes de « orders » : reste de sauvegarde ?')
    expect(twin.help).toContain('À vérifier')

    const copy = describeSchemaFinding(finding({ rule: 'copy_without_indexes', severity: 'info', table: 'orders_bak', detail: 'orders' }))
    expect(copy.title).toBe('« orders_bak » n’a aucun index, alors que « orders » en a.')
    expect(copy.help).toContain('CREATE TABLE')
  })
  it('words a timestamp that holds a deadline', () => {
    const w = describeSchemaFinding(finding({ rule: 'timestamp_deadline', severity: 'info', table: 'licences', columns: ['expires_at'], detail: 'timestamp' }))

    expect(w.title).toBe('« licences » : expires_at est un TIMESTAMP, et son nom dit une échéance.')
    expect(w.help).toContain('2038')
    expect(w.help).toContain('À vérifier')
  })
})
