import type { DiagramRelation, DiagramTable } from '../api/types'

export const BOX_WIDTH = 220
export const HEADER_HEIGHT = 32
export const ROW_HEIGHT = 22
const GAP_X = 90
const GAP_Y = 48
const PADDING = 24
const LOOP = 40

export interface PlacedTable extends DiagramTable {
  x: number
  y: number
  width: number
  height: number
}

export interface PlacedRelation {
  name: string
  from: string
  to: string
  x1: number
  y1: number
  x2: number
  y2: number
  path: string
}

export interface ErLayout {
  tables: PlacedTable[]
  relations: PlacedRelation[]
  width: number
  height: number
}

/**
 * Pose les tables sur une grille, sans bibliothèque : l'ordre suit les clés étrangères (parcours en profondeur depuis
 * la table la plus reliée) pour que les tables liées se retrouvent côte à côte ; les tables isolées viennent en dernier.
 * Le résultat ne dépend pas de l'ordre d'entrée.
 */
export function layoutDiagram(input: DiagramTable[], relations: DiagramRelation[]): ErLayout {
  const known = new Set(input.map((table) => table.name))
  const drawn = relations.filter((relation) => known.has(relation.table) && known.has(relation.referencedTable))
  const ordered = orderTables(input, drawn)

  const columns = Math.max(1, Math.ceil(Math.sqrt(ordered.length)))
  const placed: PlacedTable[] = []
  let y = PADDING
  let width = PADDING * 2
  for (let start = 0; start < ordered.length; start += columns) {
    const row = ordered.slice(start, start + columns)
    const rowHeight = Math.max(...row.map(boxHeight))
    row.forEach((table, column) => {
      placed.push({ ...table, x: PADDING + column * (BOX_WIDTH + GAP_X), y, width: BOX_WIDTH, height: boxHeight(table) })
    })
    width = Math.max(width, PADDING * 2 + row.length * BOX_WIDTH + (row.length - 1) * GAP_X)
    y += rowHeight + GAP_Y
  }

  const byName = new Map(placed.map((table) => [table.name, table]))
  const edges = drawn.map((relation) => placeRelation(relation, byName))

  return { tables: placed, relations: edges, width, height: placed.length === 0 ? PADDING * 2 : y - GAP_Y + PADDING }
}

function boxHeight(table: DiagramTable): number {
  return HEADER_HEIGHT + table.columns.length * ROW_HEIGHT
}

function orderTables(tables: DiagramTable[], relations: DiagramRelation[]): DiagramTable[] {
  const byName = new Map(tables.map((table) => [table.name, table]))
  const neighbours = new Map<string, Set<string>>([...byName.keys()].map((name) => [name, new Set<string>()]))
  for (const relation of relations) {
    if (relation.table !== relation.referencedTable) {
      neighbours.get(relation.table)?.add(relation.referencedTable)
      neighbours.get(relation.referencedTable)?.add(relation.table)
    }
  }

  const visited = new Set<string>()
  const components: string[][] = []
  const starts = [...byName.keys()].sort((a, b) => neighbours.get(b)!.size - neighbours.get(a)!.size || compare(a, b))
  for (const start of starts) {
    if (visited.has(start)) {
      continue
    }
    const component: string[] = []
    const walk = (name: string): void => {
      visited.add(name)
      component.push(name)
      for (const next of [...neighbours.get(name)!].sort(compare)) {
        if (!visited.has(next)) {
          walk(next)
        }
      }
    }
    walk(start)
    components.push(component)
  }
  components.sort((a, b) => b.length - a.length || compare(a[0] ?? '', b[0] ?? ''))

  return components.flat().map((name) => byName.get(name)!)
}

function placeRelation(relation: DiagramRelation, tables: Map<string, PlacedTable>): PlacedRelation {
  const source = tables.get(relation.table)!
  const target = tables.get(relation.referencedTable)!
  const y1 = rowCenter(source, relation.columns[0])
  const y2 = rowCenter(target, relation.referencedColumns[0])

  let leavesRight: boolean
  let entersRight: boolean
  if (source === target) {
    leavesRight = true
    entersRight = true
  } else if (source.x === target.x) {
    leavesRight = false
    entersRight = false
  } else {
    leavesRight = source.x < target.x
    entersRight = !leavesRight
  }
  const x1 = leavesRight ? source.x + source.width : source.x
  const x2 = entersRight ? target.x + target.width : target.x
  const reach = source === target || source.x === target.x ? LOOP : Math.max(LOOP, Math.abs(x2 - x1) / 2)
  const out = (leavesRight ? 1 : -1) * reach
  const into = (entersRight ? 1 : -1) * reach

  return {
    name: relation.name,
    from: relation.table,
    to: relation.referencedTable,
    x1,
    y1,
    x2,
    y2,
    path: `M ${x1} ${y1} C ${x1 + out} ${y1}, ${x2 + into} ${y2}, ${x2} ${y2}`,
  }
}

function rowCenter(table: PlacedTable, column: string | undefined): number {
  const index = table.columns.findIndex((candidate) => candidate.name === column)

  return index < 0 ? table.y + HEADER_HEIGHT / 2 : table.y + HEADER_HEIGHT + index * ROW_HEIGHT + ROW_HEIGHT / 2
}

function compare(a: string, b: string): number {
  return a < b ? -1 : a > b ? 1 : 0
}
