export interface Cliente {
  id: number
  dni_ruc: string
  nombre: string
  telefono: string | null
  correo?: string | null
  tipo_documento: 'DNI' | 'RUC' | 'CE' | 'PAS'
  creado_en: string
}

export interface ClienteFormData {
  dni_ruc: string
  nombre: string
  telefono?: string
  correo?: string
  tipo_documento?: 'DNI' | 'RUC' | 'CE' | 'PAS'
}

export interface ClienteParams {
  q?: string
  tipo?: string
  page?: number
  per_page?: number
}
