import type { Engine } from '../api/types'

/** Entoure un identifiant des guillemets du moteur (le caractère de citation, doublé, reste lisible). */
export function quoteIdentifier(engine: Engine, name: string): string {
  return engine === 'postgresql' ? `"${name.replaceAll('"', '""')}"` : `\`${name.replaceAll('`', '``')}\``
}

export function previewQuery(engine: Engine, table: string, limit = 100): string {
  return `SELECT *\n  FROM ${quoteIdentifier(engine, table)}\n LIMIT ${String(limit)};`
}
