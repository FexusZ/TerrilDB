import { describe, expect, it } from 'vitest'
import { formatDate, formatDateTime, formatDuration, formatEstimate, formatTtl } from './format'

describe('format', () => {
  it('writes dates in French', () => {
    expect(formatDate('2026-10-31T10:00:00+00:00')).toContain('2026')
    expect(formatDate('2026-10-31T10:00:00+00:00')).toMatch(/oct/)
  })

  it('writes estimated row counts with a tilde and thin separators', () => {
    expect(formatEstimate(11420)).toMatch(/^~\s?11\s?420$/)
    expect(formatEstimate(24)).toBe('~ 24')
    expect(formatEstimate(0)).toBe('~ 0')
    expect(formatEstimate(null)).toBe('')
  })

  it('writes durations', () => {
    expect(formatDuration(41)).toBe('41 ms')
    expect(formatDuration(1520)).toBe('1,52 s')
    expect(formatDuration(0)).toBe('0 ms')
  })

  it('writes a date with its time', () => {
    expect(formatDateTime('2026-10-31T10:05:00+00:00')).toMatch(/2026/)
    expect(formatDateTime('2026-10-31T10:05:00+00:00')).toMatch(/\d{2}:\d{2}/)
  })

  it('speaks of a key expiry in the largest sensible unit', () => {
    expect(formatTtl(null)).toBe('sans expiration')
    expect(formatTtl(0)).toBe('moins d’une seconde')
    expect(formatTtl(45)).toBe('45 s')
    expect(formatTtl(90)).toBe('2 min')
    expect(formatTtl(3600)).toBe('1 h')
    expect(formatTtl(2 * 86400 + 5)).toBe('2 j')
  })
})
