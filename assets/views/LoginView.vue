<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError } from '../api/client'
import { messageFor } from '../api/errors'
import AppButton from '../components/AppButton.vue'
import AuthLayout from '../components/AuthLayout.vue'
import FormField from '../components/FormField.vue'
import SecretNotice from '../components/SecretNotice.vue'
import { useSessionStore } from '../stores/session'

const session = useSessionStore()
const route = useRoute()
const router = useRouter()

const step = ref<'credentials' | 'code'>('credentials')
const login = ref('')
const password = ref('')
const code = ref('')
const useRecoveryCode = ref(false)
const error = ref<string | null>(null)
const busy = ref(false)

const codeLabel = computed(() => (useRecoveryCode.value ? 'Code de récupération' : 'Code à 6 chiffres'))

/** Seule une adresse interne est suivie : un lien de connexion ne doit pas renvoyer vers un autre site. */
function destination(): string | { name: string } {
  const redirect = route.query.redirect
  return typeof redirect === 'string' && redirect.startsWith('/') && !redirect.startsWith('//') ? redirect : { name: 'workspace-home' }
}

async function submit(): Promise<void> {
  busy.value = true
  error.value = null
  try {
    await session.login(login.value, password.value, step.value === 'code' ? code.value : undefined)
    await router.push(destination())
  } catch (failure) {
    if (failure instanceof ApiError && failure.code === 'second_factor_required') {
      step.value = 'code'
    } else {
      error.value = messageFor(failure)
    }
  } finally {
    busy.value = false
  }
}

function back(): void {
  step.value = 'credentials'
  password.value = ''
  code.value = ''
  useRecoveryCode.value = false
  error.value = null
}
</script>

<template>
  <AuthLayout
    :headline="step === 'code' ? 'Une deuxième clé, sur votre téléphone.' : 'Vos accès restent à vous.'"
    :text="
      step === 'code'
        ? 'Même si votre mot de passe fuite, il ne suffit pas pour ouvrir votre compte.'
        : 'Les identifiants de vos bases sont chiffrés avec une clé tirée de votre mot de passe. Le serveur ne peut pas les relire, même avec un accès complet à sa base.'
    "
    :facts="step === 'code' ? 'CODE À 6 CHIFFRES · RENOUVELÉ TOUTES LES 30 SECONDES' : 'ARGON2ID · XCHACHA20-POLY1305 · TOTP'"
  >
    <form @submit.prevent="submit">
      <div class="title">
        <h1>{{ step === 'code' ? 'Code de vérification' : 'Connexion' }}</h1>
        <p>{{ step === 'code' ? 'Saisissez le code affiché par votre application d’authentification.' : 'Accédez à vos bases de données.' }}</p>
      </div>

      <template v-if="step === 'credentials'">
        <FormField id="login" v-model="login" label="E-mail ou nom d’utilisateur" autocomplete="username" required />
        <FormField id="password" v-model="password" label="Mot de passe" type="password" autocomplete="current-password" required />
      </template>
      <template v-else>
        <FormField id="code" v-model="code" :label="codeLabel" mono autocomplete="one-time-code" :inputmode="useRecoveryCode ? 'text' : 'numeric'" required />
        <p class="once">Un code ne sert qu’une fois. S’il est refusé, attendez le suivant plutôt que de le saisir à nouveau.</p>
      </template>

      <p v-if="route.query.recovered === '1' && step === 'credentials'" class="ok" role="status">Votre nouveau mot de passe est enregistré : connectez-vous avec.</p>
      <p v-if="error" class="error" role="alert">{{ error }}</p>

      <AppButton type="submit" variant="primary" block :busy="busy">{{ step === 'code' ? 'Vérifier' : 'Se connecter' }}</AppButton>

      <div v-if="step === 'code'" class="links">
        <button type="button" class="link switch" @click="useRecoveryCode = !useRecoveryCode">
          {{ useRecoveryCode ? 'Utiliser le code de l’application' : 'Utiliser un code de récupération' }}
        </button>
        <button type="button" class="link back" @click="back">Retour</button>
      </div>
      <template v-else>
        <SecretNotice>Un administrateur ne peut pas réinitialiser votre mot de passe sans rendre vos connexions enregistrées illisibles.</SecretNotice>
        <p class="alt"><RouterLink class="forgot" :to="{ name: 'recover' }">Mot de passe oublié ?</RouterLink></p>
        <p class="alt">Pas encore de compte ? <RouterLink :to="{ name: 'register' }">Créer un compte</RouterLink></p>
      </template>
    </form>
  </AuthLayout>
</template>

<style scoped>
form { display: flex; flex-direction: column; gap: 20px; }
.title { display: flex; flex-direction: column; gap: 6px; }
h1 { font-size: 28px; font-weight: 700; letter-spacing: -0.01em; }
.title p, .once { color: var(--mut); }
.once { font-size: 13px; }
.ok { padding: 10px 12px; border-radius: var(--radius); background: var(--ok-bg); border: 1px solid var(--ok-line); color: var(--ok); font-size: 13px; }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
.alt { text-align: center; color: var(--mut); }
.links { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding-top: 18px; border-top: 1px solid var(--line); }
.link { background: none; border: 0; padding: 0; color: var(--acc); font: inherit; cursor: pointer; text-decoration: underline; }
</style>
