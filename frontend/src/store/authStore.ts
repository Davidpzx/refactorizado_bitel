import { create } from 'zustand'
import type { Usuario } from '../types/auth'

interface AuthState {
  token: string | null
  usuario: Usuario | null
  isAuthenticated: boolean
  setAuth: (token: string, usuario: Usuario) => void
  setUsuario: (usuario: Usuario) => void
  logout: () => void
}

export const useAuthStore = create<AuthState>((set) => ({
  token: localStorage.getItem('auth_token'),
  usuario: null,
  isAuthenticated: !!localStorage.getItem('auth_token'),

  setAuth: (token, usuario) => {
    localStorage.setItem('auth_token', token)
    set({ token, usuario, isAuthenticated: true })
  },

  setUsuario: (usuario) => set({ usuario }),

  logout: () => {
    localStorage.removeItem('auth_token')
    set({ token: null, usuario: null, isAuthenticated: false })
  },
}))
