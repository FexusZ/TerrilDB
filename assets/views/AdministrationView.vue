<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '../api/client'
import { messageFor } from '../api/errors'
import type { AdminJournalEvent, AdminTokenInfo } from '../api/types'
import AppButton from '../components/AppButton.vue'
import AppHeader from '../components/AppHeader.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import { formatDateTime } from '../utils/format'

/** Libellé des droits connus ; un droit inconnu s’affiche sous son nom, pour ne jamais être caché. */
const PERMISSION_LABELS: Record<string, string> = {
  writeData: 'Écriture des données',
  writeSchema: 'Écriture de la structure',
  createConnections: 'Création de connexions',
  diagnostic: 'Supervision',
  singleStep: 'Écriture en une étape',
}

const JOURNAL_LABELS: Record<AdminJournalEvent['action'], string> = {
  'account.disabled': 'Compte désactivé',
  'account.enabled': 'Compte réactivé',
  'admin.token_revoked': 'Jeton d’un autre compte révoqué',
}

const tokens = ref<AdminTokenInfo[]>([])
const tokensLoaded = ref(false)
const events = ref<AdminJournalEvent[]>([])
const next = ref<string | null>(null)
const journalLoaded = ref(false)
const error = ref<string | null>(null)
const target = ref<AdminTokenInfo | null>(null)
const busy = ref(false)

const grantedRights = (token: AdminTokenInfo): string[] =>
  Object.entries(token.permissions)
    .filter(([, granted]) => granted)
    .map(([name]) => PERMISSION_LABELS[name] ?? name)

function connectionsLabel(token: AdminTokenInfo): string {
  if (token.allConnections) {
    return 'Toutes les connexions'
  }

  return token.connectionCount === 1 ? '1 connexion' : `${token.connectionCount} connexions`
}

async function loadTokens(): Promise<void> {
  tokens.value = (await api<{ tokens: AdminTokenInfo[] }>('GET', '/web/admin/tokens')).tokens
  tokensLoaded.value = true
}

async function loadJournal(before: string | null = null): Promise<void> {
  const page = await api<{ events: AdminJournalEvent[]; next: string | null }>('GET', `/web/admin/journal${before === null ? '' : `?before=${encodeURIComponent(before)}`}`)
  events.value = before === null ? page.events : [...events.value, ...page.events]
  next.value = page.next
  journalLoaded.value = true
}

onMounted(async () => {
  try {
    await loadTokens()
    await loadJournal()
  } catch (failure) {
    error.value = messageFor(failure)
  }
})

async function loadMore(): Promise<void> {
  error.value = null
  try {
    await loadJournal(next.value)
  } catch (failure) {
    error.value = messageFor(failure)
  }
}

async function revoke(): Promise<void> {
  const token = target.value
  if (token === null) {
    return
  }
  error.value = null
  busy.value = true
  try {
    await api('DELETE', `/web/admin/tokens/${token.id}`)
    tokens.value = tokens.value.filter((item) => item.id !== token.id)
    await loadJournal()
  } catch (failure) {
    error.value = messageFor(failure)
  } finally {
    busy.value = false
    target.value = null
  }
}
</script>

<template>
  <AppHeader />
  <main class="page">
    <nav aria-label="Fil d’Ariane" class="crumbs">
      <RouterLink :to="{ name: 'connections' }">Mes connexions</RouterLink> / <RouterLink :to="{ name: 'security' }">Sécurité du compte</RouterLink> / Administration
    </nav>
    <div class="title">
      <h1>Administration</h1>
      <p>Les jetons d’API de tous les comptes et ce que les administrateurs ont fait. Vous ne voyez jamais le texte d’un jeton ni le contenu des connexions : seulement de quoi décider d’une révocation.</p>
    </div>

    <p v-if="error" class="error" role="alert">{{ error }}</p>

    <section v-if="tokensLoaded" class="panel tokens">
      <h2>Jetons d’API</h2>
      <p v-if="tokens.length === 0" class="empty">Aucun jeton.</p>
      <table v-else>
        <thead>
          <tr><th>Jeton</th><th>Propriétaire</th><th>Droits</th><th>Connexions</th><th>Créé</th><th>Expire</th><th>Dernier usage</th><th>État</th><th><span class="sr">Action</span></th></tr>
        </thead>
        <tbody>
          <tr v-for="token in tokens" :key="token.id" :class="{ off: token.status === 'expired' || token.owner.disabled }">
            <td class="name">{{ token.name }}</td>
            <td>{{ token.owner.username }}<span v-if="token.owner.disabled" class="note"> (compte désactivé)</span></td>
            <td>{{ grantedRights(token).join(', ') || 'Lecture seule' }}</td>
            <td>{{ connectionsLabel(token) }}</td>
            <td>{{ formatDateTime(token.createdAt) }}</td>
            <td>{{ token.expiresAt === null ? 'Jamais' : formatDateTime(token.expiresAt) }}</td>
            <td>{{ token.lastUsedAt === null ? 'Jamais utilisé' : formatDateTime(token.lastUsedAt) }}</td>
            <td>{{ token.status === 'expired' ? 'Expiré' : 'Actif' }}</td>
            <td class="act"><AppButton small variant="danger" @click="target = token">Révoquer</AppButton></td>
          </tr>
        </tbody>
      </table>
    </section>

    <section v-if="journalLoaded" class="panel journal">
      <h2>Journal d’administration</h2>
      <p v-if="events.length === 0" class="empty">Aucune action d’administration pour l’instant.</p>
      <ul v-else class="events">
        <li v-for="event in events" :key="event.id">
          <span class="what">{{ JOURNAL_LABELS[event.action] }}</span>
          <span class="meta">{{ formatDateTime(event.occurredAt) }} · {{ event.actor?.username ?? 'compte supprimé' }}</span>
        </li>
      </ul>
      <AppButton v-if="next !== null" class="more" small variant="ghost" @click="loadMore">Voir plus</AppButton>
    </section>

    <ConfirmDialog
      v-if="target"
      title="Révoquer ce jeton ?"
      :message="`Le jeton « ${target.name} » de « ${target.owner.username} » cessera de fonctionner tout de suite. Cette action est irréversible : le propriétaire devra en créer un autre, et il n’est pas prévenu.`"
      confirm-label="Révoquer"
      danger
      :busy="busy"
      @confirm="revoke"
      @cancel="target = null"
    />
  </main>
</template>

<style scoped>
.page { max-width: 1100px; margin: 0 auto; padding: 28px 24px 64px; display: flex; flex-direction: column; gap: 20px; }
.crumbs { font-size: 13px; color: var(--mut); }
.title { display: flex; flex-direction: column; gap: 6px; }
h1 { font-size: 30px; line-height: 1.1; font-weight: 600; }
h2 { font-size: 18px; font-weight: 600; margin-bottom: 8px; }
.title p, .empty { color: var(--mut); font-size: 13px; }
.panel { padding: 16px 20px; border-radius: 8px; border: 1px solid var(--line); background: var(--s1); overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; font-weight: 500; color: var(--mut); padding: 10px 8px; }
td { padding: 10px 8px; border-top: 1px solid var(--line); vertical-align: top; }
.name { font-weight: 500; overflow-wrap: anywhere; }
.note { color: var(--mut); }
tr.off td:not(.act) { opacity: 0.7; }
.act { text-align: right; }
.events { list-style: none; padding: 0; display: flex; flex-direction: column; font-size: 13px; }
.events li { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 8px 0; border-top: 1px solid var(--line); }
.meta { color: var(--mut); }
.more { margin-top: 12px; }
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
