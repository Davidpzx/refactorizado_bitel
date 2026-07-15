import { useState } from 'react'
import { ChatCircleDots, ChartBar, Megaphone } from '@phosphor-icons/react'
import { PageHeader } from '../../components/PageHeader'
import { useAuth } from '../../hooks/useAuth'
import { CrmEstadisticasTab } from './CrmEstadisticasTab'
import { CrmPipelineTab } from './CrmPipelineTab'
import { CrmWhatsAppTab } from './CrmWhatsAppTab'

type CrmTab = 'pipeline' | 'whatsapp' | 'estadisticas'

const TABS: { value: CrmTab; label: string; Icon: typeof Megaphone }[] = [
  { value: 'pipeline', label: 'Pipeline', Icon: Megaphone },
  { value: 'whatsapp', label: 'WhatsApp', Icon: ChatCircleDots },
  { value: 'estadisticas', label: 'Estadisticas', Icon: ChartBar },
]

export function CrmPage() {
  const [tab, setTab] = useState<CrmTab>('pipeline')
  const { usuario } = useAuth()

  return (
    <div className="space-y-6">
      <PageHeader title="CRM y Marketing" description="Pipeline de ventas, WhatsApp y estadisticas." Icon={Megaphone} />

      <div className="flex gap-1 border-b border-kyro-border">
        {TABS.map(({ value, label, Icon }) => (
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

      {tab === 'pipeline' && <CrmPipelineTab />}
      {tab === 'whatsapp' && <CrmWhatsAppTab usuario={usuario} />}
      {tab === 'estadisticas' && <CrmEstadisticasTab usuario={usuario} />}
    </div>
  )
}
