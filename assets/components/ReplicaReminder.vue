<script setup lang="ts">
import { ref, watch } from 'vue'
import { messageFor } from '../api/errors'
import { enableReplicaReading, fetchReplicaReminder, postponeReplicaReminder } from '../api/replica'

const props = defineProps<{ id: string }>()
const emit = defineEmits<{ enabled: [] }>()

const available = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)
let current = 0

// Une fois par semaine au plus, le serveur cherche un réplica : cette page ne fait que demander s'il en est apparu un.
async function look(): Promise<void> {
  const token = ++current
  available.value = false
  error.value = null
  try {
    const found = await fetchReplicaReminder(props.id)
    if (token === current) {
      available.value = found
    }
  } catch {
    // Le rappel est facultatif : un serveur injoignable n'a pas à gêner la page.
  }
}

async function enable(): Promise<void> {
  busy.value = true
  error.value = null
  try {
    await enableReplicaReading(props.id)
    available.value = false
    emit('enabled')
  } catch (failure) {
    error.value = messageFor(failure)
  } finally {
    busy.value = false
  }
}

async function later(): Promise<void> {
  busy.value = true
  error.value = null
  try {
    await postponeReplicaReminder(props.id)
    available.value = false
  } catch (failure) {
    error.value = messageFor(failure)
  } finally {
    busy.value = false
  }
}

watch(() => props.id, look, { immediate: true })
</script>

<template>
  <div v-if="available" class="reminder" role="status">
    <p>
      Un réplica est maintenant disponible pour cette connexion. Lire dessus allège le primaire, mais les données peuvent avoir un léger retard
      sur ce qui vient d’être écrit.
    </p>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <div class="actions">
      <button type="button" class="enable" :disabled="busy" @click="enable">Lire sur le réplica</button>
      <button type="button" class="later" :disabled="busy" @click="later">Plus tard</button>
    </div>
  </div>
</template>

<style scoped>
.reminder { display: grid; gap: 8px; padding: 10px 12px; margin-bottom: 12px; border-radius: var(--radius); background: var(--s1); border: 1px solid var(--line); color: var(--fg); font-size: 13px; }
.reminder p { margin: 0; }
.error { color: var(--err, var(--fg)); }
.actions { display: flex; gap: 8px; }
button { padding: 4px 10px; border-radius: var(--radius); border: 1px solid var(--line-strong); background: var(--bg); color: var(--fg); font: inherit; cursor: pointer; }
button.enable { border-color: var(--action, var(--line-strong)); }
button:disabled { opacity: 0.6; cursor: default; }
</style>
