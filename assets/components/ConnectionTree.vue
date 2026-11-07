<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { useRouter, useRoute, type RouteLocationRaw } from 'vue-router'
import { messageFor } from '../api/errors'
import { CONNECTION_COLORS, ENGINE_BADGES, ENGINE_LABELS, type ConnectionColor, type ConnectionSummary } from '../api/types'
import { useConnectionsStore } from '../stores/connections'
import { useTreeStore, type NodeStatus } from '../stores/tree'
import { formatEstimate } from '../utils/format'
import ConfirmDialog from './ConfirmDialog.vue'

const emit = defineEmits<{ deleted: [id: string] }>()

type Kind = 'connection' | 'database' | 'schema' | 'table' | 'key' | 'more' | 'message'

interface Row {
  key: string
  kind: Kind
  level: number
  label: string
  parentKey: string | null
  connection?: ConnectionSummary
  database?: string
  schema?: string
  expandable: boolean
  expanded: boolean
  to?: RouteLocationRaw
  hint?: string
  alert?: boolean
  view?: boolean
  posinset: number
  setsize: number
}

const connections = useConnectionsStore()
const tree = useTreeStore()
const router = useRouter()
const route = useRoute()

const STATUS_LABELS: Record<NodeStatus, string> = { closed: 'fermée', open: 'ouverte', unreachable: 'injoignable' }
const number = new Intl.NumberFormat('fr-FR')
const colorLabel = (color: ConnectionColor): string => CONNECTION_COLORS.find((candidate) => candidate.value === color)?.label ?? color

const root = ref<HTMLElement | null>(null)
const focusKey = ref<string | null>(null)
const toDelete = ref<ConnectionSummary | null>(null)
const deleting = ref(false)
const deleteError = ref<string | null>(null)
// Le glisser-déposer garde la connexion saisie ici : le navigateur ne montre pas toujours ce que `dataTransfer` contient pendant le survol.
const dragging = ref<string | null>(null)
const dropTarget = ref<string | null>(null)

function tableRows(out: Row[], connection: ConnectionSummary, database: string, schema: string, level: number, parentKey: string): void {
  const state = tree.tablesOf(connection.id, database, schema)
  if (state === null) {
    return
  }
  for (const table of state.items) {
    out.push({
      key: `t:${connection.id}:${database}:${schema}:${table.name}`,
      kind: 'table',
      level,
      label: table.name,
      parentKey,
      connection,
      database,
      schema,
      expandable: false,
      expanded: false,
      to: { name: 'table', params: { id: connection.id, name: table.name }, ...(database === '' && schema === '' ? {} : { query: { ...(database === '' ? {} : { database }), ...(schema === '' ? {} : { schema }) } }) },
      hint: table.kind === 'table' ? (table.estimatedRows === null ? undefined : formatEstimate(table.estimatedRows)) : 'vue',
      view: table.kind !== 'table',
      posinset: 0,
      setsize: 0,
    })
  }
  if (state.error !== null) {
    out.push({ key: `e:${connection.id}:${database}:${schema}`, kind: 'message', level, label: state.error, parentKey, expandable: false, expanded: false, alert: true, posinset: 0, setsize: 0 })
  } else if (state.loading && state.items.length === 0) {
    out.push({ key: `l:${connection.id}:${database}:${schema}`, kind: 'message', level, label: 'Chargement…', parentKey, expandable: false, expanded: false, posinset: 0, setsize: 0 })
  }
  if (state.next !== null) {
    out.push({ key: `m:${connection.id}:${database}:${schema}`, kind: 'more', level, label: state.loading ? 'Chargement…' : 'Charger les 100 suivantes', parentKey, connection, database, schema, expandable: false, expanded: false, posinset: 0, setsize: 0 })
  }
}

/** Le contenu d'une base : ses schémas (PostgreSQL, s'il y a de quoi choisir) avec leurs tables dessous, sinon ses tables. */
function scopeRows(out: Row[], connection: ConnectionSummary, database: string, level: number, parentKey: string): void {
  if (!tree.hasSchemaLevel(connection.id, database)) {
    tableRows(out, connection, database, '', level, parentKey)

    return
  }
  for (const schema of tree.schemasOf(connection.id, database) ?? []) {
    const schemaKey = `s:${connection.id}:${database}:${schema.name}`
    const schemaOpen = tree.isExpanded(schemaKey)
    const state = tree.tablesOf(connection.id, database, schema.name)
    out.push({
      key: schemaKey,
      kind: 'schema',
      level,
      label: schema.name,
      parentKey,
      connection,
      database,
      schema: schema.name,
      expandable: true,
      expanded: schemaOpen,
      hint: schemaOpen && state?.total != null ? `${number.format(state.items.length)} / ${number.format(state.total)}` : undefined,
      posinset: 0,
      setsize: 0,
    })
    if (schemaOpen) {
      tableRows(out, connection, database, schema.name, level + 1, schemaKey)
    }
  }
}

const rows = computed<Row[]>(() => {
  const out: Row[] = []
  for (const connection of connections.items) {
    const key = `c:${connection.id}`
    const open = tree.isExpanded(key)
    out.push({ key, kind: 'connection', level: 1, label: connection.name, parentKey: null, connection, expandable: true, expanded: open, posinset: 0, setsize: 0 })

    const error = tree.errorOf(connection.id)
    if (!open) {
      if (tree.statusOf(connection.id) === 'unreachable' && error !== null) {
        out.push({ key: `e:${connection.id}`, kind: 'message', level: 2, label: error, parentKey: key, expandable: false, expanded: false, alert: true, posinset: 0, setsize: 0 })
      }
      continue
    }

    if (connection.engine === 'redis') {
      const keys = tree.keysOf(connection.id)
      for (const entry of keys?.items ?? []) {
        out.push({
          key: `k:${connection.id}:${entry.name}`,
          kind: 'key',
          level: 2,
          label: entry.name,
          parentKey: key,
          connection,
          expandable: false,
          expanded: false,
          to: { name: 'key', params: { id: connection.id }, query: { name: entry.name } },
          hint: entry.type,
          posinset: 0,
          setsize: 0,
        })
      }
      if (keys?.error) {
        out.push({ key: `e:${connection.id}`, kind: 'message', level: 2, label: keys.error, parentKey: key, expandable: false, expanded: false, alert: true, posinset: 0, setsize: 0 })
      }
      if (keys?.next != null) {
        out.push({ key: `m:${connection.id}`, kind: 'more', level: 2, label: keys.loading ? 'Chargement…' : 'Charger la suite', parentKey: key, connection, expandable: false, expanded: false, posinset: 0, setsize: 0 })
      }
    } else if (tree.isFlat(connection.id)) {
      scopeRows(out, connection, '', 2, key)
    } else {
      for (const database of tree.databasesOf(connection.id) ?? []) {
        const databaseKey = `d:${connection.id}:${database.name}`
        const databaseOpen = tree.isExpanded(databaseKey)
        const state = tree.tablesOf(connection.id, database.name, '')
        out.push({
          key: databaseKey,
          kind: 'database',
          level: 2,
          label: database.name,
          parentKey: key,
          connection,
          database: database.name,
          expandable: true,
          expanded: databaseOpen,
          hint: databaseOpen && state?.total != null ? `${number.format(state.items.length)} / ${number.format(state.total)}` : undefined,
          posinset: 0,
          setsize: 0,
        })
        if (databaseOpen) {
          scopeRows(out, connection, database.name, 3, databaseKey)
        }
      }
    }
  }

  // aria-posinset / aria-setsize : la position parmi les frères (les messages n'en sont pas).
  const counts = new Map<string, number>()
  for (const row of out) {
    if (row.kind !== 'message') {
      counts.set(row.parentKey ?? '', (counts.get(row.parentKey ?? '') ?? 0) + 1)
    }
  }
  const seen = new Map<string, number>()
  for (const row of out) {
    if (row.kind !== 'message') {
      const parent = row.parentKey ?? ''
      const position = (seen.get(parent) ?? 0) + 1
      seen.set(parent, position)
      row.posinset = position
      row.setsize = counts.get(parent) ?? 0
    }
  }

  return out
})

const focusable = computed(() => rows.value.filter((row) => row.kind !== 'message'))
const tabStop = computed(() => (focusKey.value !== null && focusable.value.some((row) => row.key === focusKey.value) ? focusKey.value : (focusable.value[0]?.key ?? null)))

function isCurrent(row: Row): boolean {
  if (row.connection === undefined || route.params.id !== row.connection.id) {
    return false
  }
  if (row.kind === 'table') {
    return (
      route.name === 'table' &&
      route.params.name === row.label &&
      (typeof route.query.database === 'string' ? route.query.database : '') === row.database &&
      (typeof route.query.schema === 'string' ? route.query.schema : '') === row.schema
    )
  }

  return row.kind === 'key' && route.name === 'key' && route.query.name === row.label
}

function dragStart(event: DragEvent, row: Row): void {
  if (row.kind !== 'connection' || row.connection === undefined) {
    return
  }
  dragging.value = row.connection.id
  if (event.dataTransfer !== null) {
    event.dataTransfer.effectAllowed = 'move'
    event.dataTransfer.setData('text/plain', row.connection.id)
  }
}

function dragOver(event: DragEvent, row: Row): void {
  if (dragging.value === null || row.kind !== 'connection' || row.connection === undefined) {
    return
  }
  event.preventDefault()
  dropTarget.value = row.connection.id
}

async function drop(row: Row): Promise<void> {
  const moved = dragging.value
  dragging.value = null
  dropTarget.value = null
  if (moved !== null && row.kind === 'connection' && row.connection !== undefined) {
    await connections.moveOnto(moved, row.connection.id)
  }
}

function dragEnd(): void {
  dragging.value = null
  dropTarget.value = null
}

async function focus(key: string): Promise<void> {
  focusKey.value = key
  await nextTick()
  const element = Array.from(root.value?.querySelectorAll<HTMLElement>('[data-key]') ?? []).find((candidate) => candidate.dataset.key === key)
  element?.focus()
}

async function activate(row: Row): Promise<void> {
  focusKey.value = row.key
  const connection = row.connection
  switch (row.kind) {
    case 'connection':
      if (connection) {
        await tree.toggleConnection(connection.id)
      }
      break
    case 'database':
      if (connection && row.database !== undefined) {
        await tree.toggleDatabase(connection.id, row.database)
      }
      break
    case 'schema':
      if (connection && row.database !== undefined && row.schema !== undefined) {
        await tree.toggleSchema(connection.id, row.database, row.schema)
      }
      break
    case 'table':
    case 'key':
      if (row.to) {
        await router.push(row.to)
      }
      break
    case 'more':
      if (connection) {
        await (connection.engine === 'redis' ? tree.loadMoreKeys(connection.id) : tree.loadMoreTables(connection.id, row.database ?? '', row.schema ?? ''))
      }
      break
    default:
      break
  }
}

async function onKeydown(event: KeyboardEvent, row: Row): Promise<void> {
  if (event.target !== event.currentTarget) {
    return
  }
  const list = focusable.value
  const index = list.findIndex((candidate) => candidate.key === row.key)

  // Alt + flèche haut/bas : décale la connexion dans la liste (l'équivalent clavier du glisser-déposer).
  if (event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown') && row.kind === 'connection' && row.connection) {
    event.preventDefault()
    await connections.move(row.connection.id, event.key === 'ArrowUp' ? -1 : 1)
    await focus(row.key)

    return
  }

  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault()
      await focus(list[Math.min(index + 1, list.length - 1)]?.key ?? row.key)
      break
    case 'ArrowUp':
      event.preventDefault()
      await focus(list[Math.max(index - 1, 0)]?.key ?? row.key)
      break
    case 'Home':
      event.preventDefault()
      await focus(list[0]?.key ?? row.key)
      break
    case 'End':
      event.preventDefault()
      await focus(list[list.length - 1]?.key ?? row.key)
      break
    case 'ArrowRight':
      event.preventDefault()
      if (row.expandable && !row.expanded) {
        await activate(row)
      } else if (row.expandable && list[index + 1]?.parentKey === row.key) {
        await focus(list[index + 1]?.key ?? row.key)
      }
      break
    case 'ArrowLeft':
      event.preventDefault()
      if (row.expandable && row.expanded) {
        await activate(row)
      } else if (row.parentKey !== null) {
        await focus(row.parentKey)
      }
      break
    case 'Enter':
    case ' ':
      event.preventDefault()
      await activate(row)
      break
    case 'n':
      if (row.kind === 'connection' && row.connection) {
        await router.push({ name: 'workspace', params: { id: row.connection.id } })
      }
      break
    case 'e':
      if (row.kind === 'connection' && row.connection) {
        await router.push({ name: 'connection-edit', params: { id: row.connection.id } })
      }
      break
    case 'Delete':
      if (row.kind === 'connection' && row.connection) {
        toDelete.value = row.connection
      }
      break
    default:
      break
  }
}

async function confirmDelete(): Promise<void> {
  const connection = toDelete.value
  if (connection === null) {
    return
  }
  deleting.value = true
  deleteError.value = null
  try {
    await connections.remove(connection.id)
    tree.forget(connection.id)
    emit('deleted', connection.id)
  } catch (failure) {
    deleteError.value = messageFor(failure)
  } finally {
    deleting.value = false
    toDelete.value = null
  }
}
</script>

<template>
  <div ref="root" class="connection-tree">
    <p v-if="deleteError" class="error" role="alert">{{ deleteError }}</p>
    <p v-if="connections.items.length === 0" class="empty">Aucune connexion pour l’instant.</p>

    <ul v-else role="tree" aria-label="Connexions">
      <template v-for="row in rows" :key="row.key">
        <li v-if="row.kind === 'message'" role="none" class="message" :style="{ paddingLeft: `${row.level * 14 + 6}px` }">
          <p :role="row.alert ? 'alert' : undefined">{{ row.label }}</p>
        </li>
        <li
          v-else
          role="treeitem"
          :data-key="row.key"
          :data-kind="row.kind"
          :data-tint="row.connection?.color ?? undefined"
          :aria-level="row.level"
          :aria-posinset="row.posinset"
          :aria-setsize="row.setsize"
          :aria-expanded="row.expandable ? row.expanded : undefined"
          :aria-current="isCurrent(row) ? 'page' : undefined"
          :tabindex="tabStop === row.key ? 0 : -1"
          :class="{ current: isCurrent(row) }"
          @keydown="onKeydown($event, row)"
        >
          <div
            class="row"
            :class="{ dragging: row.kind === 'connection' && row.connection?.id === dragging, over: row.kind === 'connection' && row.connection?.id === dropTarget && dragging !== dropTarget }"
            :draggable="row.kind === 'connection'"
            :style="{ paddingLeft: `${(row.level - 1) * 14 + 6}px` }"
            @click="activate(row)"
            @dragstart="dragStart($event, row)"
            @dragover="dragOver($event, row)"
            @drop.prevent="drop(row)"
            @dragend="dragEnd"
          >
            <template v-if="row.kind === 'connection' && row.connection">
              <span class="caret" aria-hidden="true">{{ row.expanded ? '▾' : '▸' }}</span>
              <span v-if="row.connection.color" class="swatch" :data-color="row.connection.color" role="img" :aria-label="`Couleur : ${colorLabel(row.connection.color)}`" />
              <span class="badge" aria-hidden="true">{{ ENGINE_BADGES[row.connection.engine] }}</span>
              <span class="label">{{ row.label }}</span>
              <span class="sr-only">{{ ENGINE_LABELS[row.connection.engine] }}</span>
              <span class="dot" :class="tree.statusOf(row.connection.id)" role="img" :aria-label="`État : ${STATUS_LABELS[tree.statusOf(row.connection.id)]}`" />
              <span class="actions">
                <button type="button" class="console" tabindex="-1" :aria-label="`Nouvelle console sur ${row.label}`" title="Nouvelle console (n)" @click.stop="router.push({ name: 'workspace', params: { id: row.connection.id } })">▶</button>
                <button type="button" class="edit" tabindex="-1" :aria-label="`Modifier ${row.label}`" title="Modifier (e)" @click.stop="router.push({ name: 'connection-edit', params: { id: row.connection.id } })">✎</button>
                <button type="button" class="delete" tabindex="-1" :aria-label="`Supprimer ${row.label}`" title="Supprimer (Suppr)" @click.stop="toDelete = row.connection ?? null">✕</button>
              </span>
            </template>
            <template v-else-if="row.kind === 'database' || row.kind === 'schema'">
              <span class="caret" aria-hidden="true">{{ row.expanded ? '▾' : '▸' }}</span>
              <span class="label">{{ row.label }}</span>
              <small v-if="row.hint">{{ row.hint }}</small>
            </template>
            <template v-else-if="row.kind === 'more'">
              <span class="label more">{{ row.label }}</span>
            </template>
            <RouterLink v-else-if="row.to" class="node" tabindex="-1" :to="row.to" @click.stop>
              <span class="icon" :class="row.view ? 'view' : row.kind" aria-hidden="true">{{ row.kind === 'key' ? '⌘' : row.view ? '◍' : '▤' }}</span>
              <span class="label">{{ row.label }}</span>
              <small v-if="row.hint">{{ row.kind === 'table' && row.hint !== 'vue' ? `~ ${row.hint}` : row.hint }}</small>
            </RouterLink>
          </div>
        </li>
      </template>
    </ul>

    <ConfirmDialog
      v-if="toDelete"
      :title="`Supprimer « ${toDelete.name} » ?`"
      message="Les paramètres de connexion chiffrés sont effacés. Cette action est définitive."
      confirm-label="Supprimer"
      danger
      :busy="deleting"
      @confirm="confirmDelete"
      @cancel="toDelete = null"
    />
  </div>
</template>

<style scoped>
.connection-tree { font-size: 13px; }
ul { list-style: none; margin: 0; padding: 4px 0; display: flex; flex-direction: column; }
li[role='treeitem'] { outline: none; }
li[role='treeitem']:focus-visible > .row { outline: 2px solid var(--acc); outline-offset: -2px; }
.row { display: flex; align-items: center; gap: 6px; min-height: 28px; padding-right: 8px; border-radius: 5px; color: var(--fg); cursor: pointer; }
.row:hover { background: var(--s2); }
li[data-tint='red'] { --tint: var(--conn-red); }
li[data-tint='orange'] { --tint: var(--conn-orange); }
li[data-tint='yellow'] { --tint: var(--conn-yellow); }
li[data-tint='green'] { --tint: var(--conn-green); }
li[data-tint='teal'] { --tint: var(--conn-teal); }
li[data-tint='blue'] { --tint: var(--conn-blue); }
li[data-tint='violet'] { --tint: var(--conn-violet); }
li[data-tint='pink'] { --tint: var(--conn-pink); }
/* La ligne d'une connexion colorée l'est en entier ; ce qui s'ouvre dessous reprend la même couleur, en plus pâle. */
li[data-tint][data-kind='connection'] > .row { background: color-mix(in srgb, var(--tint) 38%, var(--s1)); }
li[data-tint]:not([data-kind='connection']) > .row { background: color-mix(in srgb, var(--tint) 11%, var(--s1)); }
li[data-tint][data-kind='connection'] > .row:hover { background: color-mix(in srgb, var(--tint) 48%, var(--s1)); }
li[data-tint]:not([data-kind='connection']) > .row:hover { background: color-mix(in srgb, var(--tint) 20%, var(--s1)); }
li.current > .row { background: var(--acc-bg); color: var(--acc); }
.caret { width: 12px; color: var(--mut); font-size: 11px; }
.badge { font: 500 10px var(--font-mono); color: var(--acc); }
.label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.label.more { color: var(--acc); }
.swatch { width: 10px; height: 10px; border-radius: 3px; flex: none; background: var(--mut); }
.swatch[data-color='red'] { background: var(--conn-red); }
.swatch[data-color='orange'] { background: var(--conn-orange); }
.swatch[data-color='yellow'] { background: var(--conn-yellow); }
.swatch[data-color='green'] { background: var(--conn-green); }
.swatch[data-color='teal'] { background: var(--conn-teal); }
.swatch[data-color='blue'] { background: var(--conn-blue); }
.swatch[data-color='violet'] { background: var(--conn-violet); }
.swatch[data-color='pink'] { background: var(--conn-pink); }
.row.dragging { opacity: 0.5; }
.row.over { box-shadow: inset 0 2px 0 var(--acc); }
.dot { width: 8px; height: 8px; border-radius: 50%; border: 1px solid var(--mut); flex: none; }
.dot.open { background: var(--acc); border-color: var(--acc); }
.dot.unreachable { background: var(--bad); border-color: var(--bad); }
.actions { display: none; gap: 2px; }
.row:hover .actions, li:focus-within .actions { display: inline-flex; }
.actions button { width: 22px; height: 22px; border: 0; border-radius: 4px; background: transparent; color: var(--mut); font-size: 11px; cursor: pointer; }
.actions button:hover { background: var(--s3); color: var(--fg); }
.node { display: flex; align-items: center; gap: 6px; flex: 1; min-width: 0; color: inherit; text-decoration: none; }
.icon { font-size: 11px; color: var(--mut); width: 12px; text-align: center; }
.icon.view { color: var(--acc); }
small { font: 400 11px var(--font-mono); color: var(--mut); }
.message { padding-right: 8px; }
.message p { padding: 4px 0; font-size: 12px; color: var(--mut); }
.message p[role='alert'] { color: var(--bad); }
.empty { padding: 12px; color: var(--mut); }
.error { margin: 4px; padding: 8px 10px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); }
</style>
