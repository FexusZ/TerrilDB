import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

// L'application vit sous /app/ : le serveur PHP rend le même index pour toute adresse /app/…
export default defineConfig({
  root: 'assets',
  base: '/app/',
  plugins: [vue()],
  build: { outDir: '../public/app', emptyOutDir: true },
  server: {
    host: true,
    port: 5173,
    // L'API JSON est servie par le conteneur PHP (docker compose up php).
    proxy: { '/web': 'http://php:8000', '/api': 'http://php:8000' },
  },
  test: { environment: 'jsdom', include: ['**/*.test.ts'] },
})
