import { describe, expect, it } from 'vitest'
import { CATALOGUE, fieldNames, snippetsFor } from './snippets'

const ALL = { recursive_cte: true, window_functions: true, lateral: true, json_table: true }
const ids = (list: { id: string }[]): string[] => list.map((snippet) => snippet.id)

describe('snippetsFor', () => {
  it('offers every snippet of an engine whose capabilities are all declared', () => {
    expect(ids(snippetsFor('postgresql', ALL))).toEqual(['recursive_ancestors', 'running_total', 'last_rows_per_parent', 'json_table_rows'])
  })

  it('hides a snippet whose capability the server does not declare', () => {
    const mariadb = { ...ALL, lateral: false }

    expect(ids(snippetsFor('mariadb', mariadb))).not.toContain('last_rows_per_parent')
    expect(ids(snippetsFor('mariadb', mariadb))).toContain('running_total')
  })

  it('hides everything while the capabilities are unknown', () => {
    expect(snippetsFor('postgresql', null)).toEqual([])
  })

  it('hides a snippet whose capability the answer does not mention', () => {
    expect(ids(snippetsFor('postgresql', { window_functions: true }))).toEqual(['running_total'])
  })

  it('never offers a snippet written for another engine', () => {
    // Même déclarée, LATERAL n'est pas écrite pour MariaDB ni ClickHouse ; JSON_TABLE n'existe pas dans ClickHouse.
    expect(ids(snippetsFor('clickhouse', ALL))).toEqual(['recursive_ancestors', 'running_total'])
    expect(ids(snippetsFor('mariadb', ALL))).not.toContain('last_rows_per_parent')
  })

  it('offers nothing for Redis', () => {
    expect(snippetsFor('redis', ALL)).toEqual([])
  })
})

describe('the catalogue', () => {
  it('only holds placeholders, never the name of a real table', () => {
    for (const snippet of CATALOGUE) {
      const literal = snippet.body.replace(/\$\{\w+\}/g, '')
      expect(literal, snippet.id).not.toMatch(/\b(categories|customers|products|orders|order_items)\b/)
    }
  })

  it('gives an example value to every placeholder, and only to those', () => {
    for (const snippet of CATALOGUE) {
      expect(Object.keys(snippet.example).sort(), snippet.id).toEqual([...new Set(fieldNames(snippet.body))].sort())
    }
  })
})
