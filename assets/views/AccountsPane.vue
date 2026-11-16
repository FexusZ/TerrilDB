<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { messageFor } from '../api/errors'
import { fetchAccounts, fetchEncryption } from '../api/diagnostics'
import type { AccountReport, ConnectionEncryption } from '../api/types'
import AppButton from '../components/AppButton.vue'
import { useWorkspaceStore } from '../stores/workspace'
import { describeEncryption, describeFinding, severityLabel } from '../utils/accounts'
import { describeStatus } from '../utils/diagnostics'

const props = defineProps<{ id: string }>()
const workspace = useWorkspaceStore()

const report = ref<AccountReport | null>(null)
const encryption = ref<ConnectionEncryption | null>(null)
const accountsError = ref<string | null>(null)
const encryptionError = ref<string | null>(null)
const loading = ref(false)

const note = computed(() => (report.value === null ? null : describeStatus(report.value.status, 'les comptes')))

function scope() {
  return { database: workspace.database, schema: workspace.schema }
}

const privileges = (privileges: string[]): string => (privileges.length === 0 ? '' : privileges.join(', '))
const perDatabase = (map: Record<string, string[]>): string =>
  Object.entries(map)
    .map(([database, list]) => `${database} : ${list.join(', ')}`)
    .join(' · ')

async function load(): Promise<void> {
  loading.value = true
  accountsError.value = null
  encryptionError.value = null
  // Deux lectures indépendantes : l'échec de l'une ne cache pas l'autre.
  await Promise.all([
    fetchAccounts(props.id, scope()).then(
      (value) => (report.value = value),
      (failure: unknown) => {
        report.value = null
        accountsError.value = messageFor(failure)
      },
    ),
    fetchEncryption(props.id, scope()).then(
      (value) => (encryption.value = value),
      (failure: unknown) => {
        encryption.value = null
        encryptionError.value = messageFor(failure)
      },
    ),
  ])
  loading.value = false
}

onMounted(load)
</script>

<template>
  <section class="pane">
    <div class="head">
      <h1>Comptes et privilèges</h1>
      <p class="meta">Lecture seule, à la demande : rien n’est créé ni modifié sur le serveur. Les empreintes de mot de passe ne sont jamais lues.</p>
      <div class="tools"><AppButton small :busy="loading" @click="load">Actualiser</AppButton></div>
      <p v-if="encryptionError" class="error" role="alert">{{ encryptionError }}</p>
      <p v-else-if="encryption" :class="encryption.encrypted ? 'clean' : 'note'" role="status" data-testid="encryption">{{ describeEncryption(encryption) }}</p>
    </div>

    <p v-if="accountsError" class="error" role="alert">{{ accountsError }}</p>
    <p v-else-if="!report" class="meta" role="status">Lecture en cours…</p>
    <p v-else-if="note" class="note" role="status">{{ note }}</p>
    <template v-else>
      <p v-if="report.truncated" class="note" role="status">Le serveur en a davantage : seuls les 300 premiers comptes sont montrés, l’audit est partiel.</p>

      <p v-if="report.privilegesIncomplete" class="note" role="status">Lecture incomplète des droits : certains droits manquent peut-être ci-dessous, ne concluez pas qu’un compte n’a pas un droit.</p>

      <section class="group" aria-labelledby="findings-title">
        <h2 id="findings-title">Audit de sécurité<template v-if="report.findings.length > 0"> · {{ report.findings.length }}</template></h2>
        <p v-if="report.findings.length === 0 && !report.privilegesIncomplete && !report.truncated" class="clean">Aucune constatation.</p>
        <ul v-else-if="report.findings.length > 0" class="findings" data-testid="findings">
          <li v-for="(finding, index) in report.findings" :key="index" :class="finding.severity">
            <span class="sev">{{ severityLabel(finding.severity) }}</span>
            <strong v-if="finding.account">{{ finding.account }}</strong>
            <span>{{ describeFinding(finding) }}</span>
          </li>
        </ul>
      </section>

      <section class="group" aria-labelledby="accounts-title">
        <h2 id="accounts-title">Comptes · {{ report.accounts.length }}</h2>
        <div class="scroll">
          <table data-testid="accounts">
            <thead><tr><th>Compte</th><th>Authentification</th><th>État</th><th>Rôles</th><th>Droits globaux</th><th>Droits par base</th></tr></thead>
            <tbody>
              <tr v-for="account in report.accounts" :key="account.name">
                <td>{{ account.name }}<span v-if="account.current" class="me"> TerrilDB</span><span v-if="account.isRole" class="tag"> rôle</span></td>
                <td>{{ account.plugin }}<template v-if="!account.passwordSet"> · sans mot de passe</template><template v-if="account.tls !== 'none'"> · TLS exigé</template></td>
                <td><template v-if="account.locked">verrouillé</template><template v-if="account.passwordExpired"> expiré</template></td>
                <td>{{ account.roles.join(', ') }}</td>
                <td class="priv">{{ privileges(account.globalPrivileges) }}</td>
                <td class="priv">{{ perDatabase(account.databasePrivileges) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </section>
</template>

<style scoped>
.pane { flex: 1; min-width: 0; overflow: auto; padding: 24px 28px 48px; display: flex; flex-direction: column; gap: 22px; }
h1 { font-size: 30px; line-height: 1.1; font-weight: 600; }
h2 { font-size: 15px; font-weight: 600; margin-bottom: 8px; }
.head { display: flex; flex-direction: column; gap: 8px; }
.tools { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; }
.meta { color: var(--mut); font-size: 13px; }
.findings { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.findings li { display: flex; flex-wrap: wrap; gap: 8px; align-items: baseline; padding: 8px 12px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s1); font-size: 13px; }
.findings li.high { border-color: var(--bad-line); background: var(--bad-bg); }
.findings li.medium { border-color: var(--sec-line); background: var(--sec-bg); }
.sev { font-weight: 600; font-size: 12px; color: var(--mut); }
.scroll { overflow-x: auto; }
table { border-collapse: collapse; width: 100%; font-size: 13px; }
th, td { text-align: left; padding: 6px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
th { color: var(--mut); font-weight: 500; white-space: nowrap; }
.priv { font-family: var(--font-mono); font-size: 12px; max-width: 360px; overflow-wrap: anywhere; }
.me { color: var(--acc); font-weight: 500; }
.tag { color: var(--mut); }
.clean { padding: 12px 14px; border-radius: var(--radius); background: var(--ok-bg); border: 1px solid var(--ok-line); color: var(--ok); }
.note { padding: 10px 12px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
