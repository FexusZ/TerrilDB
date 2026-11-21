<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { messageFor } from '../api/errors'
import { CONNECTION_COLORS, DEFAULT_PORTS, ENGINE_LABELS, type ConnectionColor, type ConnectionInput, type Engine, type JumpInput } from '../api/types'
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
const form = reactive({ engine: 'postgresql' as Engine, name: '', host: '', port: '', database: '', username: '', password: '', color: '' as ConnectionColor | '' })
const readReplica = ref(false)
const original = ref<string | null>(null)
const error = ref<string | null>(null)
const testOk = ref<string | null>(null)
const busy = ref(false)
const testing = ref(false)
const loadFailed = ref(false)

// Rebond SSH : `stored` = l'empreinte déjà enregistrée avec la connexion, `read` = celle que le serveur vient de lire et que
// l'utilisateur doit confirmer (`confirmed`) avant qu'on l'enregistre. Elle n'est jamais acceptée en silence.
const jump = reactive({ enabled: false, host: '', port: '22', username: '', privateKey: '', hasKey: false, stored: '', storedHost: '', storedPort: '', read: '', readType: '', confirmed: false })
const reading = ref(false)

const engines = Object.keys(ENGINE_LABELS) as Engine[]
// Le tunnel n'offre qu'un socket Unix : seuls les pilotes MySQL, MariaDB et PostgreSQL savent s'y connecter.
const canJump = computed(() => form.engine === 'postgresql' || form.engine === 'mysql' || form.engine === 'mariadb')
const jumpActive = computed(() => canJump.value && jump.enabled)
// Vitess se joint par le protocole MySQL : la cible `@replica` n'a de sens que pour ce moteur.
const canReplica = computed(() => form.engine === 'mysql')
// Une empreinte ne vaut que pour le bastion qui l'a donnée : si l'adresse change, on l'oublie.
const jumpMoved = computed(() => jump.host.trim() !== jump.storedHost || jump.port.trim() !== jump.storedPort)
const shownFingerprint = computed(() => (jump.read !== '' ? jump.read : jumpMoved.value ? '' : jump.stored))
const portPlaceholder = computed(() => String(DEFAULT_PORTS[form.engine]))
// Redis n'a ni base nommée ni schéma : la « base » est un numéro, l'identifiant un utilisateur ACL (« default » sans ACL).
const databaseLabel = computed(() => (form.engine === 'redis' ? 'Numéro de base (facultatif)' : 'Base de données (facultatif)'))
const databasePlaceholder = computed(() => (form.engine === 'redis' ? '0' : undefined))
const usernamePlaceholder = computed(() => (form.engine === 'redis' ? 'default' : undefined))

/** Empreinte des paramètres de connexion (hors nom), pour savoir si le formulaire diffère de ce qui est enregistré. */
function signature(): string {
  return JSON.stringify([form.engine, form.host, form.port, form.database, form.username, canReplica.value && readReplica.value, jumpActive.value, jump.host, jump.port, jump.username, jump.read])
}

watch(() => [jump.host, jump.port], () => {
  jump.read = ''
  jump.confirmed = false
})

onMounted(async () => {
  if (props.id === undefined) {
    return
  }
  try {
    const { connection, parameters } = await store.fetch(props.id)
    form.engine = connection.engine
    form.name = connection.name
    form.color = connection.color ?? ''
    form.host = parameters.host
    form.port = String(parameters.port)
    form.database = parameters.database
    form.username = parameters.username
    readReplica.value = parameters.readReplica === true
    if (parameters.transport !== undefined) {
      jump.enabled = true
      jump.host = parameters.transport.host
      jump.port = String(parameters.transport.port)
      jump.username = parameters.transport.username
      jump.hasKey = parameters.transport.hasPrivateKey
      jump.stored = parameters.transport.hostKeyFingerprint ?? ''
      jump.storedHost = jump.host
      jump.storedPort = jump.port
    }
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

function readJumpPort(): number | null {
  const port = jump.port.trim() === '' ? 22 : Number(jump.port)
  return Number.isInteger(port) && port >= 1 && port <= 65535 ? port : null
}

async function readHostKey(): Promise<void> {
  error.value = null
  const port = readJumpPort()
  if (port === null || jump.host.trim() === '') {
    error.value = 'Indiquez l’adresse et un port valide du bastion.'
    return
  }
  reading.value = true
  try {
    const key = await store.readHostKey(jump.host.trim(), port)
    jump.read = key.fingerprint
    jump.readType = key.keyType
    jump.confirmed = false
  } catch (failure) {
    jump.read = ''
    error.value = messageFor(failure)
  } finally {
    reading.value = false
  }
}

/** Le rebond à envoyer, `undefined` si rien à dire, ou `null` (déjà affiché) si le formulaire est invalide. */
function jumpInput(): JumpInput | null | undefined {
  if (!jumpActive.value) {
    // Rebond décoché sur une connexion qui en avait un : on le retire explicitement.
    return editing.value && jump.storedHost !== '' ? null : undefined
  }
  const port = readJumpPort()
  if (port === null || jump.host.trim() === '' || jump.username.trim() === '') {
    error.value = 'Le bastion demande une adresse, un port valide et un identifiant.'
    return null
  }
  if (jump.privateKey.trim() === '' && !(jump.hasKey && editing.value)) {
    error.value = 'Collez la clé privée (ed25519) du bastion.'
    return null
  }
  const input: JumpInput = { type: 'ssh', host: jump.host.trim(), port, username: jump.username.trim() }
  if (jump.privateKey.trim() !== '') {
    input.privateKey = jump.privateKey
  }
  if (jump.read !== '') {
    if (!jump.confirmed) {
      error.value = 'Confirmez l’empreinte de la clé du bastion avant d’enregistrer.'
      return null
    }
    input.hostKeyFingerprint = jump.read
  } else if (jumpMoved.value || jump.stored === '') {
    error.value = 'Lisez l’empreinte de la clé du bastion, vérifiez-la et confirmez-la.'
    return null
  }

  return input
}

/** null si le formulaire est invalide (l'erreur est alors affichée). */
function toInput(): ConnectionInput | null {
  const port = readPort()
  if (port === null) {
    error.value = 'Le port doit être un nombre entre 1 et 65535.'
    return null
  }
  const input: ConnectionInput = { name: form.name, engine: form.engine, host: form.host, database: form.database, username: form.username }
  if (form.color !== '') {
    input.color = form.color
  }
  if (port !== undefined) {
    input.port = port
  }
  // À la modification, un mot de passe vide conserve celui qui est enregistré.
  if (form.password !== '' || !editing.value) {
    input.password = form.password
  }
  if (canReplica.value && (readReplica.value || editing.value)) {
    input.readReplica = readReplica.value
  }
  const transport = jumpInput()
  if (transport === null && jumpActive.value) {
    return null
  }
  if (transport !== undefined) {
    input.transport = transport
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
  if (editing.value && jumpActive.value && jump.privateKey.trim() === '' && !unchanged) {
    error.value = 'Collez la clé privée du bastion pour tester de nouveaux paramètres.'
    return
  }
  const input = toInput()
  if (input === null) {
    return
  }
  testing.value = true
  try {
    const result = unchanged && props.id !== undefined ? { durationMs: await store.testSaved(props.id) } : await store.probe(input)
    testOk.value = `Connexion réussie en ${formatDuration(result.durationMs)}`
    if ('replica' in result && result.replica === 'available') {
      testOk.value += ' · un réplica en service peut recevoir les lectures'
    } else if ('replica' in result && result.replica === 'missing') {
      testOk.value += ' · Aucun réplica en service : la lecture sur réplica n’est pas possible pour l’instant'
    }
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

      <fieldset class="colors">
        <legend>Couleur (facultative)</legend>
        <div class="swatches">
          <label class="color none" :class="{ selected: form.color === '' }">
            <input v-model="form.color" type="radio" name="color" value="">
            <span>Aucune</span>
          </label>
          <label v-for="color in CONNECTION_COLORS" :key="color.value" class="color" :class="{ selected: form.color === color.value }" :title="color.label">
            <input v-model="form.color" type="radio" name="color" :value="color.value">
            <span class="swatch" :data-color="color.value" aria-hidden="true" />
            <span class="sr-only">{{ color.label }}</span>
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

      <label v-if="canReplica" class="check">
        <input id="read-replica" v-model="readReplica" type="checkbox">
        <span>Lire sur les réplicas (Vitess, <code>@replica</code>) : les lectures n'atteignent pas le primaire. Sans réplica disponible, la connexion échoue.</span>
      </label>

      <fieldset v-if="canJump" class="jump">
        <legend>Rebond SSH (facultatif)</legend>
        <label class="check">
          <input id="jump-enabled" v-model="jump.enabled" type="checkbox">
          <span>Joindre la base par un bastion SSH</span>
        </label>
        <template v-if="jump.enabled">
          <p class="hint">L’hôte et le port ci-dessus sont ceux de la base, vus depuis le bastion. Le bastion doit être déclaré par l’administrateur ; seule une clé ed25519 est acceptée.</p>
          <div class="pair host">
            <FormField id="jump-host" v-model="jump.host" label="Bastion : hôte" mono required />
            <FormField id="jump-port" v-model="jump.port" label="Port" mono inputmode="numeric" placeholder="22" />
          </div>
          <FormField id="jump-username" v-model="jump.username" label="Bastion : identifiant" mono autocomplete="off" required />
          <div class="field">
            <label for="jump-key">Clé privée</label>
            <textarea
              id="jump-key"
              v-model="jump.privateKey"
              class="mono"
              rows="5"
              spellcheck="false"
              autocomplete="off"
              :placeholder="editing && jump.hasKey ? 'Laisser vide pour conserver la clé enregistrée' : '-----BEGIN OPENSSH PRIVATE KEY-----'"
            />
          </div>
          <div class="hostkey">
            <AppButton class="read-host-key" :busy="reading" @click="readHostKey">Lire l’empreinte du bastion</AppButton>
            <p v-if="shownFingerprint !== ''" class="fingerprint">
              {{ jump.read !== '' ? `Empreinte lue (${jump.readType}) : ` : 'Empreinte confirmée : ' }}<code>{{ shownFingerprint }}</code>
            </p>
            <label v-if="jump.read !== ''" class="check">
              <input id="jump-confirm" v-model="jump.confirmed" type="checkbox">
              <span>Je confirme que cette empreinte est celle de mon bastion (à comparer avec celle que son administrateur vous a donnée).</span>
            </label>
          </div>
        </template>
      </fieldset>

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
h1 { font-size: 40px; line-height: 1.1; font-weight: 600; letter-spacing: -0.02em; }
.title p { color: var(--mut); }
form { display: flex; flex-direction: column; gap: 22px; padding: 28px; border-radius: 8px; border: 1px solid var(--line); background: var(--s1); }
fieldset { border: 0; margin: 0; padding: 0; min-width: 0; }
legend { font-size: 12px; font-weight: 500; color: var(--mut); letter-spacing: 0.02em; padding: 0; margin-bottom: 6px; }
.choices { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
.engine { display: flex; align-items: center; gap: 10px; padding: 14px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s1); cursor: pointer; }
.engine.selected { border-color: var(--acc); background: var(--acc-bg); }
.engine input { accent-color: var(--acc); margin: 0; }
.colors legend { margin-bottom: 8px; font-size: 13px; color: var(--mut); }
.swatches { display: flex; flex-wrap: wrap; gap: 8px; }
.color { display: inline-flex; align-items: center; justify-content: center; min-width: 32px; height: 32px; padding: 0 8px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s1); color: var(--mut); font-size: 12px; cursor: pointer; }
.color input { position: absolute; opacity: 0; pointer-events: none; }
.color.selected { border-color: var(--acc); box-shadow: 0 0 0 1px var(--acc); color: var(--fg); }
.color:focus-within { outline: 2px solid var(--acc); outline-offset: 1px; }
.swatch { width: 16px; height: 16px; border-radius: 4px; background: var(--mut); }
.swatch[data-color='red'] { background: var(--conn-red); }
.swatch[data-color='orange'] { background: var(--conn-orange); }
.swatch[data-color='yellow'] { background: var(--conn-yellow); }
.swatch[data-color='green'] { background: var(--conn-green); }
.swatch[data-color='teal'] { background: var(--conn-teal); }
.swatch[data-color='blue'] { background: var(--conn-blue); }
.swatch[data-color='violet'] { background: var(--conn-violet); }
.swatch[data-color='pink'] { background: var(--conn-pink); }
.jump { display: flex; flex-direction: column; gap: 14px; padding: 14px 16px; border-radius: var(--radius); border: 1px solid var(--line); }
.check { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; }
.check input { accent-color: var(--acc); margin-top: 3px; }
.hint { color: var(--mut); font-size: 13px; margin: 0; }
.field { display: flex; flex-direction: column; gap: 6px; }
.field label { font-size: 12px; font-weight: 500; color: var(--mut); }
textarea { padding: 10px 12px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--bg); color: var(--fg); resize: vertical; font-size: 12px; }
.hostkey { display: flex; flex-direction: column; align-items: flex-start; gap: 10px; }
.fingerprint { margin: 0; word-break: break-all; }
.fingerprint code { font-family: var(--mono, monospace); }
.pair { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }
.pair.host { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); }
@media (max-width: 520px) { .pair.host { grid-template-columns: 1fr; } }
.ok { display: flex; padding: 12px 14px; border-radius: var(--radius); background: var(--ok-bg); border: 1px solid var(--ok-line); color: var(--ok); }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
.footer { display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; align-items: center; padding-top: 20px; border-top: 1px solid var(--line); }
.buttons { display: flex; flex-wrap: wrap; gap: 10px; }
.cancel { color: var(--mut); }
</style>
