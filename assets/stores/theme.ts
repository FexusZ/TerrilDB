import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

export type ThemeMode = 'system' | 'light' | 'dark'

const KEY = 'terrildb.theme'
const MODES: ThemeMode[] = ['system', 'light', 'dark']

function isMode(value: unknown): value is ThemeMode {
  return MODES.includes(value as ThemeMode)
}

export const useThemeStore = defineStore('theme', () => {
  const mode = ref<ThemeMode>('system')
  const systemLight = ref(false)
  const effective = computed<'light' | 'dark'>(() => (mode.value === 'system' ? (systemLight.value ? 'light' : 'dark') : mode.value))

  function apply(): void {
    document.documentElement.dataset.theme = effective.value
  }

  /** À appeler une fois au démarrage : lit le choix mémorisé et suit les préférences du système. */
  function init(): void {
    try {
      const stored = localStorage.getItem(KEY)
      if (isMode(stored)) {
        mode.value = stored
      }
    } catch {
      // Stockage refusé (navigation privée, données bloquées) : on suit le système.
    }
    const query = window.matchMedia('(prefers-color-scheme: light)')
    systemLight.value = query.matches
    query.addEventListener('change', () => {
      systemLight.value = query.matches
      apply()
    })
    apply()
  }

  function set(next: ThemeMode): void {
    mode.value = next
    try {
      localStorage.setItem(KEY, next)
    } catch {
      // Le choix vaut pour cette page seulement.
    }
    apply()
  }

  function cycle(): void {
    set(MODES[(MODES.indexOf(mode.value) + 1) % MODES.length] ?? 'system')
  }

  return { mode, effective, init, set, cycle }
})
