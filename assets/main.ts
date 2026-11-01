import '@fontsource/archivo/400.css'
import '@fontsource/archivo/500.css'
import '@fontsource/archivo/600.css'
import '@fontsource/archivo/700.css'
import '@fontsource/jetbrains-mono/400.css'
import '@fontsource/jetbrains-mono/500.css'
import { createPinia } from 'pinia'
import { createApp } from 'vue'
import { onSessionLost } from './api/client'
import App from './App.vue'
import { router } from './router'
import { useConnectionsStore } from './stores/connections'
import { useSessionStore } from './stores/session'
import { useTokensStore } from './stores/tokens'
import { useWorkspaceStore } from './stores/workspace'
import './styles/tokens.css'
import './styles/base.css'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
app.use(router)

// Une réponse qui dit « session perdue ou verrouillée » ramène à l'écran de connexion, sans rien garder de la session.
onSessionLost(() => {
  useSessionStore(pinia).dropUser()
  useConnectionsStore(pinia).reset()
  useTokensStore(pinia).reset()
  useWorkspaceStore(pinia).close()
  void router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
})

app.mount('#app')
