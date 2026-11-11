import { describe, expect, it } from 'vitest'
import type { BlockingNode, DiagnosticStatus, OpenTransaction } from '../api/types'
import { describeStatus, flattenBlocking, formatSeconds, isLongTransaction } from './diagnostics'

const status = (availability: DiagnosticStatus['availability'], extra: Partial<DiagnosticStatus> = {}): DiagnosticStatus => ({ availability, reason: null, privilege: null, ...extra })

const transaction = (extra: Partial<OpenTransaction> = {}): OpenTransaction => ({ session: '1', user: 'app', state: 'RUNNING', seconds: 10, rowsLocked: null, rowsModified: null, sleeping: false, query: null, ...extra })

const node = (id: string, blocked: BlockingNode[] = []): BlockingNode => ({ id, user: null, query: null, seconds: null, kind: null, mode: null, object: null, blocked })

describe('describeStatus', () => {
  it('says nothing when the source is readable', () => {
    expect(describeStatus(status('available'), 'les verrous')).toBeNull()
  })

  it('names the missing privilege', () => {
    expect(describeStatus(status('permission_denied', { reason: 'insufficient_privilege', privilege: 'PROCESS' }), 'les verrous')).toBe('Le compte de la connexion n’a pas le droit de lire les verrous : il lui manque PROCESS.')
  })

  it('explains a switched-off source and says it is not an empty list', () => {
    expect(describeStatus(status('disabled', { reason: 'performance_schema_off' }), 'les verrous')).toContain('performance_schema')
    expect(describeStatus(status('disabled', { reason: 'performance_schema_off' }), 'les verrous')).toContain('ne veut pas dire')
  })

  it('says the engine has no such source', () => {
    expect(describeStatus(status('unsupported', { reason: 'not_supported' }), 'les verrous')).toBe('Ce moteur ne fournit pas les verrous.')
  })

  it('never shows an unknown reason as is', () => {
    expect(describeStatus(status('disabled', { reason: 'something_else' }), 'les verrous')).not.toContain('something_else')
  })
})

describe('formatSeconds', () => {
  it.each([
    [0, '0 s'],
    [59, '59 s'],
    [60, '1 min'],
    [135, '2 min 15 s'],
    [3600, '1 h'],
    [3725, '1 h 2 min'],
    [90000, '1 j 1 h'],
  ])('writes %i seconds as %s', (seconds, text) => {
    expect(formatSeconds(seconds)).toBe(text)
  })
})

describe('isLongTransaction', () => {
  it('flags a transaction at or above the threshold', () => {
    expect(isLongTransaction(transaction({ seconds: 60 }), 60)).toBe(true)
    expect(isLongTransaction(transaction({ seconds: 59 }), 60)).toBe(false)
  })

  it('treats a threshold of zero as flagging nothing wrongly: it flags all', () => {
    expect(isLongTransaction(transaction({ seconds: 0 }), 0)).toBe(true)
  })
})

describe('flattenBlocking', () => {
  it('lists the tree depth first with the depth of each line', () => {
    const lines = flattenBlocking([node('1', [node('2', [node('3')]), node('4')]), node('5')])

    expect(lines.map((line) => [line.node.id, line.depth])).toEqual([['1', 0], ['2', 1], ['3', 2], ['4', 1], ['5', 0]])
  })
})
