import { api } from './api'

/* ── Tipos ─────────────────────────────────────────────────────────────────── */

export interface CredencialTienda {
  codigo: string
  nombre: string
  bitel_username: string | null
  sync_intervalo_min: number | null
  activo: number | null
  last_config_fetch: string | null
  last_sync_at: string | null
  configurada: number
  estado_agente: { estado: 'EN_LINEA' | 'CAIDO' | 'SIN_CONTACTO' | 'DESACTIVADO'; minutos: number | null }
}

export interface CategoriaCuadre {
  etiqueta: string
  erp: number
  bitel: number
  diff: number
  ops: number
}

export interface CuadreCategorial {
  categorias: Record<string, CategoriaCuadre>
  total_erp: number
  total_bitel: number
  total_diff: number
  ok: boolean
  estado: 'CUADRADO' | 'DESCUADRADO'
  tiendas?: string[]
  es_grupo?: boolean
  es_apoyo_destino?: boolean
  apoyo_origen?: string
  recarga_bipay_erp: number
}

export interface PanelCuadreBitel {
  fecha: string
  tiendas: { codigo: string; nombre: string }[]
  cuadres: Record<string, CuadreCategorial>
  anomalias: { tienda: string; total_bitel: number; total_erp: number }[]
  apoyos: { tienda_origen: string; tienda_destino: string }[]
  reportes_enviados: Record<string, number>
  queue: { estado: string; movs_procesados: number; fecha_solicitud: string; fecha_procesado: string | null; mensaje: string | null } | null
}

export interface CuadreGlobal {
  fecha: string
  declarado_total: number
  scrapeado_total: number
  diferencia: number
  ok: boolean
  estado: string
  tiendas: { tienda: string; declarado: number; scrapeado: number; cantidad: number; diferencia: number }[]
}

export interface MovimientosDia {
  fecha: string
  tiendas: Record<string, {
    resumen: Record<string, number>
    agentes: Record<string, { cantidad: number; total: number }>
    registros: { fecha_hora: string; agente: string; tipo: string; descripcion: string; monto: number }[]
  }>
  agentes_por_tienda: Record<string, string[]>
}

export interface CierreAuditoria {
  id: number
  tienda_codigo: string
  tienda_nombre: string | null
  fecha: string
  saldo_bipay_real: number
  ventas_sistema: number
  diferencia: number
  estado: 'CUADRADO' | 'DESCUADRADO' | 'AJUSTADO'
  observacion_ajuste: string | null
}

export interface DetallesAuditoria {
  auditoria: CierreAuditoria
  scraper_logs: { fecha_hora: string; tipo_operacion: string; descripcion: string; monto: number; codigo_personal: string | null }[]
  erp_logs: { id: number; usuario: string; agente: string | null; bipay: number; recarga_bipay: number; retiro_bipay: number; total_calculado: number; creado_en: string }[]
}

export interface MorosidadResponse {
  periodo: string
  solicitud: { estado: string; total_lineas: number; fecha_solicitud: string; fecha_procesado: string | null } | null
  lineas: { numero_linea: string; cliente: string | null; cod_tienda: string | null; plan: string | null; estado_linea: string; dias_mora: number; monto_deuda: number; es_churn: number }[]
  clientes_estado: { total: number; churn: number; suspendidos: number; deuda_total: number } | null
}

export interface WebhookConfig {
  bipay_webhook_url?: string
  bipay_webhook_tipo?: string
  bipay_webhook_umbral?: string
}

/* ── API ───────────────────────────────────────────────────────────────────── */

export const integradorApi = {
  credenciales: () =>
    api.get<{ data: CredencialTienda[] }>('/v1/integrador/credenciales').then((r) => r.data.data),
  guardarCredenciales: (payload: { tienda_codigo: string; bitel_username: string; bitel_password?: string; sync_intervalo_min?: number }) =>
    api.post<{ ok: boolean; message: string; token?: string }>('/v1/integrador/credenciales', payload).then((r) => r.data),
  regenerarToken: (codigo: string) =>
    api.post<{ ok: boolean; message: string; token?: string }>(`/v1/integrador/credenciales/${codigo}/regenerar-token`).then((r) => r.data),
  toggleActivo: (codigo: string) =>
    api.post(`/v1/integrador/credenciales/${codigo}/toggle`).then((r) => r.data),
  urlDescargaAgente: (tienda: string, token: string) =>
    `${api.defaults.baseURL}/v1/integrador/descargar-agente?tienda=${encodeURIComponent(tienda)}&token=${encodeURIComponent(token)}`,
  solicitarExtraccion: (periodo: string) =>
    api.post('/v1/integrador/solicitar-extraccion', { periodo }).then((r) => r.data),
  solicitarBitelHistorico: (fecha: string) =>
    api.post('/v1/integrador/solicitar-bitel-historico', { fecha }).then((r) => r.data),
  morosidad: (periodo: string) =>
    api.get<MorosidadResponse>('/v1/integrador/morosidad', { params: { periodo } }).then((r) => r.data),
}

export const cuadreBitelApi = {
  panel: (fecha: string) =>
    api.get<PanelCuadreBitel>('/v1/cuadre-bitel/panel', { params: { fecha } }).then((r) => r.data),
  rango: (fecha_ini: string, fecha_fin: string, tiendas?: string[]) =>
    api.get('/v1/cuadre-bitel/rango', { params: { fecha_ini, fecha_fin, tiendas } }).then((r) => r.data),
  global: (fecha: string) =>
    api.get<CuadreGlobal>('/v1/cuadre-bitel/global', { params: { fecha } }).then((r) => r.data),
  movimientosDia: (fecha: string, agentes?: string) =>
    api.get<MovimientosDia>('/v1/cuadre-bitel/movimientos-dia', { params: { fecha, agentes } }).then((r) => r.data),
  confirmarApoyo: (fecha: string, tienda_origen: string, tienda_destino: string) =>
    api.post('/v1/cuadre-bitel/apoyos', { fecha, tienda_origen, tienda_destino }).then((r) => r.data),
  eliminarApoyo: (fecha: string, tienda_origen: string, tienda_destino: string) =>
    api.delete('/v1/cuadre-bitel/apoyos', { data: { fecha, tienda_origen, tienda_destino } }).then((r) => r.data),
}

export const auditoriaBipayApi = {
  index: (fecha: string) =>
    api.get<{ fecha: string; cierres: CierreAuditoria[] }>('/v1/auditoria-bipay', { params: { fecha } }).then((r) => r.data),
  ejecutarCruce: (fecha: string, tienda?: string) =>
    api.post('/v1/auditoria-bipay/cruce', { fecha, tienda }).then((r) => r.data),
  detalles: (id: number) =>
    api.get<DetallesAuditoria>(`/v1/auditoria-bipay/${id}/detalles`).then((r) => r.data),
  ajustar: (id: number, observacion: string) =>
    api.post(`/v1/auditoria-bipay/${id}/ajustar`, { observacion }).then((r) => r.data),
  webhookConfig: () =>
    api.get<WebhookConfig>('/v1/auditoria-bipay/webhook').then((r) => r.data),
  guardarWebhook: (config: WebhookConfig) =>
    api.put('/v1/auditoria-bipay/webhook', config).then((r) => r.data),
}

export const clienteCrmApi = {
  buscar: (dni: string) =>
    api.get<{ ok: boolean; nombres: string; apellidos: string; telefono: string | null; operadora: string }>(`/v1/clientes-crm/${dni}`).then((r) => r.data),
  guardar: (payload: {
    dni: string; nombres: string; apellidos: string; telefono?: string
    operacion: string; producto_interes?: string; motivo_rechazo?: string
    operadora?: string; fuente?: 'RENIEC_API' | 'MANUAL_CON_FALLBACK'; agente?: string
  }) => api.post('/v1/clientes-crm', payload).then((r) => r.data),
}
