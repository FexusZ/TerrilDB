<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api/client'
import { messageFor } from '../api/errors'
import type { InvitationInfo } from '../api/types'
import AppButton from '../components/AppButton.vue'
import AppHeader from '../components/AppHeader.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import FormField from '../components/FormField.vue'
import { formatDate } from '../utils/format'

const router = useRouter()

const items = ref<InvitationInfo[]>([])
const note = ref('')
const lifetime = ref('7')
const error = ref<string | null>(null)
const listError = ref<string | null>(null)
const busy = ref(false)
const fresh = ref<string | null>(null)
const toRevoke = ref<InvitationInfo | null>(null)
const copyFailed = ref(false)
const emailEnabled = ref(false)
const email = ref('')
const mailStatus = ref<'none' | 'sent' | 'failed'>('none')

const LIFETIMES = [
  { value: '1', label: '1 jour' },
  { value: '7', label: '7 jours' },
  { value: '30', label: '30 jours' },
]

function state(invitation: InvitationInfo): string {
  if (invitation.status === 'used') {
    return `Utilisée par ${invitation.usedBy ?? 'un compte supprimé'}`
  }

  return invitation.status === 'expired' ? 'Expirée' : 'En attente'
}

onMounted(async () => {
  try {
    const result = await api<{ invitations: InvitationInfo[]; emailEnabled: boolean }>('GET', '/web/invitations')
    items.value = result.invitations
    emailEnabled.value = result.emailEnabled
  } catch (failure) {
    listError.value = messageFor(failure)
  }
})

async function create(): Promise<void> {
  error.value = null
  busy.value = true
  try {
    const address = email.value.trim()
    const body = { note: note.value, lifetimeDays: Number(lifetime.value), ...(address === '' ? {} : { email: address }) }
    const { token, invitation, emailSent } = await api<{ token: string; invitation: InvitationInfo; emailSent?: boolean }>('POST', '/web/invitations', body)
    mailStatus.value = address === '' ? 'none' : emailSent === true ? 'sent' : 'failed'
    email.value = ''
    items.value = [invitation, ...items.value]
    // Le lien est construit ici : le serveur ignore sous quelle adresse on le joint.
    fresh.value = `${window.location.origin}${router.resolve({ name: 'register', query: { invitation: token } }).href}`
    note.value = ''
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
    // Le presse-papiers peut être refusé : le lien reste sélectionnable à l'écran.
    copyFailed.value = true
  }
}

async function revoke(): Promise<void> {
  if (toRevoke.value === null) {
    return
  }
  const id = toRevoke.value.id
  try {
    await api('DELETE', `/web/invitations/${id}`)
    items.value = items.value.filter((invitation) => invitation.id !== id)
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
      <RouterLink :to="{ name: 'connections' }">Mes connexions</RouterLink> / <RouterLink :to="{ name: 'security' }">Sécurité du compte</RouterLink> / Invitations
    </nav>
    <div class="title">
      <h1>Inviter quelqu’un</h1>
      <p>Une invitation est un lien à usage unique qui permet de créer un compte. Le serveur n’envoie aucun e-mail : transmettez le lien vous-même. Il expire, et vous pouvez le révoquer tant qu’il n’a pas servi.</p>
    </div>

    <p v-if="listError" class="error" role="alert">{{ listError }}</p>

    <section v-if="fresh" class="fresh" aria-live="polite">
      <h2>Votre nouveau lien</h2>
      <p v-if="mailStatus === 'sent'">Un e-mail a été envoyé à la personne invitée (son adresse n’est conservée nulle part). Le lien ci-dessous est aussi à vous, au cas où.</p>
      <p v-else-if="mailStatus === 'failed'" class="warn">L’e-mail n’a pas pu être envoyé : transmettez le lien vous-même.</p>
      <p>Copiez-le maintenant : il ne sera plus affiché. Seule une empreinte est conservée.</p>
      <code>{{ fresh }}</code>
      <p v-if="copyFailed" class="hint">La copie automatique a échoué : sélectionnez le lien ci-dessus.</p>
      <div class="row">
        <AppButton small @click="copy">Copier</AppButton>
        <AppButton class="copied" small variant="primary" @click="fresh = null">J’ai copié le lien</AppButton>
      </div>
    </section>

    <section class="panel">
      <h2>Créer une invitation</h2>
      <form class="create" @submit.prevent="create">
        <FormField id="invitation-note" v-model="note" label="Pour qui ? (facultatif)" hint="Un repère pour vous, par exemple « Pour Marie »." />
        <FormField v-if="emailEnabled" id="invitation-email" v-model="email" label="Envoyer par e-mail (facultatif)" type="email" autocomplete="off" inputmode="email" hint="Le serveur envoie le lien puis oublie l’adresse. Sans adresse, copiez le lien vous-même." />
        <div class="field">
          <label for="invitation-lifetime">Validité</label>
          <select id="invitation-lifetime" v-model="lifetime">
            <option v-for="option in LIFETIMES" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </div>
        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <div><AppButton type="submit" variant="primary" :busy="busy">Créer l’invitation</AppButton></div>
      </form>
    </section>

    <section class="panel">
      <h2>Invitations émises</h2>
      <p v-if="items.length === 0" class="empty">Aucune invitation pour l’instant.</p>
      <ul v-else>
        <li v-for="invitation in items" :key="invitation.id" class="invitation" :class="invitation.status">
          <div>
            <p class="name">{{ invitation.note || 'Sans repère' }}</p>
            <p class="meta">{{ state(invitation) }} · créée le {{ formatDate(invitation.createdAt) }}<template v-if="invitation.status !== 'used'"> · expire le {{ formatDate(invitation.expiresAt) }}</template></p>
          </div>
          <AppButton class="revoke" small variant="danger" @click="toRevoke = invitation">Révoquer</AppButton>
        </li>
      </ul>
    </section>

    <ConfirmDialog
      v-if="toRevoke"
      title="Révoquer l’invitation ?"
      message="Le lien cessera de fonctionner. Un compte déjà créé avec cette invitation n’est pas touché."
      confirm-label="Révoquer"
      danger
      @confirm="revoke"
      @cancel="toRevoke = null"
    />
  </main>
</template>

<style scoped>
.page { max-width: 760px; margin: 0 auto; padding: 28px 24px 64px; display: flex; flex-direction: column; gap: 20px; }
.crumbs { font-size: 13px; color: var(--mut); }
.title { display: flex; flex-direction: column; gap: 6px; }
h1 { font-size: 24px; font-weight: 700; }
h2 { font-size: 16px; font-weight: 600; margin-bottom: 10px; }
.title p, .meta, .hint, .empty { color: var(--mut); font-size: 13px; }
.panel { padding: 18px 20px; border-radius: 8px; border: 1px solid var(--line); background: var(--s1); }
.create { display: flex; flex-direction: column; gap: 14px; }
.field { display: flex; flex-direction: column; gap: 6px; font-size: 13px; }
select { height: 36px; padding: 0 10px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s2); color: var(--fg); font: 400 13px var(--font-ui); max-width: 200px; }
ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.invitation { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 10px 12px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s2); }
.invitation.expired, .invitation.used { opacity: 0.75; }
.name { font-weight: 500; }
.fresh { padding: 16px 18px; border-radius: 8px; border: 1px solid var(--sec-line); background: var(--sec-bg); display: flex; flex-direction: column; gap: 10px; }
.fresh code { display: block; padding: 10px 12px; border-radius: var(--radius); background: var(--bg); border: 1px solid var(--line); color: var(--sec-ink); font: 400 12.5px/1.5 var(--font-mono); word-break: break-all; user-select: all; }
.row { display: flex; gap: 8px; }
.warn { color: var(--sec-ink); }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
