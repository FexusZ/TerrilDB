import { describe, expect, it } from 'vitest'
import { ApiError } from './client'
import { messageFor } from './errors'

describe('refusals on a partitioned table', () => {
  it('explains why the change is refused, naming the column or key', () => {
    expect(messageFor(new ApiError(422, 'unsupported_partition_change', { reason: 'partition_column', subject: 'created_on' }))).toBe('« created_on » sert au partitionnement de la table : on ne peut ni la retirer, ni la renommer, ni changer son type.')
    expect(messageFor(new ApiError(422, 'unsupported_partition_change', { reason: 'partition_unique_key', subject: 'PRIMARY' }))).toContain('colonnes de partitionnement')
    expect(messageFor(new ApiError(422, 'unsupported_partition_change', { reason: 'partition_foreign_key', subject: 'fk_a' }))).toContain('clé étrangère')
  })

  it('stays generic for an unknown reason', () => {
    expect(messageFor(new ApiError(422, 'unsupported_partition_change', { reason: 'brand_new' }))).toContain('table partitionnée')
  })
})
