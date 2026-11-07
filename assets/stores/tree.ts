import { defineStore } from 'pinia'
import { ref } from 'vue'
import { ApiError, api } from '../api/client'
import { messageFor } from '../api/errors'
import type { DatabaseInfo, KeyEntry, KeyPage, TableSummary } from '../api/types'
import { useConnectionsStore } from './connections'

/** fermée (repliée, ou jamais ouverte) · ouverte (dépliée et lue) · injoignable (la dernière ouverture a échoué). */
export type NodeStatus = 'closed' | 'open' | 'unreachable'

export interface TablesState {
  items: TableSummary[]
  next: string | null
  total: number | null
  loading: boolean
  error: string | null
}

export interface KeysState {
  items: KeyEntry[]
  next: string | null
  total: number
  loading: boolean
  error: string | null
}

const PAGE = 100
// Au plus ce nombre de bases sondées par connexion (une connexion à la base cible chacune) : au-delà, elles restent visibles.
const PROBE_LIMIT = 25

/**
 * L'arbre permanent « connexion > bases > tables » (ou « connexion > clés » pour Redis). Il vit le temps de la session :
 * ce qui est déplié et ce qui a été lu reste en mémoire quand on replie, pour ne pas relire la base à chaque clic. Une
 * réponse qui arrive après qu'une connexion a été oubliée (suppression, déconnexion) est ignorée.
 *
 * Clés de nœud : `c:<connexion>` pour une connexion, `d:<connexion>:<base>` pour une base (base vide = celle de la connexion),
 * `s:<connexion>:<base>:<schéma>` pour un schéma PostgreSQL (schéma vide = le schéma courant, sans niveau schéma).
 */
export const useTreeStore = defineStore('tree', () => {
  const expanded = ref<string[]>([])
  const statuses = ref<Record<string, NodeStatus>>({})
  const errors = ref<Record<string, string | null>>({})
  const databases = ref<Record<string, DatabaseInfo[] | null>>({})
  const schemas = ref<Record<string, DatabaseInfo[] | null>>({})
  const defaults = ref<Record<string, string | null>>({})
  const flat = ref<Record<string, boolean>>({})
  const tables = ref<Record<string, TablesState>>({})
  const keys = ref<Record<string, KeysState>>({})
  const emptyDatabases = ref<Record<string, true>>({})
  const emptiesShown = ref<Record<string, boolean>>({})

  let epoch = 0
  const generations = new Map<string, number>()
  const snapshot = (id: string): string => `${String(epoch)}:${String(generations.get(id) ?? 0)}`

  const statusOf = (id: string): NodeStatus => statuses.value[id] ?? 'closed'
  const errorOf = (id: string): string | null => errors.value[id] ?? null
  const isExpanded = (key: string): boolean => expanded.value.includes(key)
  const isFlat = (id: string): boolean => flat.value[id] === true
  const databasesOf = (id: string): DatabaseInfo[] | null => databases.value[id] ?? null
  const tablesOf = (id: string, database: string, schema = ''): TablesState | null => tables.value[`${id}:${database}:${schema}`] ?? null
  const schemasOf = (id: string, database: string): DatabaseInfo[] | null => schemas.value[`${id}:${database}`] ?? null
  /** Un niveau schéma n'existe que s'il y a de quoi choisir : un unique schéma « public » est celui qu'on voit déjà. */
  const hasSchemaLevel = (id: string, database: string): boolean => {
    const list = schemasOf(id, database)

    return list !== null && (list.length > 1 || (list.length === 1 && list[0]?.name !== 'public'))
  }
  const keysOf = (id: string): KeysState | null => keys.value[id] ?? null

  const isEmptyDatabase = (id: string, database: string): boolean => emptyDatabases.value[`${id}:${database}`] === true
  const showsEmpties = (id: string): boolean => emptiesShown.value[id] === true
  /** Bases connues pour être vides : masquées par défaut, sauf si on demande à les voir ou si on est dedans. */
  const emptyCount = (id: string): number => (databasesOf(id) ?? []).filter((database) => isEmptyDatabase(id, database.name)).length
  const visibleDatabasesOf = (id: string): DatabaseInfo[] =>
    (databasesOf(id) ?? []).filter((database) => showsEmpties(id) || !isEmptyDatabase(id, database.name) || isExpanded(`d:${id}:${database.name}`))

  function toggleEmpties(id: string): void {
    emptiesShown.value = { ...emptiesShown.value, [id]: !showsEmpties(id) }
  }

  function markEmpty(id: string, database: string): void {
    emptyDatabases.value = { ...emptyDatabases.value, [`${id}:${database}`]: true }
  }

  /**
   * Cherche les bases vides d'une connexion, une à la fois et sans rien écrire d'autre que « vide » : une seule table demandée
   * (ou la liste des schémas sur PostgreSQL, où plusieurs schémas ou un schéma autre que public comptent comme du contenu).
   * Une sonde qui échoue ne dit rien : la base reste visible.
   */
  async function probeDatabases(id: string, list: DatabaseInfo[], stamp: string): Promise<void> {
    const postgres = useConnectionsStore().byId(id)?.engine === 'postgresql'
    for (const database of list.filter((candidate) => !candidate.system).slice(0, PROBE_LIMIT)) {
      if (snapshot(id) !== stamp) {
        return
      }
      if (tablesOf(id, database.name) !== null || schemasOf(id, database.name) !== null) {
        continue
      }
      try {
        const params = new URLSearchParams({ database: database.name })
        if (postgres) {
          const result = await api<{ schemas?: DatabaseInfo[] } | undefined>('GET', `/web/connections/${id}/schemas?${params.toString()}`)
          const found = result?.schemas
          if (!Array.isArray(found) || found.length > 1 || (found.length === 1 && found[0]?.name !== 'public')) {
            continue
          }
        }
        params.set('limit', '1')
        const result = await api<{ tables?: TableSummary[] } | undefined>('GET', `/web/connections/${id}/tables?${params.toString()}`)
        if (snapshot(id) === stamp && Array.isArray(result?.tables) && result.tables.length === 0) {
          markEmpty(id, database.name)
        }
      } catch {
        // Inconnue : on ne cache rien.
      }
    }
  }

  function expand(key: string): void {
    if (!expanded.value.includes(key)) {
      expanded.value = [...expanded.value, key]
    }
  }

  function collapse(key: string): void {
    expanded.value = expanded.value.filter((candidate) => candidate !== key)
  }

  async function toggleConnection(id: string): Promise<void> {
    if (isExpanded(`c:${id}`)) {
      collapse(`c:${id}`)
      statuses.value = { ...statuses.value, [id]: 'closed' }

      return
    }
    // Déjà lue pendant cette session : on redéplie sans relire.
    if (databases.value[id] != null || keys.value[id] != null) {
      expand(`c:${id}`)
      statuses.value = { ...statuses.value, [id]: 'open' }

      return
    }

    const stamp = snapshot(id)
    errors.value = { ...errors.value, [id]: null }
    try {
      if (useConnectionsStore().byId(id)?.engine === 'redis') {
        const page = await fetchKeys(id, null)
        if (snapshot(id) !== stamp) {
          return
        }
        keys.value = { ...keys.value, [id]: { items: page.keys, next: page.nextCursor, total: page.total, loading: false, error: null } }
      } else {
        const { list, fallback } = await fetchDatabases(id)
        if (snapshot(id) !== stamp) {
          return
        }
        databases.value = { ...databases.value, [id]: list }
        defaults.value = { ...defaults.value, [id]: fallback }
        // Une connexion qui ne nomme aucune base n'a rien à montrer d'elle-même : on garde le niveau « base ».
        if (list.length <= 1 && fallback !== null) {
          flat.value = { ...flat.value, [id]: true }
          await openScope(id, '')
        }
      }
    } catch (error) {
      if (snapshot(id) !== stamp) {
        return
      }
      statuses.value = { ...statuses.value, [id]: 'unreachable' }
      errors.value = { ...errors.value, [id]: messageFor(error) }

      return
    }
    if (snapshot(id) !== stamp) {
      return
    }
    expand(`c:${id}`)
    statuses.value = { ...statuses.value, [id]: 'open' }
    const listed = databases.value[id]
    if (listed != null && !isFlat(id) && listed.length > 1) {
      void probeDatabases(id, listed, stamp)
    }
  }

  async function fetchDatabases(id: string): Promise<{ list: DatabaseInfo[]; fallback: string | null }> {
    try {
      const result = await api<{ default?: string | null; databases: DatabaseInfo[] }>('GET', `/web/connections/${id}/databases`)

      return { list: result.databases, fallback: result.default === undefined ? '' : result.default }
    } catch (error) {
      // Un moteur sans liste de bases se parcourt comme une base unique.
      if (error instanceof ApiError && error.code === 'unsupported_operation') {
        return { list: [], fallback: '' }
      }
      throw error
    }
  }

  /**
   * Ouvre une base : sur PostgreSQL on lit d'abord ses schémas, et les tables ne se chargent qu'à l'ouverture d'un schéma
   * (sauf s'il n'y a pas de niveau schéma) ; ailleurs, directement ses tables.
   */
  async function openScope(id: string, database: string): Promise<void> {
    if (useConnectionsStore().byId(id)?.engine === 'postgresql') {
      await loadSchemas(id, database)
      if (hasSchemaLevel(id, database)) {
        return
      }
    }
    await loadTables(id, database, '', false)
  }

  async function loadSchemas(id: string, database: string): Promise<void> {
    const stamp = snapshot(id)
    const params = new URLSearchParams()
    if (database !== '') {
      params.set('database', database)
    }
    try {
      const result = await api<{ schemas?: DatabaseInfo[] }>('GET', `/web/connections/${id}/schemas${params.size > 0 ? `?${params.toString()}` : ''}`)
      if (snapshot(id) === stamp) {
        schemas.value = { ...schemas.value, [`${id}:${database}`]: result.schemas ?? [] }
      }
    } catch (error) {
      if (snapshot(id) === stamp) {
        const key = `${id}:${database}:`
        tables.value = { ...tables.value, [key]: { items: [], next: null, total: null, loading: false, error: messageFor(error) } }
      }
    }
  }

  function fetchKeys(id: string, cursor: string | null): Promise<KeyPage> {
    const params = new URLSearchParams({ pattern: '*', count: String(PAGE) })
    if (cursor !== null) {
      params.set('cursor', cursor)
    }

    return api<KeyPage>('GET', `/web/connections/${id}/keys?${params.toString()}`)
  }

  async function toggleDatabase(id: string, database: string): Promise<void> {
    const key = `d:${id}:${database}`
    if (isExpanded(key)) {
      collapse(key)

      return
    }
    expand(key)
    if (schemasOf(id, database) === null && tablesOf(id, database) === null) {
      await openScope(id, database)
    }
  }

  async function toggleSchema(id: string, database: string, schema: string): Promise<void> {
    const key = `s:${id}:${database}:${schema}`
    if (isExpanded(key)) {
      collapse(key)

      return
    }
    expand(key)
    if (tablesOf(id, database, schema) === null) {
      await loadTables(id, database, schema, false)
    }
  }

  /** Une page de tables d'une base : la première, ou la suivante qui s'ajoute. */
  async function loadTables(id: string, database: string, schema: string, append: boolean): Promise<void> {
    const key = `${id}:${database}:${schema}`
    const current = tables.value[key]
    if (append && (current === undefined || current.next === null || current.loading)) {
      return
    }
    const stamp = snapshot(id)
    const base: TablesState = current ?? { items: [], next: null, total: null, loading: false, error: null }
    tables.value = { ...tables.value, [key]: { ...base, loading: true, error: null } }

    const params = new URLSearchParams({ limit: String(PAGE) })
    if (database !== '') {
      params.set('database', database)
    }
    if (schema !== '') {
      params.set('schema', schema)
    }
    if (append && base.next !== null) {
      params.set('cursor', base.next)
    }
    try {
      const page = await api<{ tables: TableSummary[]; nextCursor?: string | null; total?: number | null }>('GET', `/web/connections/${id}/tables?${params.toString()}`)
      if (snapshot(id) !== stamp) {
        return
      }
      // Une base qu'on vient d'ouvrir et qui ne contient rien (sans niveau schéma) : on s'en souvient pour la masquer ensuite.
      if (!append && schema === '' && database !== '' && !hasSchemaLevel(id, database)) {
        if (page.tables.length === 0) {
          markEmpty(id, database)
        } else if (isEmptyDatabase(id, database)) {
          emptyDatabases.value = drop(emptyDatabases.value, (candidate) => candidate === `${id}:${database}`)
        }
      }
      tables.value = {
        ...tables.value,
        [key]: {
          items: append ? [...base.items, ...page.tables] : page.tables,
          next: page.nextCursor ?? null,
          total: append ? base.total : (page.total ?? null),
          loading: false,
          error: null,
        },
      }
    } catch (error) {
      if (snapshot(id) !== stamp) {
        return
      }
      tables.value = { ...tables.value, [key]: { ...(append ? base : { items: [], next: null, total: null, loading: false, error: null }), loading: false, error: messageFor(error) } }
    }
  }

  const loadMoreTables = (id: string, database: string, schema = ''): Promise<void> => loadTables(id, database, schema, true)

  /** Après une modification de structure (table créée, renommée, supprimée) : relit la première page de chaque base déjà lue. */
  async function refreshTables(id: string): Promise<void> {
    for (const key of Object.keys(tables.value).filter((candidate) => candidate.startsWith(`${id}:`))) {
      const rest = key.slice(id.length + 1)
      const cut = rest.lastIndexOf(':')
      await loadTables(id, rest.slice(0, cut), rest.slice(cut + 1), false)
    }
  }

  async function loadMoreKeys(id: string): Promise<void> {
    const current = keys.value[id]
    if (current === undefined || current.next === null || current.loading) {
      return
    }
    const stamp = snapshot(id)
    keys.value = { ...keys.value, [id]: { ...current, loading: true, error: null } }
    try {
      const page = await fetchKeys(id, current.next)
      if (snapshot(id) !== stamp) {
        return
      }
      keys.value = { ...keys.value, [id]: { items: [...current.items, ...page.keys], next: page.nextCursor, total: page.total, loading: false, error: null } }
    } catch (error) {
      if (snapshot(id) === stamp) {
        keys.value = { ...keys.value, [id]: { ...current, loading: false, error: messageFor(error) } }
      }
    }
  }

  function drop<T>(record: Record<string, T>, matches: (key: string) => boolean): Record<string, T> {
    return Object.fromEntries(Object.entries(record).filter(([key]) => !matches(key)))
  }

  /** Oublie tout d'une connexion (supprimée) : nœuds repliés, données et réponses en vol écartées. */
  function forget(id: string): void {
    generations.set(id, (generations.get(id) ?? 0) + 1)
    expanded.value = expanded.value.filter((key) => key !== `c:${id}` && !key.startsWith(`d:${id}:`) && !key.startsWith(`s:${id}:`))
    statuses.value = drop(statuses.value, (key) => key === id)
    errors.value = drop(errors.value, (key) => key === id)
    databases.value = drop(databases.value, (key) => key === id)
    schemas.value = drop(schemas.value, (key) => key.startsWith(`${id}:`))
    defaults.value = drop(defaults.value, (key) => key === id)
    flat.value = drop(flat.value, (key) => key === id)
    tables.value = drop(tables.value, (key) => key.startsWith(`${id}:`))
    keys.value = drop(keys.value, (key) => key === id)
    emptyDatabases.value = drop(emptyDatabases.value, (key) => key.startsWith(`${id}:`))
    emptiesShown.value = drop(emptiesShown.value, (key) => key === id)
  }

  /** À la déconnexion : plus rien ne doit survivre à la session. */
  function reset(): void {
    epoch++
    generations.clear()
    expanded.value = []
    statuses.value = {}
    errors.value = {}
    databases.value = {}
    schemas.value = {}
    defaults.value = {}
    flat.value = {}
    tables.value = {}
    keys.value = {}
    emptyDatabases.value = {}
    emptiesShown.value = {}
  }

  return { statusOf, errorOf, isExpanded, isFlat, databasesOf, visibleDatabasesOf, emptyCount, showsEmpties, toggleEmpties, schemasOf, hasSchemaLevel, tablesOf, keysOf, toggleConnection, toggleDatabase, toggleSchema, loadMoreTables, refreshTables, loadMoreKeys, forget, reset }
})
