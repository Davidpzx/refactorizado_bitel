export interface Ticket {
  id: number
  tienda_id: string
  agente_id: number | null
  vendedor: string
  descripcion: string
  monto: string
  cantidad: number
  efectivo: string | null
  yape: string | null
  bipay: string | null
  plin: string | null         // alias enviado al crear (payload)
  transferencia: string | null // nombre real en la BD (respuesta del backend)
  nombre_cliente: string | null
  dni_cliente: string | null
  telefono: string | null
  creado_en: string  // BD: tickets_emitidos usa creado_en (no created_at/updated_at)
}

export interface TicketFilters {
  desde?: string
  hasta?: string
  tienda_id?: string
  agente_id?: number
  q?: string
  dni_cliente?: string
  forma_pago?: string
  page?: number
  per_page?: number
}

export interface TicketPayload {
  tienda_id: string
  agente_id?: number
  vendedor: string
  descripcion: string
  monto: number
  cantidad: number
  efectivo?: number
  yape?: number
  bipay?: number
  plin?: number
  nombre_cliente?: string
  dni_cliente?: string
  telefono?: string
}

export interface TicketUpdatePayload {
  nombre_cliente?: string
  telefono?: string
  efectivo?: number
  yape?: number
  bipay?: number
  plin?: number
  estado?: string
}
