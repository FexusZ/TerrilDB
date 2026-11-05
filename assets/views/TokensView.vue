<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { messageFor } from '../api/errors'
import type { ApiTokenInfo } from '../api/types'
import AppButton from '../components/AppButton.vue'
import AppHeader from '../components/AppHeader.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import FormField from '../components/FormField.vue'
import { useConnectionsStore } from '../stores/connections'
import { useTokensStore } from '../stores/tokens'
import { formatDate } from '../utils/format'

const tokens = useTokensStore()
const connections = useConnectionsStore()

const name = ref('')
const chosen = ref<string[]>([])
const everything = ref(false)
const writeData = ref(false)
const writeSchema = ref(false)
const createConnections = ref(false)
const lifetime = ref('30')
const error = ref<string | null>(null)
const listError = ref<string | null>(null)
const busy = ref(false)
const fresh = ref<string | null>(null)
const toRevoke = ref<ApiTokenInfo | null>(null)
const copyFailed = ref(false)
const refreshing = ref<string | null>(null)

const LIFETIMES = [
  { value: '7', label: '7 jours' },
  { value: '30', label: '30 jours' },
  { value: '90', label: '90 jours' },
  { value: '365', label: '1 an' },
  { value: 'never', label: 'Jamais (jusqu’à révocation)' },
]

const nameOf = computed(() => new Map(connections.items.map((connection) => [connection.id, connection.name])))
const connectionsOf = (token: ApiTokenInfo): string => token.connectionIds.map((id) => nameOf.value.get(id) ?? 'connexion supprimée').join(', ')
const expired = (token: ApiTokenInfo): boolean => token.expiresAt !== null && new Date(token.expiresAt) <= new Date()

onMounted(async () => {
  try {
    await Promise.all([connections.loaded ? Promise.resolve() : connections.load(), tokens.load()])
  } catch (failure) {
    listError.value = messageFor(failure)
  }
})

async function create(): Promise<void> {
  error.value = null
  if (!everything.value && !createConnections.value && chosen.value.length === 0) {
    error.value = 'Choisissez au moins une connexion.'
    return
  }
  busy.value = true
  try {
    const validity = lifetime.value === 'never' ? { neverExpires: true } : { lifetimeDays: Number(lifetime.value) }
    const rights = {
      ...(writeData.value ? { writeData: true } : {}),
      ...(writeSchema.value ? { writeSchema: true } : {}),
      ...(createConnections.value ? { createConnections: true } : {}),
    }
    fresh.value = await tokens.create(
      everything.value ? { name: name.value, allConnections: true, ...validity, ...rights } : { name: name.value, connectionIds: chosen.value, ...validity, ...rights },
    )
    name.value = ''
    chosen.value = []
    everything.value = false
    writeData.value = false
    writeSchema.value = false
    createConnections.value = false
    copyFailed.value = false
  } catch (failure) {
    error.value = messageFor(failure)
  } finally {
    busy.value = false
  }
}

async function copy(): Promise<void> {
  try {
    await navigator.clipboard.writeText(fresh.value ?? '')
    copyFailed.value = false
  } catch {
    // Le presse-papiers peut être refusé : le jeton reste sélectionnable à l'écran.
    copyFailed.value = true
  }
}

async function refresh(token: ApiTokenInfo): Promise<void> {
  listError.value = null
  refreshing.value = token.id
  try {
    await tokens.refresh(token.id)
  } catch (failure) {
    listError.value = messageFor(failure)
  } finally {
    refreshing.value = null
  }
}

async function revoke(): Promise<void> {
  if (toRevoke.value === null) {
    return
  }
  try {
    await tokens.revoke(toRevoke.value.id)
  } catch (failure) {
    listError.value = messageFor(failure)
  } finally {
    toRevoke.value = null
  }
}
</script>

<template>
  <AppHeader />
  <main class="page">
    <nav aria-label="Fil d’Ariane" class="crumbs">
      <RouterLink :to="{ name: 'connections' }">Mes connexions</RouterLink> / <RouterLink :to="{ name: 'security' }">Sécurité du compte</RouterLink> / Jetons d’API
    </nav>
    <div class="title">
      <h1>Jetons d’accès à l’API</h1>
      <p>Un jeton donne à un outil (assistant, script, intégration) un accès aux connexions que vous choisissez, en lecture seule sauf droits cochés ci-dessous. Il expire à la date choisie (ou jamais, si vous le décidez), peut être révoqué à tout moment, et il est supprimé si vous changez de mot de passe.</p>
    </div>

    <p class="usage">API REST : <code>/api/v1</code>. Serveur MCP pour un assistant : <code>/mcp</code>. Dans les deux cas, l’en-tête <code>Authorization: Bearer &lt;jeton&gt;</code>.</p>

    <p v-if="listError" class="error" role="alert">{{ listError }}</p>

    <section v-if="fresh" class="fresh" aria-live="polite">
      <h2>Votre nouveau jeton</h2>
      <p>Copiez-le maintenant : il ne sera plus affiché. Seule une empreinte est conservée, il ne peut pas être retrouvé.</p>
      <code>{{ fresh }}</code>
      <p v-if="copyFailed" class="hint">La copie automatique a échoué : sélectionnez le jeton ci-dessus.</p>
      <div class="row">
        <AppButton small @click="copy">Copier</AppButton>
        <AppButton class="copied" small variant="primary" @click="fresh = null">J’ai copié le jeton</AppButton>
      </div>
    </section>

    <section class="panel">
      <h2>Créer un jeton</h2>
      <form class="create" @submit.prevent="create">
        <FormField id="token-name" v-model="name" label="Nom du jeton" hint="Par exemple « Assistant de reporting »." required />
        <fieldset>
          <legend>Connexions autorisées</legend>
          <label class="choice">
            <input id="token-all" v-model="everything" type="checkbox">
            <span>Toutes mes connexions <small class="hint">(actuelles ; « Actualiser » ajoutera les suivantes)</small></span>
          </label>
          <p v-if="connections.loaded && connections.items.length === 0" class="hint">Aucune connexion pour l’instant. Cochez « Toutes mes connexions » ou « Créer des connexions » pour créer le jeton quand même, ou <RouterLink :to="{ name: 'connection-new' }">ajoutez une base</RouterLink>.</p>
          <label v-for="connection in everything ? [] : connections.items" :key="connection.id" class="choice">
            <input v-model="chosen" type="checkbox" :value="connection.id">
            <span>{{ connection.name }}</span>
          </label>
        </fieldset>
        <fieldset>
          <legend>Autorisations (lecture seule par défaut)</legend>
          <label class="choice">
            <input id="token-write-data" v-model="writeData" type="checkbox">
            <span>Modifier les données <small class="hint">(INSERT, UPDATE, DELETE)</small></span>
          </label>
          <label class="choice">
            <input id="token-write-schema" v-model="writeSchema" type="checkbox">
            <span>Modifier la structure <small class="hint">(CREATE, ALTER, DROP, TRUNCATE…)</small></span>
          </label>
          <label class="choice">
            <input id="token-create-connections" v-model="createConnections" type="checkbox">
            <span>Créer des connexions <small class="hint">(elles rejoignent automatiquement le jeton)</small></span>
          </label>
        </fieldset>
        <div class="field">
          <label for="token-lifetime">Validité</label>
          <select id="token-lifetime" v-model="lifetime">
            <option v-for="option in LIFETIMES" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </div>
        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <div><AppButton type="submit" variant="primary" :busy="busy">Créer le jeton</AppButton></div>
      </form>
    </section>

    <section class="panel">
      <h2>Jetons existants</h2>
      <p v-if="tokens.loaded && tokens.items.length === 0" class="empty">Aucun jeton.</p>
      <ul v-else class="tokens">
        <li v-for="token in tokens.items" :key="token.id" class="token">
          <div class="info">
            <p class="token-name">{{ token.name }} <span v-if="expired(token)" class="chip bad">Expiré</span></p>
            <p class="meta"><span v-if="token.allConnections" class="chip">Toutes mes connexions</span> {{ connectionsOf(token) }}</p>
            <p class="meta rights">
              <span v-if="!token.writeData && !token.writeSchema && !token.createConnections" class="chip">Lecture seule</span>
              <span v-if="token.writeData" class="chip warn">Modifie les données</span>
              <span v-if="token.writeSchema" class="chip warn">Modifie la structure</span>
              <span v-if="token.createConnections" class="chip warn">Crée des connexions</span>
            </p>
            <p class="meta">
              <template v-if="token.expiresAt === null">N’expire jamais</template>
              <template v-else>{{ expired(token) ? 'Expiré' : 'Expire' }} le {{ formatDate(token.expiresAt) }}</template> ·
              {{ token.lastUsedAt ? `Dernière utilisation le ${formatDate(token.lastUsedAt)}` : 'Jamais utilisé' }}
            </p>
          </div>
          <div class="actions">
            <AppButton v-if="token.allConnections && !expired(token)" class="refresh" small :busy="refreshing === token.id" :aria-label="`Actualiser ${token.name}`" @click="refresh(token)">Actualiser</AppButton>
            <AppButton class="revoke" small variant="danger" :aria-label="`Révoquer ${token.name}`" @click="toRevoke = token">Révoquer</AppButton>
          </div>
        </li>
      </ul>
    </section>

    <ConfirmDialog
      v-if="toRevoke"
      :title="`Révoquer « ${toRevoke.name} » ?`"
      message="Les outils qui utilisent ce jeton perdent leur accès immédiatement. Cette action est définitive."
      confirm-label="Révoquer"
      danger
      @confirm="revoke"
      @cancel="toRevoke = null"
    />
  </main>
</template>

<style scoped>
.page { max-width: 760px; margin: 0 auto; padding: 36px 24px 64px; display: flex; flex-direction: column; gap: 24px; }
.crumbs { color: var(--mut); font-size: 13px; }
.title { display: flex; flex-direction: column; gap: 6px; }
h1 { font-size: 30px; font-weight: 700; letter-spacing: -0.01em; }
.title p { color: var(--mut); max-width: 62ch; }
h2 { font-size: 18px; font-weight: 600; }
.panel, .fresh { display: flex; flex-direction: column; gap: 16px; padding: 26px; border-radius: 8px; border: 1px solid var(--line); background: var(--s1); }
.fresh { border-color: var(--sec-line); background: var(--sec-bg); }
.fresh p { color: var(--sec-ink); }
.fresh code { display: block; padding: 12px; border-radius: var(--radius); background: var(--bg); border: 1px solid var(--line); font: 400 13px var(--font-mono); overflow-wrap: anywhere; user-select: all; color: var(--fg); }
.row { display: flex; gap: 10px; flex-wrap: wrap; }
form { display: flex; flex-direction: column; gap: 16px; }
fieldset { border: 0; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
legend { font-size: 12px; font-weight: 500; color: var(--mut); margin-bottom: 6px; padding: 0; }
.choice { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid var(--line); border-radius: var(--radius); background: var(--bg); cursor: pointer; }
.choice input { accent-color: var(--acc); }
.field label { display: block; font-size: 12px; font-weight: 500; color: var(--mut); margin-bottom: 6px; }
select { height: 42px; padding: 0 12px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--bg); color: var(--fg); font: inherit; }
.tokens { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.token { display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; padding: 14px 0; border-top: 1px solid var(--line); }
.token:first-child { border-top: 0; padding-top: 0; }
.token-name { font-weight: 600; }
.actions { display: flex; gap: 8px; }
.usage { color: var(--mut); font-size: 13px; }
.usage code { font: 400 12px var(--font-mono); color: var(--fg); }
.meta, .empty, .hint { color: var(--mut); font-size: 13px; }
.chip { display: inline-flex; align-items: center; height: 20px; padding: 0 8px; border-radius: 10px; font-size: 11px; border: 1px solid var(--line); }
.chip.warn { color: var(--sec-ink); border-color: var(--sec-line); background: var(--sec-bg); }
.rights { display: flex; gap: 6px; flex-wrap: wrap; }
.chip.bad { color: var(--bad); border-color: var(--bad-line); background: var(--bad-bg); }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
