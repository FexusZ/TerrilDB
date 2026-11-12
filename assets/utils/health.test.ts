import { describe, expect, it } from 'vitest'
import type { HealthIndicator } from '../api/health'
import { describeIndicator, formatIndicator } from './health'

const base: HealthIndicator = { key: 'queries_per_second', value: 12.5, unit: 'per_second', level: 'ok', threshold: null }

describe('health utils', () => {
  it('formats a value with its unit', () => {
    expect(formatIndicator(base)).toBe('12,5 /s')
    expect(formatIndicator({ ...base, unit: 'percent', value: 99.2 })).toBe('99,2 %')
    expect(formatIndicator({ ...base, unit: 'count', value: 3 })).toBe('3')
  })

  it('says unknown, never zero, when there is no value', () => {
    expect(formatIndicator({ ...base, value: null, level: 'unknown' })).toBe('inconnu')
  })

  it('has a label and a how-to-read text for every known key, and a safe fallback for others', () => {
    for (const key of ['queries_per_second', 'connections_used_percent', 'threads_running', 'aborted_connects_percent', 'buffer_pool_hit_percent', 'tmp_disk_tables_percent', 'row_lock_waits_per_second', 'slow_queries_per_second', 'redo_log_waits', 'transactions_per_second', 'rollback_percent', 'cache_hit_percent', 'temp_files_per_second', 'deadlocks', 'rows_returned_per_second']) {
      const described = describeIndicator(key)
      expect(described.label, key).not.toBe(key)
      expect(described.hint, key).not.toBe('')
    }
    expect(describeIndicator('mystery').label).toBe('mystery')
  })
})
