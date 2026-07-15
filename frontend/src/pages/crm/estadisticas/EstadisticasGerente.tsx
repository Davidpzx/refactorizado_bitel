import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../../services/api'
import { Input } from '../../../components/ui/input'
import { Select } from '../../../components/ui/select'
import { EstadisticasAdmin } from './EstadisticasAdmin'
import type { CrmDashboardFilters } from '../../../types/crm'

interface FiltrosGerente extends CrmDashboardFilters {
  desde: string
  hasta: string
  tienda_id: string
  agente_id: string
  categoria: string
  canal: string
}

export function EstadisticasGerente() {
  const [filtros, setFiltros] = useState<FiltrosGerente>({
    desde: '',
    hasta: '',
    tienda_id: '',
    agente_id: '',
    categoria: '',
    canal: '',
  })

  const { data: tiendas } = useQuery({
    queryKey: ['crm-tiendas-filtro'],
    queryFn: () => api.get<{ codigo: string; nombre: string }[]>('/v1/tiendas').then(r => r.data),
  })

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-3 rounded-kyro border border-kyro-border bg-kyro-surface p-4">
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Desde</label>
          <Input type="date" value={filtros.desde} onChange={e => setFiltros(f => ({ ...f, desde: e.target.value }))} className="w-36" />
        </div>
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Hasta</label>
          <Input type="date" value={filtros.hasta} onChange={e => setFiltros(f => ({ ...f, hasta: e.target.value }))} className="w-36" />
        </div>
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Tienda</label>
          <Select value={filtros.tienda_id} onChange={e => setFiltros(f => ({ ...f, tienda_id: e.target.value }))} className="w-44">
            <option value="">Todas</option>
            {(tiendas ?? []).map(t => <option key={t.codigo} value={t.codigo}>{t.nombre}</option>)}
          </Select>
        </div>
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Agente</label>
          <Input value={filtros.agente_id} onChange={e => setFiltros(f => ({ ...f, agente_id: e.target.value }))} className="w-32" placeholder="ID" />
        </div>
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Categoria</label>
          <Select value={filtros.categoria} onChange={e => setFiltros(f => ({ ...f, categoria: e.target.value }))} className="w-40">
            <option value="">Todas</option>
            <option value="POSTPAGO">Postpago</option>
            <option value="PREPAGO">Prepago</option>
            <option value="EQUIPO">Equipo</option>
          </Select>
        </div>
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Canal</label>
          <Select value={filtros.canal} onChange={e => setFiltros(f => ({ ...f, canal: e.target.value }))} className="w-40">
            <option value="">Todos</option>
            <option value="WHATSAPP">WhatsApp</option>
            <option value="TIENDA">Tienda</option>
            <option value="REFERIDO">Referido</option>
          </Select>
        </div>
      </div>

      <EstadisticasAdmin filtros={filtros} />
    </div>
  )
}
