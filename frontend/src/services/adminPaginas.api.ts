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

export const adminPaginasApi = {
  preciosPendientes: (tienda?: string) =>
    api.get<PreciosPendientesResponse>('/v1/inventario/precios-pendientes', { params: tienda ? { tienda } : {} }).then((r) => r.data),

  guardarPrecios: (id: number, precios: { precio_costo: number; precio_minimo: number; precio_normal: number }) =>
    api.put(`/v1/inventario/${id}`, precios).then((r) => r.data),

  fotosPendientes: () =>
    api.get<FotosPendientesResponse>('/v1/asistencias/fotos-pendientes').then((r) => r.data),

  photoAction: (id: number, accion: 'aprobar' | 'rechazar') =>
    api.post(`/v1/asistencias/${id}/photo-action`, { accion }).then((r) => r.data),
}
