export interface Usuario {
  id: number
  nombre: string
  email: string
  rol: string
  tienda_id: string
  agente_id: number | null
  activo?: boolean
  formato_ticket?: '58' | '80'
}

export interface LoginCredentials {
  email: string
  password: string
}

export interface AuthResponse {
  token: string
  usuario: Usuario
}
