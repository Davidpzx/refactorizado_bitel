import { useState } from 'react'
import type { WhatsAppChat } from '../../../types/whatsapp'
import { Input } from '../../../components/ui/input'

export function ChatList({
  chats,
  chatActivoId,
  onSeleccionar,
  mostrarBadgeCuenta,
}: {
  chats: WhatsAppChat[]
  chatActivoId: number | null
  onSeleccionar: (chat: WhatsAppChat) => void
  mostrarBadgeCuenta: boolean
}) {
  const [busqueda, setBusqueda] = useState('')

  const filtrados = chats.filter((chat) => {
    const texto = `${chat.nombre_contacto ?? ''} ${chat.numero_contacto ?? ''}`.toLowerCase()
    return texto.includes(busqueda.toLowerCase())
  })

  return (
    <div className="flex h-full flex-col border-r border-kyro-border">
      <div className="border-b border-kyro-border p-2">
        <Input
          value={busqueda}
          onChange={(event) => setBusqueda(event.target.value)}
          placeholder="Buscar chat..."
          className="h-9"
        />
      </div>
      <div className="flex-1 overflow-y-auto">
        {filtrados.length === 0 && (
          <p className="p-4 text-center text-xs text-kyro-muted">Sin conversaciones.</p>
        )}
        {filtrados.map((chat) => (
          <button
            key={chat.id}
            type="button"
            onClick={() => onSeleccionar(chat)}
            className={`flex w-full items-center gap-3 border-b border-kyro-border/60 px-3 py-2.5 text-left transition-colors ${
              chatActivoId === chat.id ? 'bg-kyro-indigo/10' : 'hover:bg-kyro-border/30'
            }`}
          >
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-kyro-indigo/15 text-xs font-semibold text-kyro-indigo">
              {(chat.nombre_contacto ?? chat.numero_contacto ?? '?').slice(0, 2).toUpperCase()}
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex items-center justify-between gap-2">
                <span className="truncate text-sm font-medium">
                  {chat.nombre_contacto ?? chat.numero_contacto ?? 'Desconocido'}
                </span>
                <span className="flex items-center gap-1">
                  {chat.interes_score >= 5 && <span title="Cliente interesado">🔥</span>}
                  {chat.no_leidos > 0 && (
                    <span className="flex h-4 min-w-4 items-center justify-center rounded-full bg-kyro-indigo px-1 text-[10px] font-bold text-white">
                      {chat.no_leidos}
                    </span>
                  )}
                </span>
              </div>
              {mostrarBadgeCuenta && chat.cuenta && (
                <span className="text-[10px] text-kyro-muted">{chat.cuenta.nombre}</span>
              )}
            </div>
          </button>
        ))}
      </div>
    </div>
  )
}
