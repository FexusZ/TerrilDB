import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '../api/client'
import { messageFor } from '../api/errors'
import type { AuditEntry } from '../api/types'

const PAGE_SIZE = 50

export const useActivityStore = defineStore('activity', () => {
  const items = ref<AuditEntry[]>([])
  const next = ref<string | null>(null)
  const category = ref('')
  const loading = ref(false)
  const error = ref<string | null>(null)

  const hasMore = computed(() => next.value !== null)

  function address(before?: string): string {
    const params = new URLSearchParams({ limit: String(PAGE_SIZE) })
    if (category.value !== '') {
      params.set('category', category.value)
    }
    if (before !== undefined) {
      params.set('before', before)
    }

    return `/web/activity?${params.toString()}`
  }

  async function fetchPage(before?: string): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const page = await api<{ events: AuditEntry[]; next: string | null }>('GET', address(before))
      items.value = before === undefined ? page.events : [...items.value, ...page.events]
      next.value = page.next
    } catch (failure) {
      error.value = messageFor(failure)
    } finally {
      loading.value = false
    }
  }

  /** Repart de la première page, éventuellement pour une autre catégorie. */
  async function load(filter = ''): Promise<void> {
    category.value = filter
    items.value = []
    next.value = null
    await fetchPage()
  }

  async function more(): Promise<void> {
    if (next.value === null || loading.value) {
      return
    }
    await fetchPage(next.value)
  }

  function reset(): void {
    items.value = []
    next.value = null
    category.value = ''
    error.value = null
  }

  return { items, category, loading, error, hasMore, load, more, reset }
})
