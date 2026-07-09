import { api } from './api'

export interface FacturacionConfig {
  id: number
  tienda_id: string | null
  company_id: number
  branch_id: number
  base_url: string | null
  ruc: string | null
  razon_social_emisor: string | null
  emisor_ubigeo: string | null
  emisor_distrito: string | null
  emisor_provincia: string | null
  emisor_departamento: string | null
  modo: 'beta' | 'produccion'
  serie_boleta: string | null
  serie_factura: string | null
  serie_nota_credito: string | null
  igv_porcentaje: string | number
  activo: boolean
  tiene_api_token: boolean
  es_global: boolean
  esta_operativa: boolean
  creado_en: string
  actualizado_en: string
}

export interface FacturacionConfigPayload {
  tienda_id?: string | null
  company_id?: number
  branch_id?: number
  base_url?: string | null
  api_token?: string | null
  ruc?: string | null
  razon_social_emisor?: string | null
  emisor_ubigeo?: string | null
  emisor_distrito?: string | null
  emisor_provincia?: string | null
  emisor_departamento?: string | null
  modo?: 'beta' | 'produccion'
  serie_boleta?: string | null
  serie_factura?: string | null
  serie_nota_credito?: string | null
  igv_porcentaje?: number
  activo?: boolean
}

export interface ConfigurarSunatResponse {
  ok: boolean
  msg: string
  certificado_path?: string
  config?: FacturacionConfig
}

export const facturacionConfigApi = {
  index: () =>
    api.get<{ data: FacturacionConfig[] }>('/v1/facturacion-config').then((r) => r.data.data),
  store: (payload: FacturacionConfigPayload) =>
    api.post<FacturacionConfig>('/v1/facturacion-config', payload).then((r) => r.data),
  update: (id: number, payload: FacturacionConfigPayload) =>
    api.put<FacturacionConfig>(`/v1/facturacion-config/${id}`, payload).then((r) => r.data),
  destroy: (id: number) =>
    api.delete(`/v1/facturacion-config/${id}`).then((r) => r.data),
  configureSunat: (formData: FormData) =>
    api
      .post<ConfigurarSunatResponse>('/v1/facturacion-config/configure-sunat', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      .then((r) => r.data),
}
