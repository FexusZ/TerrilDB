import type { Engine } from '../api/types'

const MAX_NAME_BYTES = 1024
// Contrôles C0 et C1, DEL, séparateurs de ligne et de paragraphe, marques et isolats bidirectionnels (miroir de SqlText côté serveur).
// Une moitié de paire de substitution : du texte qui n'est pas de l'UTF-8 valide.
const LONE_SURROGATE = /[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/
const UNSAFE_NAME = /[\u0000-\u001F\u007F-\u009F\u2028\u2029\u061C\u200E\u200F\u202A-\u202E\u2066-\u2069]/u

/** Un nom de la cible qu'on accepte d'écrire dans du SQL montré : ni contrôle, ni séparateur de ligne, ni bidirectionnel, ni vide, ni démesuré. */
export function isSafeName(name: string): boolean {
  return name !== '' && !LONE_SURROGATE.test(name) && new TextEncoder().encode(name).length <= MAX_NAME_BYTES && !UNSAFE_NAME.test(name)
}

/** Entoure un identifiant des guillemets du moteur (le caractère de citation, doublé, reste lisible) ; refuse un nom hostile. */
export function quoteIdentifier(engine: Engine, name: string): string {
  if (!isSafeName(name)) {
    throw new Error('Ce nom ne peut pas être écrit dans du SQL.')
  }
  if (engine === 'postgresql') {
    return `"${name.replaceAll('"', '""')}"`
  }
  if (engine === 'clickhouse') {
    return `\`${name.replaceAll('\\', '\\\\').replaceAll('`', '\\`')}\``
  }

  return `\`${name.replaceAll('`', '``')}\``
}

export function previewQuery(engine: Engine, table: string, limit = 100): string {
  return `SELECT *\n  FROM ${quoteIdentifier(engine, table)}\n LIMIT ${String(limit)};`
}

const IDENTIFIER = '(?:"(?:[^"]|"")+"|`(?:[^`]|``)+`|[\\w$]+)'
const CLAUSE = '(?:where|prewhere|final|sample|order|limit|offset|fetch|settings)'
const NOT_A_PLAIN_READ = /\b(?:join|union|intersect|except|group\s+by|having|distinct|with|over|window|count|sum|avg|min|max)\b/i

function unquote(identifier: string): { name: string; quoted: boolean } {
  const first = identifier[0]
  if (first === '"') {
    return { name: identifier.slice(1, -1).replaceAll('""', '"'), quoted: true }
  }
  if (first === '`') {
    return { name: identifier.slice(1, -1).replaceAll('``', '`'), quoted: true }
  }

  return { name: identifier, quoted: false }
}

/**
 * Dit si le SQL lit les lignes de `table` telles quelles (SELECT … FROM table [alias] [WHERE|ORDER BY|LIMIT…]), sans jointure,
 * regroupement, agrégat, DISTINCT, UNION ni sous-requête : seul ce résultat peut être modifié ligne à ligne. Lecture lexicale
 * volontairement stricte : dans le doute, la grille reste en lecture seule (le serveur vérifie de toute façon chaque ligne).
 */
export function isPlainReadOf(sql: string, table: string): boolean {
  const text = sql
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .replace(/--[^\n]*/g, ' ')
    .replace(/'(?:[^']|'')*'/g, "''")
    .replace(/\s+/g, ' ')
    .replace(/;\s*$/, '')
    .trim()
  if (!/^select\s/i.test(text) || NOT_A_PLAIN_READ.test(text) || (text.match(/\bfrom\b/gi) ?? []).length !== 1) {
    return false
  }
  const after = /\bfrom\s+(.*)$/i.exec(text)?.[1]
  const reference = after === undefined ? null : new RegExp(`^(${IDENTIFIER}(?:\\s*\\.\\s*${IDENTIFIER})*)(.*)$`).exec(after)
  if (reference === null || reference[1] === undefined) {
    return false
  }
  const segments = reference[1].match(new RegExp(IDENTIFIER, 'g')) ?? []
  const last = unquote(segments[segments.length - 1] ?? '')
  if (last.quoted ? last.name !== table : last.name.toLowerCase() !== table.toLowerCase()) {
    return false
  }
  let rest = (reference[2] ?? '').trim()
  rest = rest.replace(new RegExp(`^(?:as\\s+)?(?!${CLAUSE}\\b)${IDENTIFIER}`, 'i'), '').trim()

  return rest === '' || new RegExp(`^${CLAUSE}\\b`, 'i').test(rest)
}
