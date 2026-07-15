import { api } from './api'
import type { WhatsAppChat, WhatsAppCuenta, WhatsAppMensaje, WhatsAppMensajesPaginados } from '../types/whatsapp'

export const whatsappApi = {
  cuentas: {
    list: (): Promise<WhatsAppCuenta[]> =>
      api.get('/v1/whatsapp/cuentas').then(r => r.data),

    create: (data: { nombre: string; numero: string; tienda_id?: string }): Promise<{ cuenta: WhatsAppCuenta; qr: string }> =>
      api.post('/v1/whatsapp/cuentas', data).then(r => r.data),

    qr: (id: number): Promise<{ estado: WhatsAppCuenta['estado']; qr: string }> =>
      api.get(`/v1/whatsapp/cuentas/${id}/qr`).then(r => r.data),

    eliminar: (id: number): Promise<void> =>
      api.delete(`/v1/whatsapp/cuentas/${id}`).then(r => r.data),
  },

  chats: {
    list: (cuentaId?: number): Promise<WhatsAppChat[]> =>
      api.get('/v1/whatsapp/chats', { params: cuentaId ? { cuenta_id: cuentaId } : {} }).then(r => r.data),

    iniciar: (data: { telefono: string; nombre_contacto?: string; tienda_id?: string; crm_cliente_id?: number }): Promise<{ cuenta_id: number; chat: WhatsAppChat }> =>
      api.post('/v1/whatsapp/chats/iniciar', data).then(r => r.data),
  },

  mensajes: {
    list: (chatId: number): Promise<WhatsAppMensajesPaginados> =>
      api.get(`/v1/whatsapp/chats/${chatId}/mensajes`).then(r => r.data),

    enviar: (chatId: number, data: { tipo: 'texto' | 'imagen'; contenido?: string; media_url?: string }): Promise<WhatsAppMensaje> =>
      api.post(`/v1/whatsapp/chats/${chatId}/mensajes`, data).then(r => r.data),
  },
}
