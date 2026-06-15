export interface Usuario {
  id: number
  nombre: string
  email: string
  rol: string
  tienda_id: string
  agente_id: number | null
  activo?: boolean
}

export interface LoginCredentials {
  email: string
  password: string
}

export interface AuthResponse {
  token: string
  usuario: Usuario
}
