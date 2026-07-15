import { useEffect, useState } from 'react'
import type { Usuario } from '../../types/auth'
import type { WhatsAppChat } from '../../types/whatsapp'
import { useWhatsAppChats, useWhatsAppCuentas } from '../../hooks/useWhatsApp'
import { normalizarRol } from '../../utils/roles'
import { Button } from '../../components/ui/button'
import { ChatList } from './whatsapp/ChatList'
import { ConectarCuentaModal } from './whatsapp/ConectarCuentaModal'
import { ConversacionPanel } from './whatsapp/ConversacionPanel'
import { CuentaSelector } from './whatsapp/CuentaSelector'

export function CrmWhatsAppTab({
  usuario,
  chatPreseleccionado,
  onPreseleccionConsumida,
}: {
  usuario: Usuario | null
  chatPreseleccionado?: { cuentaId: number; chat: WhatsAppChat } | null
  onPreseleccionConsumida?: () => void
}) {
  const esAdmin = normalizarRol(usuario?.rol) === 'administrador'
  const [cuentaActivaId, setCuentaActivaId] = useState<number | 'todas'>('todas')
  const [chatActivo, setChatActivo] = useState<WhatsAppChat | null>(null)
  const [modalQrAbierto, setModalQrAbierto] = useState(false)

  const { data: cuentas = [] } = useWhatsAppCuentas()
  const { data: chats = [] } = useWhatsAppChats(cuentaActivaId === 'todas' ? undefined : cuentaActivaId)

  useEffect(() => {
    if (!chatPreseleccionado) return
    setCuentaActivaId(chatPreseleccionado.cuentaId)
    setChatActivo(chatPreseleccionado.chat)
    onPreseleccionConsumida?.()
  }, [chatPreseleccionado, onPreseleccionConsumida])

  if (cuentas.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-24 text-center text-kyro-muted">
        <p className="text-sm">Todavia no hay ninguna cuenta de WhatsApp conectada.</p>
        {esAdmin && (
          <Button variant="default" onClick={() => setModalQrAbierto(true)}>
            Conectar la primera cuenta
          </Button>
        )}
        <ConectarCuentaModal open={modalQrAbierto} onClose={() => setModalQrAbierto(false)} />
      </div>
    )
  }

  return (
    <div className="flex h-[calc(100vh-220px)] flex-col gap-3">
      <CuentaSelector
        cuentas={cuentas}
        cuentaActivaId={cuentaActivaId}
        onSeleccionar={(id) => {
          setCuentaActivaId(id)
          setChatActivo(null)
        }}
        onAgregarNueva={() => setModalQrAbierto(true)}
        esAdmin={esAdmin}
      />

      <div className="flex flex-1 overflow-hidden rounded-kyro border border-kyro-border">
        <div className="w-80 shrink-0">
          <ChatList
            chats={chats}
            chatActivoId={chatActivo?.id ?? null}
            onSeleccionar={setChatActivo}
            mostrarBadgeCuenta={cuentaActivaId === 'todas'}
          />
        </div>
        <ConversacionPanel chat={chatActivo} />
      </div>

      <ConectarCuentaModal open={modalQrAbierto} onClose={() => setModalQrAbierto(false)} />
    </div>
  )
}
