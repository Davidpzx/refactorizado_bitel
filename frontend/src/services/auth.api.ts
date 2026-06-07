import { api } from './api'
import type { AuthResponse, LoginCredentials, Usuario } from '../types/auth'

export const authApi = {
  login: (credentials: LoginCredentials) =>
    api.post<AuthResponse>('/v1/auth/login', credentials).then((r) => r.data),

  me: () =>
    api.get<Usuario>('/v1/auth/me').then((r) => r.data),

  logout: () =>
    api.post<{ message: string }>('/v1/auth/logout').then((r) => r.data),
}
