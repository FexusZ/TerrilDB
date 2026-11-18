import { describe, expect, it } from 'vitest'
import type { StructureStatement } from '../api/types'
import { algorithmDetail, algorithmPill, copyWarning } from './alterAlgorithm'

const statement = (over: Partial<StructureStatement> = {}): StructureStatement => ({ sql: 'ALTER TABLE t ADD COLUMN x int', operation: 'add_column', subject: 'x', destructive: false, ...over })

describe('algorithmPill', () => {
  it('says nothing when the server gave no advice or has nothing to say', () => {
    expect(algorithmPill(statement())).toBeNull()
    expect(algorithmPill(statement({ algorithm: 'unknown', algorithmReason: 'unsupported_engine' }))).toBeNull()
    expect(algorithmPill(statement({ algorithm: 'unknown', algorithmReason: 'not_an_alter' }))).toBeNull()
  })

  it('names each algorithm with a tone', () => {
    expect(algorithmPill(statement({ algorithm: 'instant' }))).toEqual({ label: 'instantané', tone: 'ok' })
    expect(algorithmPill(statement({ algorithm: 'inplace' }))).toEqual({ label: 'en place', tone: 'ok' })
    expect(algorithmPill(statement({ algorithm: 'copy' }))).toEqual({ label: 'recopie', tone: 'warn' })
    expect(algorithmPill(statement({ algorithm: 'rewrite' }))).toEqual({ label: 'réécriture', tone: 'warn' })
    expect(algorithmPill(statement({ algorithm: 'unknown', algorithmReason: 'unverified_version' }))).toEqual({ label: 'algorithme inconnu', tone: 'unknown' })
  })

  it('never shows an instant pill without being told so', () => {
    expect(algorithmPill(statement({ algorithm: undefined }))).toBeNull()
  })
})

describe('algorithmDetail', () => {
  it('explains an instant change as metadata only', () => {
    expect(algorithmDetail(statement({ algorithm: 'instant', blocksWrites: false, copiesTable: false }))).toContain('métadonnées')
  })

  it('says whether writes stay possible during an in-place change', () => {
    expect(algorithmDetail(statement({ algorithm: 'inplace', blocksWrites: false }))).toContain('écritures restent possibles')
    expect(algorithmDetail(statement({ algorithm: 'inplace', blocksWrites: true }))).toContain('bloque les écritures')
  })

  it('announces the copy, the lock when it is known, and the disk space', () => {
    const blocked = algorithmDetail(statement({ algorithm: 'copy', blocksWrites: true, copiesTable: true, estimatedExtraBytes: 50 * 1024 * 1024 }))
    expect(blocked).toContain('recopie toute la table')
    expect(blocked).toContain('bloque les écritures')
    expect(blocked).toContain('50')
    const unknownLock = algorithmDetail(statement({ algorithm: 'copy', blocksWrites: null, copiesTable: true, estimatedExtraBytes: null }))
    expect(unknownLock).toContain('non vérifié')
    expect(unknownLock).not.toContain('bloque les écritures pendant')
  })

  it('does not rassure about an unknown algorithm and says why', () => {
    expect(algorithmDetail(statement({ algorithm: 'unknown', algorithmReason: 'unverified_version' }))).toContain('version du serveur')
    expect(algorithmDetail(statement({ algorithm: 'unknown', algorithmReason: 'not_innodb' }))).toContain('InnoDB')
    expect(algorithmDetail(statement({ algorithm: 'unknown', algorithmReason: 'depends_on_sql_mode' }))).toContain('sql_mode')
    expect(algorithmDetail(statement({ algorithm: 'unknown', algorithmReason: 'unverified_change' }))).toContain('pas couvert')
    expect(algorithmDetail(statement({ algorithm: 'unknown', algorithmReason: 'unverified_change' }))).not.toMatch(/instantané|rapide/)
  })
})

describe('copyWarning', () => {
  const copy = statement({ algorithm: 'copy', blocksWrites: true, copiesTable: true, estimatedExtraBytes: 1024 })
  const big = { level: 'none' as const, estimatedRows: 150_000, warnAt: 0, confirmAt: 0 }

  it('stays quiet for a copy of a small table', () => {
    expect(copyWarning([copy], { ...big, estimatedRows: 99_999 }, 'mysql')).toBeNull()
  })

  it('warns for a copy above 100 000 estimated rows', () => {
    const text = copyWarning([copy], big, 'mysql')
    expect(text).toContain('150')
    expect(text).toContain('recopie')
  })

  it('warns for a copy above 100 MB even when the rows are few', () => {
    const heavy = statement({ algorithm: 'rewrite', blocksWrites: true, copiesTable: true, estimatedExtraBytes: 100 * 1024 * 1024 })
    expect(copyWarning([heavy], { ...big, estimatedRows: 10 }, 'postgresql')).not.toBeNull()
  })

  it('reuses the installation policy: a table the policy calls big warns too', () => {
    expect(copyWarning([copy], { level: 'warning', estimatedRows: 10, warnAt: 5, confirmAt: 0 }, 'mysql')).not.toBeNull()
  })

  it('stays quiet when nothing is copied, however big the table', () => {
    expect(copyWarning([statement({ algorithm: 'instant' }), statement({ algorithm: 'inplace' }), statement({ algorithm: 'unknown', algorithmReason: 'unverified_change' })], big, 'mysql')).toBeNull()
  })

  it('names the external online schema change tools of the engine, never launching any', () => {
    expect(copyWarning([copy], big, 'mysql')).toContain('gh-ost')
    expect(copyWarning([copy], big, 'mysql')).toContain('pt-online-schema-change')
    expect(copyWarning([copy], big, 'mariadb')).toContain('pt-online-schema-change')
    expect(copyWarning([copy], big, 'mariadb')).not.toContain('gh-ost')
    expect(copyWarning([copy], big, 'postgresql')).toContain('par lots')
    expect(copyWarning([copy], big, 'mysql')).toContain('TerrilDB n’en lance aucun')
  })
})
