import { useState } from 'react'
import { ChatCircleDots, ChartBar, Image, Megaphone } from '@phosphor-icons/react'
import { PageHeader } from '../../components/PageHeader'
import { useAuth } from '../../hooks/useAuth'
import { useIniciarChatWhatsApp } from '../../hooks/useWhatsApp'
import { normalizarRol } from '../../utils/roles'
import type { Lead } from '../../types/crm'
import type { WhatsAppChat } from '../../types/whatsapp'
import { CrmContenidoBotTab } from './CrmContenidoBotTab'
import { CrmEstadisticasTab } from './CrmEstadisticasTab'
import { CrmPipelineTab } from './CrmPipelineTab'
import { CrmWhatsAppTab } from './CrmWhatsAppTab'

type CrmTab = 'pipeline' | 'whatsapp' | 'contenido' | 'estadisticas'

const TABS: { value: CrmTab; label: string; Icon: typeof Megaphone }[] = [
  { value: 'pipeline', label: 'Pipeline', Icon: Megaphone },
  { value: 'whatsapp', label: 'WhatsApp', Icon: ChatCircleDots },
  { value: 'contenido', label: 'Contenido del bot', Icon: Image },
  { value: 'estadisticas', label: 'Estadisticas', Icon: ChartBar },
]

export function CrmPage() {
  const [tab, setTab] = useState<CrmTab>('pipeline')
  const [chatPreseleccionado, setChatPreseleccionado] = useState<{ cuentaId: number; chat: WhatsAppChat } | null>(null)
  const { usuario } = useAuth()
  const iniciarChat = useIniciarChatWhatsApp()
  const esAdmin = normalizarRol(usuario?.rol) === 'administrador'

  const handleContactar = (lead: Lead) => {
    if (!lead.cliente?.telefono) return

    iniciarChat.mutate(
      {
        telefono: lead.cliente.telefono,
        nombre_contacto: lead.cliente.nombre,
        tienda_id: lead.tienda_id,
        crm_cliente_id: lead.cliente.id,
      },
      {
        onSuccess: (data) => {
          setChatPreseleccionado({ cuentaId: data.cuenta_id, chat: data.chat })
          setTab('whatsapp')
        },
        onError: () => {
          alert('No hay WhatsApp conectado para tu tienda. Contacta al administrador.')
        },
      }
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader title="CRM y Marketing" description="Pipeline de ventas, WhatsApp y estadisticas." Icon={Megaphone} />

      <div className="flex gap-1 border-b border-kyro-border">
        {TABS.filter(t => t.value !== 'contenido' || esAdmin).map(({ value, label, Icon }) => (
          <button
            key={value}
            onClick={() => setTab(value)}
            className={`flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium transition-colors ${
              tab === value
                ? 'border-kyro-indigo text-kyro-indigo'
                : 'border-transparent text-kyro-muted hover:text-kyro-body'
            }`}
          >
            <Icon size={15} />
            {label}
          </button>
        ))}
      </div>

      {tab === 'pipeline' && <CrmPipelineTab onContactar={handleContactar} />}
      {tab === 'whatsapp' && (
        <CrmWhatsAppTab
          usuario={usuario}
          chatPreseleccionado={chatPreseleccionado}
          onPreseleccionConsumida={() => setChatPreseleccionado(null)}
        />
      )}
      {tab === 'contenido' && esAdmin && <CrmContenidoBotTab />}
      {tab === 'estadisticas' && <CrmEstadisticasTab usuario={usuario} />}
    </div>
  )
}
