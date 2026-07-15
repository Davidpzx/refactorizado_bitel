import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { whatsappApi } from '../services/whatsapp.api'

type EnviarMensajeWhatsAppData = {
  tipo: 'texto' | 'imagen'
  contenido?: string
  media_url?: string
}

type CrearCuentaWhatsAppData = {
  nombre: string
  numero: string
  tienda_id?: string
}

export function useWhatsAppCuentas() {
  return useQuery({
    queryKey: ['whatsapp-cuentas'],
    queryFn: () => whatsappApi.cuentas.list(),
    staleTime: 30_000,
  })
}

export function useWhatsAppChats(cuentaId?: number) {
  return useQuery({
    queryKey: ['whatsapp-chats', cuentaId ?? 'todas'],
    queryFn: () => whatsappApi.chats.list(cuentaId),
    refetchInterval: 8_000,
  })
}

export function useWhatsAppMensajes(chatId: number | null) {
  return useQuery({
    queryKey: ['whatsapp-mensajes', chatId],
    queryFn: () => whatsappApi.mensajes.list(chatId as number),
    enabled: chatId !== null,
    refetchInterval: 5_000,
  })
}

export function useEnviarMensajeWhatsApp() {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: ({ chatId, data }: { chatId: number; data: EnviarMensajeWhatsAppData }) =>
      whatsappApi.mensajes.enviar(chatId, data),
    onSuccess: (_result, variables) => {
      qc.invalidateQueries({ queryKey: ['whatsapp-mensajes', variables.chatId] })
      qc.invalidateQueries({ queryKey: ['whatsapp-chats'] })
    },
  })
}

export function useCrearCuentaWhatsApp() {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: (data: CrearCuentaWhatsAppData) => whatsappApi.cuentas.create(data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['whatsapp-cuentas'] }),
  })
}

export function useEliminarCuentaWhatsApp() {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => whatsappApi.cuentas.eliminar(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['whatsapp-cuentas'] }),
  })
}

export function useQrCuentaWhatsApp(id: number | null) {
  return useQuery({
    queryKey: ['whatsapp-qr', id],
    queryFn: () => whatsappApi.cuentas.qr(id as number),
    enabled: id !== null,
    refetchInterval: (query) => (query.state.data?.estado === 'conectada' ? false : 3_000),
  })
}
