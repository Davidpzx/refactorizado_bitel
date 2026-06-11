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
  plin: string | null
  nombre_cliente: string | null
  dni_cliente: string | null
  telefono: string | null
  created_at: string
  updated_at: string
}

export interface TicketFilters {
  desde?: string
  hasta?: string
  tienda_id?: string
  agente_id?: number
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
}
