import { useEffect } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { authApi } from '../services/auth.api'
import { useAuthStore } from '../store/authStore'
import type { LoginCredentials, Usuario } from '../types/auth'

export function useAuth() {
  const { token, usuario, isAuthenticated, setAuth, setUsuario, logout: storeLogout } = useAuthStore()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  // Obtener datos del usuario si hay token pero no está cargado aún
  const meQuery = useQuery<Usuario>({
    queryKey: ['auth', 'me'],
    queryFn: authApi.me,
    enabled: !!token && !usuario,
    retry: false,
  })

  useEffect(() => {
    if (meQuery.data) {
      setUsuario(meQuery.data)
    }
  }, [meQuery.data, setUsuario])

  useEffect(() => {
    if (meQuery.isError) {
      storeLogout()
      navigate('/login', { replace: true })
    }
  }, [meQuery.isError, storeLogout, navigate])

  const loginMutation = useMutation({
    mutationFn: (credentials: LoginCredentials) => authApi.login(credentials),
    onSuccess: (data) => {
      setAuth(data.token, data.usuario)
      queryClient.invalidateQueries({ queryKey: ['auth'] })
      navigate('/', { replace: true })
    },
  })

  const logoutMutation = useMutation({
    mutationFn: authApi.logout,
    onSettled: () => {
      storeLogout()
      queryClient.clear()
      navigate('/login', { replace: true })
    },
  })

  return {
    usuario,
    isAuthenticated,
    isMeLoading: meQuery.isLoading,
    login: loginMutation.mutate,
    loginAsync: loginMutation.mutateAsync,
    isLoggingIn: loginMutation.isPending,
    loginError: loginMutation.error,
    logout: logoutMutation.mutate,
    isLoggingOut: logoutMutation.isPending,
  }
}
