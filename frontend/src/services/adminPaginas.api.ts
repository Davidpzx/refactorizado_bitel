import { api } from './api'

// ── Revisar Stock (precios pendientes) ──────────────────────────────────────────

export interface PrecioPendienteItem {
  id: number
  tienda_id: string
  producto_nombre: string
  tipo: string
  imei_serial: string | null
  cantidad: number
  precio_costo: number | string | null
  precio_minimo: number | string | null
  precio_normal: number | string | null
  fecha_registro: string | null
}

export interface PreciosPendientesResponse {
  data: PrecioPendienteItem[]
  total: number
  tiendas: string[]
}

// ── Revisar Fotos de asistencia ─────────────────────────────────────────────────

export interface FotoPendienteItem {
  id: number
  agente_id: number
  fecha: string
  hora_ingreso: string | null
  metodo_marcacion: string | null
  foto_marcacion: string
  nombres: string
  tienda_base: string | null
  lat_entrada?: number | null
  lng_entrada?: number | null
  accuracy_entrada?: number | null
  distancia_entrada?: number | null
}

export interface FotosPendientesResponse {
  data: FotoPendienteItem[]
  total: number
}

// ── Presencia en vivo (APP-04/07) ────────────────────────────────────────────────

export type EstadoPresencia = 'ok' | 'fuera_de_rango' | 'mock_gps' | 'sin_ping'

export interface PresenciaAgenteItem {
  agente_id: number
  dni: string
  nombre: string
  tienda: string | null
  hora_ingreso: string | null
  estado: EstadoPresencia
  ultimo_ping: string | null
  minutos_desde_ping: number | null
  distancia: number | null
  battery_pct: number | null
  incidencias_dia: number
}

export interface PresenciaResponse {
  data: PresenciaAgenteItem[]
  total: number
}

// ── Monitor de fraude de dispositivos ───────────────────────────────────────────

export interface AlertaFraudeItem {
  id: number
  fecha_hora: string
  nombre_agente: string | null
  dni_ingresado: string | null
  /** DNI del dueño real del celular; null cuando no se pudo identificar. */
  dni_duenio_hash: string | null
  tienda_intento: string | null
  /** APP-06: 'dispositivo' (log_fraude_dispositivo, comportamiento original) o 'ubicacion'. */
  fuente?: 'dispositivo' | 'ubicacion'
  /** Solo cuando fuente==='ubicacion': fuera_de_rango | mock_gps | sin_senal. */
  tipo_ubicacion?: 'fuera_de_rango' | 'mock_gps' | 'sin_senal' | null
}

export interface FraudeDispositivosResponse {
  data: AlertaFraudeItem[]
  total: number
}

export const adminPaginasApi = {
  preciosPendientes: (tienda?: string) =>
    api.get<PreciosPendientesResponse>('/v1/inventario/precios-pendientes', { params: tienda ? { tienda } : {} }).then((r) => r.data),

  preciosMatriz: (filtros: { tienda?: string; tipo?: string; q?: string } = {}) => {
    const params: Record<string, string> = {}
    if (filtros.tienda) params.tienda = filtros.tienda
    if (filtros.tipo) params.tipo = filtros.tipo
    if (filtros.q) params.q = filtros.q
    return api.get<PreciosPendientesResponse>('/v1/inventario/precios-matriz', { params }).then((r) => r.data)
  },

  guardarPrecios: (id: number, precios: { precio_costo: number; precio_minimo: number; precio_normal: number }) =>
    api.put(`/v1/inventario/${id}`, precios).then((r) => r.data),

  fotosPendientes: () =>
    api.get<FotosPendientesResponse>('/v1/asistencias/fotos-pendientes').then((r) => r.data),

  photoAction: (id: number, accion: 'aprobar' | 'rechazar') =>
    api.post(`/v1/asistencias/${id}/photo-action`, { accion }).then((r) => r.data),

  fraudeDispositivos: () =>
    api.get<FraudeDispositivosResponse>('/v1/asistencias/fraude-dispositivos').then((r) => r.data),

  presencia: () =>
    api.get<PresenciaResponse>('/v1/asistencias-admin/presencia').then((r) => r.data),
}
