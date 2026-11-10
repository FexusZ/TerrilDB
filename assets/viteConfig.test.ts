// @vitest-environment node
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

describe('vite.config', () => {
  it('ne met aucune police en data: dans le CSS (la CSP refuse font-src data:)', () => {
    const source = readFileSync(fileURLToPath(new URL('../vite.config.ts', import.meta.url)), 'utf8')
    expect(source).toMatch(/assetsInlineLimit:\s*0\b/)
  })
})
