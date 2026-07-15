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

export function useIniciarChatWhatsApp() {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: (data: { telefono: string; nombre_contacto?: string; tienda_id?: string; crm_cliente_id?: number }) =>
      whatsappApi.chats.iniciar(data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['whatsapp-chats'] }),
  })
}

export function usePromocion() {
  return useQuery({ queryKey: ['whatsapp-promocion'], queryFn: () => whatsappApi.contenido.promocion() })
}

export function useGuardarPromocion() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ texto, foto }: { texto: string; foto?: File }) => whatsappApi.contenido.guardarPromocion(texto, foto),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['whatsapp-promocion'] }),
  })
}

export function useFotosProducto() {
  return useQuery({ queryKey: ['whatsapp-fotos-producto'], queryFn: () => whatsappApi.contenido.fotosProducto() })
}

export function useGuardarFotoProducto() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ productoNombre, foto }: { productoNombre: string; foto: File }) => whatsappApi.contenido.guardarFotoProducto(productoNombre, foto),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['whatsapp-fotos-producto'] }),
  })
}

export function useEliminarFotoProducto() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => whatsappApi.contenido.eliminarFotoProducto(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['whatsapp-fotos-producto'] }),
  })
}

export function useBuscarProductosInventario(q: string) {
  return useQuery({
    queryKey: ['inventario-nombres', q],
    queryFn: () => whatsappApi.contenido.buscarInventario(q),
    enabled: q.length >= 2,
  })
}

export function useBotReglas(habilitado: boolean) {
  return useQuery({
    queryKey: ['whatsapp-bot-reglas'],
    queryFn: () => whatsappApi.bot.reglas(),
    enabled: habilitado,
  })
}

export function useGuardarBotRegla() {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: (data: Parameters<typeof whatsappApi.bot.guardarRegla>[0]) => whatsappApi.bot.guardarRegla(data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['whatsapp-bot-reglas'] }),
  })
}

export function useEliminarBotRegla() {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => whatsappApi.bot.eliminarRegla(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['whatsapp-bot-reglas'] }),
  })
}

export function useToggleBotCuenta() {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: ({ cuentaId, botActivo }: { cuentaId: number; botActivo: boolean }) => whatsappApi.bot.toggle(cuentaId, botActivo),
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
