<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { messageFor } from '../api/errors'
import AppButton from '../components/AppButton.vue'
import AuthLayout from '../components/AuthLayout.vue'
import FormField from '../components/FormField.vue'
import SecretNotice from '../components/SecretNotice.vue'
import { useSessionStore } from '../stores/session'

const session = useSessionStore()
const router = useRouter()

const login = ref('')
const phrase = ref('')
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
    await session.recover(login.value, phrase.value, password.value)
    await router.push({ name: 'login', query: { recovered: '1' } })
  } catch (failure) {
    error.value = messageFor(failure)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthLayout headline="Une clé de secours, pas un passe-partout." text="La phrase de récupération que vous avez notée permet de choisir un nouveau mot de passe sans perdre vos connexions enregistrées." facts="PHRASE DE 32 CARACTÈRES">
    <form @submit.prevent="submit">
      <div class="title">
        <h1>Mot de passe oublié</h1>
        <p>Saisissez votre identifiant, votre phrase de récupération et un nouveau mot de passe.</p>
      </div>
      <FormField id="login" v-model="login" label="E-mail ou nom d’utilisateur" autocomplete="username" required />
      <FormField id="phrase" v-model="phrase" label="Phrase de récupération" mono autocomplete="off" hint="Huit groupes de quatre caractères ; les tirets et la casse n’ont pas d’importance." required />
      <FormField id="password" v-model="password" label="Nouveau mot de passe" type="password" autocomplete="new-password" hint="12 caractères au minimum." required />
      <FormField id="confirmation" v-model="confirmation" label="Confirmation" type="password" autocomplete="new-password" required />
      <SecretNotice>La phrase ne sert qu’une fois. Votre double authentification, si elle est activée, reste exigée à la prochaine connexion. Sans phrase notée à l’avance, un mot de passe perdu ne se récupère pas.</SecretNotice>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <AppButton type="submit" variant="primary" block :busy="busy">Choisir ce mot de passe</AppButton>
      <p class="alt"><RouterLink :to="{ name: 'login' }">Retour à la connexion</RouterLink></p>
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
