<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useConnectionsStore } from '../stores/connections'
import { useSessionStore } from '../stores/session'
import { useTokensStore } from '../stores/tokens'
import { useWorkspaceStore } from '../stores/workspace'
import AppButton from './AppButton.vue'
import BrandMark from './BrandMark.vue'
import LockIcon from './LockIcon.vue'

const session = useSessionStore()
const router = useRouter()

async function logout(): Promise<void> {
  await session.logout()
  useConnectionsStore().reset()
  useTokensStore().reset()
  useWorkspaceStore().close()
  await router.push({ name: 'login' })
}
</script>

<template>
  <header class="top">
    <div class="left">
      <RouterLink :to="{ name: 'connections' }" class="home" aria-label="Mes connexions"><BrandMark :size="24" /></RouterLink>
      <slot name="left" />
    </div>
    <div class="right">
      <span class="chip"><LockIcon :size="12" />Session déverrouillée</span>
      <RouterLink :to="{ name: 'security' }" class="user">
        <span class="avatar" aria-hidden="true">{{ session.user?.username.slice(0, 1).toUpperCase() }}</span>
        <span>{{ session.user?.username }}</span>
      </RouterLink>
      <AppButton small variant="ghost" @click="logout">Déconnexion</AppButton>
    </div>
  </header>
</template>

<style scoped>
.top { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; min-height: 56px; padding: 8px 24px; border-bottom: 1px solid var(--line); background: var(--s1); }
.left, .right { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.home { color: var(--fg); text-decoration: none; }
.chip { display: inline-flex; align-items: center; gap: 6px; height: 24px; padding: 0 10px; border-radius: 12px; border: 1px solid var(--acc-line); background: var(--acc-bg); color: var(--acc); font-size: 12px; }
.user { display: flex; align-items: center; gap: 10px; color: var(--fg); text-decoration: none; }
.avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--s3); border: 1px solid var(--line); display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; }
</style>
