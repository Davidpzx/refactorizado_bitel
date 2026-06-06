import { create } from 'zustand'

interface Usuario {
  id: number
  name: string
  email: string
}

interface AuthState {
  token: string | null
  usuario: Usuario | null
  setAuth: (token: string, usuario: Usuario) => void
  logout: () => void
}

export const useAuthStore = create<AuthState>((set) => ({
  token: localStorage.getItem('auth_token'),
  usuario: null,
  setAuth: (token, usuario) => {
    localStorage.setItem('auth_token', token)
    set({ token, usuario })
  },
  logout: () => {
    localStorage.removeItem('auth_token')
    set({ token: null, usuario: null })
  },
}))
