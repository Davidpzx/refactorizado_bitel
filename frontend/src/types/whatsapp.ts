export interface WhatsAppCuenta {
  id: number
  nombre: string
  numero: string
  instancia: string
  provider: 'evolution' | 'watchimp'
  tienda_id: string | null
  estado: 'conectada' | 'desconectada' | 'qr_pendiente'
}

export interface WhatsAppChat {
  id: number
  cuenta_id: number
  jid: string
  nombre_contacto: string | null
  numero_contacto: string | null
  crm_cliente_id: number | null
  ultimo_mensaje_at: string | null
  no_leidos: number
  cuenta?: { id: number; nombre: string; tienda_id: string | null }
}

export interface WhatsAppMensaje {
  id: number
  chat_id: number
  direccion: 'in' | 'out'
  tipo: 'texto' | 'imagen' | 'documento'
  contenido: string | null
  media_url: string | null
  wa_message_id: string | null
  enviado_por: number | null
  timestamp: string
}

export interface WhatsAppMensajesPaginados {
  data: WhatsAppMensaje[]
  current_page: number
  last_page: number
}
