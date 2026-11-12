import { createRouter, createWebHistory, type Router, type RouterHistory } from 'vue-router'
import { useSessionStore } from './stores/session'

declare module 'vue-router' {
  interface RouteMeta {
    /** Page accessible sans être connecté. */
    public?: boolean
  }
}

// Un identifiant de connexion est un UUID : toute autre forme n'ouvre pas l'espace de travail.
const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'

export function createAppRouter(history: RouterHistory): Router {
  const router = createRouter({
    history,
    routes: [
      { path: '/', redirect: { name: 'connections' } },
      { path: '/login', name: 'login', component: () => import('./views/LoginView.vue'), meta: { public: true } },
      { path: '/register', name: 'register', component: () => import('./views/RegisterView.vue'), meta: { public: true } },
      { path: '/recover', name: 'recover', component: () => import('./views/RecoverView.vue'), meta: { public: true } },
      // L'accueil est la liste des connexions ; l'ancien nom reste valable pour ne pas casser les liens existants.
      { path: '/workspace', name: 'workspace-home', redirect: { name: 'connections' } },
      { path: '/connections', name: 'connections', component: () => import('./views/ConnectionsView.vue') },
      { path: '/connections/new', name: 'connection-new', component: () => import('./views/ConnectionFormView.vue') },
      { path: `/connections/:id(${UUID})/edit`, name: 'connection-edit', component: () => import('./views/ConnectionFormView.vue'), props: true },
      {
        path: `/connections/:id(${UUID})`,
        component: () => import('./views/WorkspaceView.vue'),
        props: true,
        children: [
          { path: '', name: 'workspace', component: () => import('./views/QueryPane.vue') },
          { path: 'accounts', name: 'accounts', component: () => import('./views/AccountsPane.vue'), props: true },
          { path: 'activity', name: 'server-activity', component: () => import('./views/ServerActivityPane.vue'), props: true },
          { path: 'audit', name: 'audit', component: () => import('./views/AuditPane.vue'), props: true },
          { path: 'diagram', name: 'diagram', component: () => import('./views/DiagramPane.vue'), props: true },
          { path: 'key', name: 'key', component: () => import('./views/KeyPane.vue'), props: (route) => ({ id: route.params.id, name: route.query.name }) },
          { path: 'data/:name(.+)', name: 'table-data', component: () => import('./views/TableDataPane.vue'), props: true },
          { path: 'tables/:name(.+)', name: 'table', component: () => import('./views/TablePane.vue'), props: true },
        ],
      },
      { path: '/security', name: 'security', component: () => import('./views/SecurityView.vue') },
      { path: '/security/activity', name: 'activity', component: () => import('./views/ActivityView.vue') },
      { path: '/security/invitations', name: 'invitations', component: () => import('./views/InvitationsView.vue') },
      { path: '/security/tokens', name: 'tokens', component: () => import('./views/TokensView.vue') },
      { path: '/:pathMatch(.*)*', redirect: { name: 'connections' } },
    ],
  })

  router.beforeEach(async (to) => {
    const session = useSessionStore()
    await session.load()

    if (session.user === null && to.meta.public !== true) {
      return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
    }
    if (session.user !== null && to.meta.public === true) {
      return { name: 'connections' }
    }

    return true
  })

  return router
}

export const router = createAppRouter(createWebHistory('/app/'))
