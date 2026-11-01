<script setup lang="ts">
import { ref } from 'vue'
import { messageFor } from '../api/errors'
import AppButton from '../components/AppButton.vue'
import AppHeader from '../components/AppHeader.vue'
import FormField from '../components/FormField.vue'
import QrCode from '../components/QrCode.vue'
import RecoveryCodes from '../components/RecoveryCodes.vue'
import SecretNotice from '../components/SecretNotice.vue'
import { useSessionStore, type PendingTotp } from '../stores/session'

const session = useSessionStore()

// Mot de passe
const current = ref('')
const next = ref('')
const confirmation = ref('')
const passwordError = ref<string | null>(null)
const passwordDone = ref(false)
const passwordBusy = ref(false)

async function changePassword(): Promise<void> {
  passwordError.value = null
  passwordDone.value = false
  if (next.value !== confirmation.value) {
    passwordError.value = 'Les deux mots de passe ne sont pas identiques.'
    return
  }
  passwordBusy.value = true
  try {
    await session.changePassword(current.value, next.value)
    current.value = next.value = confirmation.value = ''
    passwordDone.value = true
  } catch (failure) {
    passwordError.value = messageFor(failure)
  } finally {
    passwordBusy.value = false
  }
}

// Second facteur
const pending = ref<PendingTotp | null>(null)
const totpCode = ref('')
const totpError = ref<string | null>(null)
const totpBusy = ref(false)
const shownCodes = ref<string[] | null>(null)
const disablePassword = ref('')
const disableError = ref<string | null>(null)

async function beginTotp(): Promise<void> {
  totpError.value = null
  totpBusy.value = true
  try {
    pending.value = await session.beginTotp()
    totpCode.value = ''
  } catch (failure) {
    totpError.value = messageFor(failure)
  } finally {
    totpBusy.value = false
  }
}

async function confirmTotp(): Promise<void> {
  if (pending.value === null) {
    return
  }
  totpError.value = null
  totpBusy.value = true
  try {
    shownCodes.value = await session.confirmTotp(pending.value.secret, totpCode.value)
    pending.value = null
  } catch (failure) {
    totpError.value = messageFor(failure)
  } finally {
    totpBusy.value = false
  }
}

async function disableTotp(): Promise<void> {
  disableError.value = null
  try {
    await session.disableTotp(disablePassword.value)
    disablePassword.value = ''
    shownCodes.value = null
  } catch (failure) {
    disableError.value = messageFor(failure)
  }
}

// Codes de récupération
const recoveryPassword = ref('')
const recoveryError = ref<string | null>(null)

async function regenerate(): Promise<void> {
  recoveryError.value = null
  try {
    shownCodes.value = await session.regenerateRecoveryCodes(recoveryPassword.value)
    recoveryPassword.value = ''
  } catch (failure) {
    recoveryError.value = messageFor(failure)
  }
}
</script>

<template>
  <AppHeader />
  <main v-if="session.user" class="page">
    <nav aria-label="Fil d’Ariane" class="crumbs"><RouterLink :to="{ name: 'connections' }">Mes connexions</RouterLink> / Sécurité du compte</nav>
    <div class="title">
      <h1>Sécurité du compte</h1>
      <p>{{ session.user.email }}</p>
    </div>

    <section class="panel">
      <h2>Mot de passe</h2>
      <form class="password" @submit.prevent="changePassword">
        <FormField id="current" v-model="current" label="Mot de passe actuel" type="password" autocomplete="current-password" required />
        <div class="pair">
          <FormField id="new" v-model="next" label="Nouveau mot de passe" type="password" autocomplete="new-password" hint="12 caractères au minimum." required />
          <FormField id="confirmation" v-model="confirmation" label="Confirmation" type="password" autocomplete="new-password" required />
        </div>
        <SecretNotice>Vos connexions et votre double authentification seront rechiffrées avec la nouvelle clé, en une seule opération. Si l’une d’elles ne peut pas être relue, rien ne change.</SecretNotice>
        <p v-if="passwordDone" class="ok" role="status">Mot de passe modifié. Les autres sessions sont fermées.</p>
        <p v-if="passwordError" class="error" role="alert">{{ passwordError }}</p>
        <div><AppButton type="submit" variant="primary" :busy="passwordBusy">Changer le mot de passe</AppButton></div>
      </form>
    </section>

    <section class="panel totp">
      <div class="head">
        <h2>Double authentification</h2>
        <span class="chip" :class="{ ok: session.user.totpEnabled }">{{ session.user.totpEnabled ? 'Activée' : 'Désactivée' }}</span>
      </div>
      <p class="lede">Un code à 6 chiffres de votre application d’authentification est demandé à chaque connexion. Le secret est chiffré avec votre clé, comme vos connexions.</p>

      <RecoveryCodes v-if="shownCodes" :codes="shownCodes" @noted="shownCodes = null" />

      <template v-else-if="session.user.totpEnabled">
        <form class="totp-disable inline" @submit.prevent="disableTotp">
          <FormField id="disable-password" v-model="disablePassword" label="Mot de passe pour désactiver" type="password" autocomplete="current-password" required />
          <AppButton type="submit" variant="danger">Désactiver</AppButton>
          <p v-if="disableError" class="error" role="alert">{{ disableError }}</p>
        </form>
      </template>

      <template v-else-if="pending">
        <div class="enrol">
          <QrCode :value="pending.uri" label="Code QR à scanner avec votre application d’authentification" />
          <div class="enrol-side">
            <p>Scannez ce code avec votre application, ou saisissez ce secret :</p>
            <p class="secret mono">{{ pending.secret }}</p>
            <form class="totp-confirm" @submit.prevent="confirmTotp">
              <FormField id="totp-code" v-model="totpCode" label="Code à 6 chiffres" mono inputmode="numeric" autocomplete="one-time-code" :maxlength="6" required />
              <p v-if="totpError" class="error" role="alert">{{ totpError }}</p>
              <AppButton type="submit" variant="primary" :busy="totpBusy">Activer</AppButton>
            </form>
          </div>
        </div>
      </template>

      <template v-else>
        <p v-if="totpError" class="error" role="alert">{{ totpError }}</p>
        <div><AppButton class="enable" variant="primary" :busy="totpBusy" @click="beginTotp">Activer la double authentification</AppButton></div>
      </template>
    </section>

    <section v-if="session.user.totpEnabled" class="panel recovery">
      <div class="head">
        <h2>Codes de récupération</h2>
        <span class="chip">{{ session.user.recoveryCodesLeft }} sur 10 restants</span>
      </div>
      <p class="lede">Chaque code remplace un code de l’application, une seule fois. Votre mot de passe reste demandé. Les codes ne sont affichés qu’au moment de leur création.</p>
      <form class="recovery-form inline" @submit.prevent="regenerate">
        <FormField id="recovery-password" v-model="recoveryPassword" label="Mot de passe" type="password" autocomplete="current-password" required />
        <AppButton type="submit">Générer de nouveaux codes</AppButton>
        <p v-if="recoveryError" class="error" role="alert">{{ recoveryError }}</p>
      </form>
    </section>
  </main>
</template>

<style scoped>
.page { max-width: 760px; margin: 0 auto; padding: 36px 24px 64px; display: flex; flex-direction: column; gap: 24px; }
.crumbs { color: var(--mut); font-size: 13px; }
.title { display: flex; flex-direction: column; gap: 6px; }
h1 { font-size: 30px; font-weight: 700; letter-spacing: -0.01em; }
.title p, .lede { color: var(--mut); }
.lede { max-width: 60ch; }
.panel { display: flex; flex-direction: column; gap: 18px; padding: 26px; border-radius: 8px; border: 1px solid var(--line); background: var(--s1); }
h2 { font-size: 18px; font-weight: 600; }
.head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; }
.chip { display: inline-flex; align-items: center; height: 24px; padding: 0 10px; border-radius: 12px; border: 1px solid var(--line); font-size: 12px; color: var(--mut); }
.chip.ok { color: var(--ok); border-color: var(--ok-line); background: var(--ok-bg); }
form { display: flex; flex-direction: column; gap: 16px; }
.pair { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }
.inline { flex-direction: row; flex-wrap: wrap; align-items: flex-end; padding-top: 16px; border-top: 1px solid var(--line); }
.inline > :first-child { flex: 1 1 260px; max-width: 360px; }
.enrol { display: flex; flex-wrap: wrap; gap: 24px; }
.enrol-side { flex: 1 1 280px; display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.secret { padding: 9px 12px; border-radius: 5px; background: var(--bg); border: 1px solid var(--line); overflow-wrap: anywhere; font-size: 13px; }
.ok:not(.chip) { padding: 10px 12px; border-radius: var(--radius); background: var(--ok-bg); border: 1px solid var(--ok-line); color: var(--ok); font-size: 13px; }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; flex-basis: 100%; }
</style>
