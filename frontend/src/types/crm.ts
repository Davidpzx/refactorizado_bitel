export interface Lead {
  id: number
  cliente_id: number | null
  agente_id: number
  tienda_id: string
  estado: 'NUEVO' | 'CONTACTADO' | 'INTERESADO' | 'CONVERTIDO' | 'PERDIDO'
  fuente: 'PRESENCIAL' | 'WHATSAPP' | 'REFERIDO' | 'LLAMADA'
  notas: string | null
  creado_en: string
  cliente?: {
    id: number
    dni_ruc: string
    nombre: string
    telefono: string | null
  } | null
}

export interface LeadFormData {
  cliente_id?: number | null
  agente_id: number
  tienda_id: string
  estado: Lead['estado']
  fuente: Lead['fuente']
  notas?: string
}

export interface InteraccionCrm {
  id: number
  lead_id: number | null
  cliente_id: number | null
  agente_id: number
  tipo: 'LLAMADA' | 'VISITA' | 'WHATSAPP' | 'VENTA' | 'POSTVENTA'
  detalle: string | null
  fecha: string
}

export interface PipelineEstado {
  estado: Lead['estado']
  total: number
}

export interface PipelineResponse {
  pipeline: PipelineEstado[]
  total_leads: number
  tasa_conversion: number
}

// ── CRM Dashboard analítico ───────────────────────────────────────────────────

export interface CrmDashboardFilters {
  tienda_id?: string
  desde?: string
  hasta?: string
  agente_id?: string
  categoria?: string
  canal?: string
}

export interface CrmFuenteStat {
  fuente: Lead['fuente']
  total: number
}

export interface CrmTendenciaDia {
  dia: string
  leads: number
  convertidos: number
}

export interface CrmRankingAgente {
  agente_id: number
  nombres: string
  tienda_id: string
  total_leads: number
  convertidos: number
  tasa: number
}

export interface CrmActividadItem {
  id: number
  tipo: InteraccionCrm['tipo']
  detalle: string | null
  fecha: string
  agente_nombres: string
  cliente_nombre: string | null
}

export interface CrmDashboardData {
  total_leads: number
  tasa_conversion: number
  convertidos: number
  perdidos: number
  pipeline: PipelineEstado[]
  por_fuente: CrmFuenteStat[]
  tendencia: CrmTendenciaDia[]
  ranking_agentes: CrmRankingAgente[]
  actividad_reciente: CrmActividadItem[]
}

// ── CRM: temperatura calculada (paridad legacy crm_clientes/crm_interacciones) ──

export type TemperaturaEtiqueta = 'Caliente' | 'Frío' | 'Upselling' | 'Neutro'

export interface Temperatura {
  etiqueta: TemperaturaEtiqueta
  bg: string
  text: string
  border: string
}

export interface CrmInteraccionTemp {
  id: number
  cliente_id: number
  dni: string
  nombres: string
  apellidos: string
  telefono: string | null
  tienda_codigo: string
  agente_nombre: string
  tipo_operacion: string
  producto_interes: string | null
  motivo_rechazo: string | null
  fecha_hora: string
  temperatura: Temperatura
}

export interface CrmTemperaturaFiltros {
  tienda_codigo?: string
  dni?: string
  desde?: string
  hasta?: string
  temperatura?: TemperaturaEtiqueta
  page?: number
  per_page?: number
}

export interface CrmTemperaturaResponse {
  data: CrmInteraccionTemp[]
  total: number
  current_page: number
  per_page: number
}
