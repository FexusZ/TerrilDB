<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '../api/client'
import { messageFor } from '../api/errors'
import type { AccountInfo } from '../api/types'
import AppButton from '../components/AppButton.vue'
import AppHeader from '../components/AppHeader.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import { formatDateTime } from '../utils/format'

const accounts = ref<AccountInfo[]>([])
const loaded = ref(false)
const error = ref<string | null>(null)
const target = ref<AccountInfo | null>(null)
const busy = ref(false)

onMounted(async () => {
  try {
    accounts.value = (await api<{ accounts: AccountInfo[] }>('GET', '/web/accounts')).accounts
    loaded.value = true
  } catch (failure) {
    error.value = messageFor(failure)
  }
})

async function confirm(): Promise<void> {
  const account = target.value
  if (account === null) {
    return
  }
  error.value = null
  busy.value = true
  try {
    const { account: updated } = await api<{ account: AccountInfo }>('POST', `/web/accounts/${account.id}/${account.disabled ? 'enable' : 'disable'}`)
    accounts.value = accounts.value.map((item) => (item.id === updated.id ? updated : item))
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
      <RouterLink :to="{ name: 'connections' }">Mes connexions</RouterLink> / <RouterLink :to="{ name: 'security' }">Sécurité du compte</RouterLink> / Comptes
    </nav>
    <div class="title">
      <h1>Comptes</h1>
      <p>Un compte désactivé ne peut plus se connecter, ses sessions ouvertes et ses jetons d’API sont refusés ; rien n’est supprimé, et la réactivation rétablit tout. Les connexions et les jetons de chacun restent chiffrés : vous ne les voyez pas.</p>
    </div>

    <p v-if="error" class="error" role="alert">{{ error }}</p>

    <section v-if="loaded" class="panel">
      <table>
        <thead>
          <tr><th>Utilisateur</th><th>Rôle</th><th>Second facteur</th><th>Dernière connexion</th><th>État</th><th><span class="sr">Action</span></th></tr>
        </thead>
        <tbody>
          <tr v-for="account in accounts" :key="account.id" :class="{ off: account.disabled }">
            <td class="name">{{ account.username }}<span v-if="account.current" class="you"> (vous)</span></td>
            <td>{{ account.role === 'admin' ? 'Administrateur' : 'Membre' }}</td>
            <td>{{ account.totpEnabled ? 'Activé' : 'Non' }}</td>
            <td>{{ account.lastLoginAt === null ? 'Jamais' : formatDateTime(account.lastLoginAt) }}</td>
            <td>{{ account.disabled ? 'Désactivé' : 'Actif' }}</td>
            <td class="act">
              <AppButton v-if="!account.current" small :variant="account.disabled ? 'ghost' : 'danger'" @click="target = account">{{ account.disabled ? 'Réactiver' : 'Désactiver' }}</AppButton>
            </td>
          </tr>
        </tbody>
      </table>
    </section>

    <ConfirmDialog
      v-if="target"
      :title="target.disabled ? 'Réactiver le compte ?' : 'Désactiver le compte ?'"
      :message="target.disabled ? `« ${target.username} » pourra de nouveau se connecter, et ses jetons d’API refonctionneront.` : `« ${target.username} » ne pourra plus se connecter, ses sessions ouvertes et ses jetons d’API seront refusés. Rien n’est supprimé.`"
      :confirm-label="target.disabled ? 'Réactiver' : 'Désactiver'"
      :danger="!target.disabled"
      :busy="busy"
      @confirm="confirm"
      @cancel="target = null"
    />
  </main>
</template>

<style scoped>
.page { max-width: 900px; margin: 0 auto; padding: 28px 24px 64px; display: flex; flex-direction: column; gap: 20px; }
.crumbs { font-size: 13px; color: var(--mut); }
.title { display: flex; flex-direction: column; gap: 6px; }
h1 { font-size: 30px; line-height: 1.1; font-weight: 600; }
.title p { color: var(--mut); font-size: 13px; }
.panel { padding: 8px 20px; border-radius: 8px; border: 1px solid var(--line); background: var(--s1); overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; font-weight: 500; color: var(--mut); padding: 10px 8px; }
td { padding: 10px 8px; border-top: 1px solid var(--line); }
.name { font-weight: 500; }
.you { color: var(--mut); font-weight: 400; }
tr.off td:not(.act) { opacity: 0.7; }
.act { text-align: right; }
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
