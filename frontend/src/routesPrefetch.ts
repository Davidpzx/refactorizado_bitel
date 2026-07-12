/**
 * Mapa ruta -> import() dinámico de la página, para "pre-descargar" el chunk
 * ANTES del click: al pasar el mouse sobre un ítem del sidebar (`AppLayout`)
 * o en idle justo después de montar la app (rutas más usadas). Usa los
 * mismos specifiers que los `lazy()` de `App.tsx` — Vite resuelve cada
 * specifier a un único chunk, así que llamar `import()` aquí no duplica la
 * descarga ni interfiere con el `React.lazy()` que ya la consume: cuando el
 * usuario hace click, el chunk ya está en caché del navegador/módulos y el
 * `Suspense` resuelve casi instantáneo.
 */
const routeImporters: Record<string, () => Promise<unknown>> = {
  '/':                        () => import('./pages/DashboardPage'),
  '/estadisticas':            () => import('./pages/estadisticas/EstadisticasPage'),
  '/crm':                     () => import('./pages/crm/CrmPage'),
  '/revisar-stock':           () => import('./pages/admin/RevisarStockPage'),
  '/historial':                () => import('./pages/historial/HistorialPage'),
  '/mi-historial':            () => import('./pages/reportes/MiHistorialPage'),
  '/tiendas':                 () => import('./pages/admin/TiendasPage'),
  '/usuarios':                () => import('./pages/admin/UsuariosPage'),
  '/agentes':                 () => import('./pages/agentes/AgentesPage'),
  '/asistencias':             () => import('./pages/asistencias/AsistenciasPage'),
  '/planilla':                () => import('./pages/planilla/PlanillaPage'),
  '/tickets':                 () => import('./pages/tickets/TicketsPage'),
  '/comisiones':              () => import('./pages/comisiones/ComisionesPage'),
  '/financieras':             () => import('./pages/admin/PanelFinancierasPage'),
  '/reporte-bcp':             () => import('./pages/bcp/ReporteBcpPage'),
  '/panel-bipay':             () => import('./pages/bipay/PanelBipayPage'),
  '/postpago':                () => import('./pages/postpago/PostpagoPage'),
  '/mapa-calor':              () => import('./pages/analytics/MapaCalorPage'),
  '/postular':                () => import('./pages/PostulacionPublicaPage'),
  '/inventario':              () => import('./pages/inventario/InventarioPage'),
  '/bitacora-stock':          () => import('./pages/inventario/BitacoraStockPage'),
  '/reportes':                () => import('./pages/reportes/ReportesPage'),
  '/reportes/nuevo':          () => import('./pages/reportes/NuevoReportePage'),
  '/asistencias/qr':          () => import('./pages/asistencias/QrDisplayPage'),
  '/configuracion':           () => import('./pages/admin/ConfiguracionPage'),
  '/configuracion/facturacion': () => import('./pages/admin/ConfiguracionFacturacionPage'),
  '/comprobantes':            () => import('./pages/comprobantes/ComprobantesPage'),
  '/integrador':              () => import('./pages/admin/IntegradorPage'),
}

/** Evita relanzar el mismo `import()` más de una vez por sesión de pestaña. */
const prefetched = new Set<string>()

/** Dispara la descarga del chunk de `path` si existe en el mapa y no se pidió antes. */
export function prefetchRoute(path: string) {
  const importer = routeImporters[path]
  if (!importer || prefetched.has(path)) return
  prefetched.add(path)
  importer().catch(() => prefetched.delete(path))
}

/**
 * Páginas más usadas del sistema (Dashboard, Reportes, Nuevo Reporte,
 * Asistencias, Inventario) — se precargan en idle tras el primer paint para
 * que la primera navegación del usuario a cualquiera de ellas sea instantánea.
 */
export const TOP_ROUTES = ['/', '/reportes', '/reportes/nuevo', '/asistencias', '/inventario']

export function prefetchTopRoutes() {
  TOP_ROUTES.forEach(prefetchRoute)
}
