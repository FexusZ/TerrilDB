<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { messageFor } from '../api/errors'
import AppButton from '../components/AppButton.vue'
import AuthLayout from '../components/AuthLayout.vue'
import FormField from '../components/FormField.vue'
import SecretNotice from '../components/SecretNotice.vue'
import { useSessionStore } from '../stores/session'

const session = useSessionStore()
const router = useRouter()
const route = useRoute()
const invitation = typeof route.query.invitation === 'string' && route.query.invitation !== '' ? route.query.invitation : undefined

const email = ref('')
const username = ref('')
const password = ref('')
const confirmation = ref('')
const error = ref<string | null>(null)
const busy = ref(false)

async function submit(): Promise<void> {
  error.value = null
  if (password.value !== confirmation.value) {
    error.value = 'Les deux mots de passe ne sont pas identiques.'
    return
  }
  busy.value = true
  try {
    await session.register(email.value, username.value, password.value, invitation)
    await session.login(username.value, password.value)
    await router.push({ name: 'workspace-home' })
  } catch (failure) {
    error.value = messageFor(failure)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthLayout headline="Un compte, une clé." text="Votre mot de passe sert deux fois : à vous reconnaître, et à fabriquer la clé qui chiffre vos accès. Choisissez-le long, et gardez-le." facts="12 CARACTÈRES AU MINIMUM">
    <form @submit.prevent="submit">
      <div class="title">
        <h1>Créer un compte</h1>
        <p v-if="invitation">Vous avez reçu une invitation : choisissez vos identifiants.</p>
        <p v-else>Le premier compte d’une installation est le propriétaire ; les suivants s’ouvrent sur invitation.</p>
      </div>
      <FormField id="email" v-model="email" label="Adresse e-mail" type="email" autocomplete="email" inputmode="email" required />
      <FormField id="username" v-model="username" label="Nom d’utilisateur" autocomplete="username" hint="3 à 32 caractères : lettres minuscules, chiffres, point, tiret ou tiret bas." required />
      <FormField id="password" v-model="password" label="Mot de passe" type="password" autocomplete="new-password" hint="12 caractères au minimum." required />
      <FormField id="confirmation" v-model="confirmation" label="Confirmation" type="password" autocomplete="new-password" required />
      <SecretNotice>Si vous perdez ce mot de passe, vos connexions enregistrées ne pourront pas être récupérées : il n’existe pas de réinitialisation.</SecretNotice>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <AppButton type="submit" variant="primary" block :busy="busy">Créer le compte</AppButton>
      <p class="alt">Déjà un compte ? <RouterLink :to="{ name: 'login' }">Se connecter</RouterLink></p>
    </form>
  </AuthLayout>
</template>

<style scoped>
form { display: flex; flex-direction: column; gap: 20px; }
.title { display: flex; flex-direction: column; gap: 6px; }
h1 { font-size: 34px; line-height: 1.1; font-weight: 600; letter-spacing: -0.02em; }
.title p { color: var(--mut); }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
.alt { text-align: center; color: var(--mut); }
</style>
