import { useState } from 'react'
import { PaperPlaneRight } from '@phosphor-icons/react'
import type { WhatsAppChat } from '../../../types/whatsapp'
import { useEnviarMensajeWhatsApp, useWhatsAppMensajes } from '../../../hooks/useWhatsApp'
import { Button } from '../../../components/ui/button'
import { Input } from '../../../components/ui/input'

export function ConversacionPanel({ chat }: { chat: WhatsAppChat | null }) {
  const [texto, setTexto] = useState('')
  const { data } = useWhatsAppMensajes(chat?.id ?? null)
  const enviar = useEnviarMensajeWhatsApp()

  if (!chat) {
    return (
      <div className="flex h-full flex-1 items-center justify-center text-sm text-kyro-muted">
        Selecciona una conversacion.
      </div>
    )
  }

  const handleEnviar = () => {
    const contenido = texto.trim()
    if (!contenido) return

    enviar.mutate({ chatId: chat.id, data: { tipo: 'texto', contenido } })
    setTexto('')
  }

  return (
    <div className="flex h-full flex-1 flex-col">
      <div className="border-b border-kyro-border px-4 py-3">
        <p className="text-sm font-semibold">{chat.nombre_contacto ?? chat.numero_contacto}</p>
        <p className="text-xs text-kyro-muted">{chat.numero_contacto}</p>
      </div>

      <div className="flex-1 space-y-2 overflow-y-auto p-4">
        {(data?.data ?? []).map((mensaje) => (
          <div key={mensaje.id} className={`flex ${mensaje.direccion === 'out' ? 'justify-end' : 'justify-start'}`}>
            <div
              className={`max-w-[70%] rounded-kyro px-3 py-2 text-sm ${
                mensaje.direccion === 'out' ? 'bg-kyro-indigo text-white' : 'bg-kyro-border/40 text-kyro-body'
              }`}
            >
              {mensaje.tipo === 'imagen' && mensaje.media_url && (
                <img src={mensaje.media_url} alt="Imagen" className="mb-1 max-w-full rounded" />
              )}
              {mensaje.contenido && <p>{mensaje.contenido}</p>}
              <p className="mt-1 text-right text-[10px] opacity-70">
                {new Date(mensaje.timestamp).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })}
              </p>
            </div>
          </div>
        ))}
      </div>

      <div className="flex items-center gap-2 border-t border-kyro-border p-3">
        <Input
          value={texto}
          onChange={(event) => setTexto(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter') handleEnviar()
          }}
          placeholder="Escribe un mensaje..."
          className="flex-1"
        />
        <Button variant="gold" size="icon" disabled={!texto.trim() || enviar.isPending} onClick={handleEnviar}>
          <PaperPlaneRight size={16} />
        </Button>
      </div>
    </div>
  )
}
