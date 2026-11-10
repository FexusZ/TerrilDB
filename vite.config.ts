import vue from '@vitejs/plugin-vue'
import { renameSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import type { Plugin } from 'vite'
import { defineConfig } from 'vitest/config'

const outDir = fileURLToPath(new URL('./public/app', import.meta.url))

// Le serveur intégré de PHP sert tel quel un `index.html` trouvé en remontant les dossiers, sans passer par
// Symfony (donc sans routage ni en-têtes de sécurité). L'index compilé s'appelle donc spa.html : SpaController le sert.
const spaEntry = (): Plugin => ({
  name: 'terrildb-spa-entry',
  apply: 'build',
  closeBundle() {
    renameSync(`${outDir}/index.html`, `${outDir}/spa.html`)
  },
})

// L'application vit sous /app/ : le serveur PHP rend le même index pour toute adresse /app/…
export default defineConfig({
  root: 'assets',
  base: '/app/',
  plugins: [vue(), spaEntry()],
  // assetsInlineLimit 0 : les polices sortent en fichiers (la CSP n'admet pas font-src data:).
  build: { outDir: '../public/app', emptyOutDir: true, assetsInlineLimit: 0 },
  server: {
    host: true,
    port: 5173,
    // L'API JSON est servie par le conteneur PHP (docker compose up php).
    proxy: { '/web': 'http://php:8000', '/api': 'http://php:8000' },
  },
  test: { environment: 'jsdom', include: ['**/*.test.ts'] },
})
