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
