import { api } from './api'
import type { PaginatedResponse } from '../types/pagination'

// ── Types ─────────────────────────────────────────────────────────────────────

export type ComprobanteColaEstado = 'PENDIENTE' | 'ENVIADO' | 'ACEPTADO' | 'RECHAZADO' | 'ANULADO' | 'ERROR'
export type ComprobanteColaTipo = 'BOLETA' | 'FACTURA' | 'NOTA_CREDITO'

export interface ComprobanteCola {
  id: number
  venta_id: number | null
  reporte_id: number | null
  ticket_id: number | null
  tienda_id: string | null
  agente_id: number | null
  comprobante_afectado_id: number | null
  tipo_comprobante: ComprobanteColaTipo
  tipo_doc_cliente: string | null
  num_doc_cliente: string | null
  razon_social: string | null
  direccion_cliente: string | null
  email_cliente: string | null
  moneda: string
  total: string | number
  estado: ComprobanteColaEstado
  intentos: number
  max_intentos: number
  proximo_intento_at: string | null
  ultimo_error: string | null
  serie: string | null
  correlativo: number | null
  numero_completo: string | null
  api_doc_id: number | null
  sunat_ticket: string | null
  cdr_estado: string | null
  anulado_por_usuario_id: number | null
  anulado_en: string | null
  anulado_motivo: string | null
  creado_en: string
  actualizado_en: string
}

export interface ComprobantesColaFiltros {
  estado?: string
  tipo_comprobante?: string
  tienda_id?: string
  desde?: string
  hasta?: string
  page?: number
  per_page?: number
}

interface IdentidadRespuesta {
  cola_id: number
  estado: ComprobanteColaEstado
  api_doc_id: number | null
  serie: string | null
  correlativo: number | null
  numero_completo: string | null
}

export interface EmitirAhoraResponse extends IdentidadRespuesta {
  ok: boolean
  ya_emitido?: boolean
  encolado?: boolean
  msg?: string
  detalle?: string
}

export interface NotaCreditoResponse extends IdentidadRespuesta {
  ok: boolean
  ya_existe?: boolean
}

export interface AnularResponse extends IdentidadRespuesta {
  ok: boolean
  summary_id?: string | null
  ticket?: string | null
}

export interface LinkCpeResponse extends IdentidadRespuesta {
  url: string
  exp: number
  whatsapp_url: string
}

// ── API ───────────────────────────────────────────────────────────────────────

export const comprobantesColaApi = {
  index: (filtros: ComprobantesColaFiltros) =>
    api.get<PaginatedResponse<ComprobanteCola>>('/v1/comprobantes-cola', { params: filtros }).then(r => r.data),

  /** "Reintentar ahora": mismo endpoint que emitir, pasando la fila ya encolada. */
  reintentar: (colaId: number) =>
    api.post<EmitirAhoraResponse>('/v1/comprobantes-cola/emitir-ahora', { cola_id: colaId }).then(r => r.data),

  notaCredito: (id: number, payload: { cod_motivo?: string; des_motivo?: string } = {}) =>
    api.post<NotaCreditoResponse>(`/v1/comprobantes-cola/${id}/nota-credito`, payload).then(r => r.data),

  anular: (id: number, motivo?: string) =>
    api.post<AnularResponse>(`/v1/comprobantes-cola/${id}/anular`, { motivo }).then(r => r.data),

  link: (id: number) =>
    api.post<LinkCpeResponse>(`/v1/comprobantes-cola/${id}/link`).then(r => r.data),

  descargar: (id: number, tipo: 'pdf' | 'xml' | 'cdr') =>
    api.get(`/v1/comprobantes-cola/${id}/descargar/${tipo}`, { responseType: 'blob' }),
}
