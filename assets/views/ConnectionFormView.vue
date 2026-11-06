<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { messageFor } from '../api/errors'
import { DEFAULT_PORTS, ENGINE_LABELS, type ConnectionInput, type Engine } from '../api/types'
import AppButton from '../components/AppButton.vue'
import AppHeader from '../components/AppHeader.vue'
import FormField from '../components/FormField.vue'
import SecretNotice from '../components/SecretNotice.vue'
import { useConnectionsStore } from '../stores/connections'
import { formatDuration } from '../utils/format'

const props = defineProps<{ id?: string }>()
const store = useConnectionsStore()
const router = useRouter()

const editing = computed(() => props.id !== undefined)
const form = reactive({ engine: 'postgresql' as Engine, name: '', host: '', port: '', database: '', username: '', password: '' })
const original = ref<string | null>(null)
const error = ref<string | null>(null)
const testOk = ref<string | null>(null)
const busy = ref(false)
const testing = ref(false)
const loadFailed = ref(false)

const engines = Object.keys(ENGINE_LABELS) as Engine[]
const portPlaceholder = computed(() => String(DEFAULT_PORTS[form.engine]))
// Redis n'a ni base nommée ni schéma : la « base » est un numéro, l'identifiant un utilisateur ACL (« default » sans ACL).
const databaseLabel = computed(() => (form.engine === 'redis' ? 'Numéro de base (facultatif)' : 'Base de données (facultatif)'))
const databasePlaceholder = computed(() => (form.engine === 'redis' ? '0' : undefined))
const usernamePlaceholder = computed(() => (form.engine === 'redis' ? 'default' : undefined))

/** Empreinte des paramètres de connexion (hors nom), pour savoir si le formulaire diffère de ce qui est enregistré. */
function signature(): string {
  return JSON.stringify([form.engine, form.host, form.port, form.database, form.username])
}

onMounted(async () => {
  if (props.id === undefined) {
    return
  }
  try {
    const { connection, parameters } = await store.fetch(props.id)
    form.engine = connection.engine
    form.name = connection.name
    form.host = parameters.host
    form.port = String(parameters.port)
    form.database = parameters.database
    form.username = parameters.username
    original.value = signature()
  } catch (failure) {
    loadFailed.value = true
    error.value = messageFor(failure)
  }
})

function readPort(): number | undefined | null {
  if (form.port.trim() === '') {
    return undefined
  }
  const port = Number(form.port)
  return Number.isInteger(port) && port >= 1 && port <= 65535 ? port : null
}

/** null si le formulaire est invalide (l'erreur est alors affichée). */
function toInput(): ConnectionInput | null {
  const port = readPort()
  if (port === null) {
    error.value = 'Le port doit être un nombre entre 1 et 65535.'
    return null
  }
  const input: ConnectionInput = { name: form.name, engine: form.engine, host: form.host, database: form.database, username: form.username }
  if (port !== undefined) {
    input.port = port
  }
  // À la modification, un mot de passe vide conserve celui qui est enregistré.
  if (form.password !== '' || !editing.value) {
    input.password = form.password
  }

  return input
}

async function save(): Promise<void> {
  error.value = null
  testOk.value = null
  const input = toInput()
  if (input === null) {
    return
  }
  busy.value = true
  try {
    if (props.id === undefined) {
      await store.create(input)
    } else {
      await store.update(props.id, input)
    }
    await router.push({ name: 'connections' })
  } catch (failure) {
    error.value = messageFor(failure)
  } finally {
    busy.value = false
  }
}

async function test(): Promise<void> {
  error.value = null
  testOk.value = null
  const unchanged = editing.value && form.password === '' && signature() === original.value
  if (editing.value && form.password === '' && !unchanged) {
    error.value = 'Saisissez le mot de passe de la base pour tester de nouveaux paramètres.'
    return
  }
  const input = toInput()
  if (input === null) {
    return
  }
  testing.value = true
  try {
    const duration = unchanged && props.id !== undefined ? await store.testSaved(props.id) : await store.probe(input)
    testOk.value = `Connexion réussie en ${formatDuration(duration)}`
  } catch (failure) {
    error.value = messageFor(failure)
  } finally {
    testing.value = false
  }
}
</script>

<template>
  <AppHeader />
  <main class="page">
    <nav aria-label="Fil d’Ariane" class="crumbs"><RouterLink :to="{ name: 'connections' }">Mes connexions</RouterLink> / {{ editing ? 'Modifier' : 'Nouvelle connexion' }}</nav>
    <div class="title">
      <h1>{{ editing ? 'Modifier la connexion' : 'Nouvelle connexion' }}</h1>
      <p>Déclarez une base existante. TerrilDB s’y connecte, il ne la remplace pas.</p>
    </div>

    <p v-if="loadFailed" class="error" role="alert">{{ error }}</p>
    <form v-else @submit.prevent="save">
      <fieldset class="engines">
        <legend>Moteur</legend>
        <div class="choices">
          <label v-for="engine in engines" :key="engine" class="engine" :class="{ selected: form.engine === engine }">
            <input v-model="form.engine" type="radio" name="engine" :value="engine">
            <span>{{ ENGINE_LABELS[engine] }}</span>
          </label>
        </div>
      </fieldset>

      <FormField id="name" v-model="form.name" label="Nom de la connexion" hint="Visible dans votre liste. Deux connexions ne peuvent pas porter le même nom." required />
      <div class="pair host">
        <FormField id="host" v-model="form.host" label="Hôte" mono required />
        <FormField id="port" v-model="form.port" label="Port" mono inputmode="numeric" :placeholder="portPlaceholder" />
      </div>
      <div class="pair">
        <FormField id="database" v-model="form.database" :label="databaseLabel" :placeholder="databasePlaceholder" mono />
        <FormField id="username" v-model="form.username" label="Identifiant" :placeholder="usernamePlaceholder" mono autocomplete="off" required />
      </div>
      <FormField
        id="password"
        v-model="form.password"
        label="Mot de passe de la base"
        type="password"
        mono
        autocomplete="new-password"
        :placeholder="editing ? 'Laisser vide pour conserver le mot de passe enregistré' : undefined"
        hint="Un compte en lecture seule suffit pour parcourir et interroger."
      />

      <SecretNotice>L’hôte, le port, la base, l’identifiant et le mot de passe sont chiffrés ensemble avec votre clé avant d’être enregistrés. Seuls le nom et le moteur restent lisibles.</SecretNotice>

      <p v-if="testOk" class="ok" role="status">{{ testOk }}</p>
      <p v-if="error" class="error" role="alert">{{ error }}</p>

      <div class="footer">
        <RouterLink :to="{ name: 'connections' }" class="cancel">Annuler</RouterLink>
        <div class="buttons">
          <AppButton class="test" :busy="testing" @click="test">Tester la connexion</AppButton>
          <AppButton type="submit" variant="primary" :busy="busy">Enregistrer</AppButton>
        </div>
      </div>
    </form>
  </main>
</template>

<style scoped>
.page { max-width: 720px; margin: 0 auto; padding: 36px 24px 64px; display: flex; flex-direction: column; gap: 24px; }
.crumbs { color: var(--mut); font-size: 13px; }
.title { display: flex; flex-direction: column; gap: 6px; }
h1 { font-size: 30px; font-weight: 700; letter-spacing: -0.01em; }
.title p { color: var(--mut); }
form { display: flex; flex-direction: column; gap: 22px; padding: 28px; border-radius: 8px; border: 1px solid var(--line); background: var(--s1); }
fieldset { border: 0; margin: 0; padding: 0; min-width: 0; }
legend { font-size: 12px; font-weight: 500; color: var(--mut); letter-spacing: 0.02em; padding: 0; margin-bottom: 6px; }
.choices { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
.engine { display: flex; align-items: center; gap: 10px; padding: 14px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s1); cursor: pointer; }
.engine.selected { border-color: var(--acc); background: var(--acc-bg); }
.engine input { accent-color: var(--acc); margin: 0; }
.pair { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }
.pair.host { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); }
@media (max-width: 520px) { .pair.host { grid-template-columns: 1fr; } }
.ok { display: flex; padding: 12px 14px; border-radius: var(--radius); background: var(--ok-bg); border: 1px solid var(--ok-line); color: var(--ok); }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
.footer { display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; align-items: center; padding-top: 20px; border-top: 1px solid var(--line); }
.buttons { display: flex; flex-wrap: wrap; gap: 10px; }
.cancel { color: var(--mut); }
</style>
