import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useThemeStore } from './theme'

type Listener = () => void

function stubSystem(light: boolean) {
  const listeners: Listener[] = []
  const query = {
    matches: light,
    addEventListener: (_: string, listener: Listener) => listeners.push(listener),
    removeEventListener: vi.fn(),
  }
  vi.stubGlobal('matchMedia', () => query)

  return {
    change(nextLight: boolean) {
      query.matches = nextLight
      listeners.forEach((listener) => listener())
    },
  }
}

describe('theme store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    delete document.documentElement.dataset.theme
    vi.unstubAllGlobals()
  })

  it('follows the system by default', () => {
    stubSystem(true)
    const theme = useThemeStore()

    theme.init()

    expect(theme.mode).toBe('system')
    expect(theme.effective).toBe('light')
    expect(document.documentElement.dataset.theme).toBe('light')
  })

  it('falls back to dark when the system gives no preference', () => {
    stubSystem(false)
    const theme = useThemeStore()

    theme.init()

    expect(document.documentElement.dataset.theme).toBe('dark')
  })

  it('applies and remembers an explicit choice', () => {
    stubSystem(true)
    const theme = useThemeStore()
    theme.init()

    theme.set('dark')

    expect(document.documentElement.dataset.theme).toBe('dark')
    expect(localStorage.getItem('terrildb.theme')).toBe('dark')
    setActivePinia(createPinia())
    const next = useThemeStore()
    next.init()
    expect(next.mode).toBe('dark')
  })

  it('ignores a stored value it does not know', () => {
    stubSystem(false)
    localStorage.setItem('terrildb.theme', 'neon')
    const theme = useThemeStore()

    theme.init()

    expect(theme.mode).toBe('system')
  })

  it('follows the system while in system mode, and only then', () => {
    const system = stubSystem(false)
    const theme = useThemeStore()
    theme.init()

    system.change(true)
    expect(document.documentElement.dataset.theme).toBe('light')

    theme.set('dark')
    system.change(true)
    expect(document.documentElement.dataset.theme).toBe('dark')
  })

  it('cycles system, light, dark', () => {
    stubSystem(false)
    const theme = useThemeStore()
    theme.init()

    theme.cycle()
    expect(theme.mode).toBe('light')
    theme.cycle()
    expect(theme.mode).toBe('dark')
    theme.cycle()
    expect(theme.mode).toBe('system')
  })

  it('still works when storage is unavailable', () => {
    stubSystem(false)
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    const theme = useThemeStore()

    expect(() => {
      theme.init()
      theme.set('light')
    }).not.toThrow()
    expect(document.documentElement.dataset.theme).toBe('light')
    vi.restoreAllMocks()
  })
})
