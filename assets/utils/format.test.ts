import { describe, expect, it } from 'vitest'
import { formatDate, formatEstimate, formatDuration } from './format'

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
})
