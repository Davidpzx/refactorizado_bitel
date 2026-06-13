import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { RefreshCw, Send, Trash2, FileCheck, AlertCircle, Clock, CheckCircle2 } from 'lucide-react'
import { api } from '../../services/api'
import { Button } from '../../components/ui/button'
import { Select } from '../../components/ui/select'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import type { PaginatedResponse } from '../../types/pagination'

// ── Types ─────────────────────────────────────────────────────────────────────

interface Comprobante {
  id: number
  venta_id: number
  tipo_comprobante: '03' | '01'
  serie: string
  numero: number
  estado_sunat: 'PENDIENTE' | 'ACEPTADO' | 'ACEPTADO_OBS' | 'ERROR'
  xml_path: string | null
  cdr_path: string | null
  mensaje_sunat: string | null
  intentos: number
  proximo_intento: string | null
  creado_en: string
  nombre_completo: string
  venta?: {
    id: number
    monto_total: number | string
    cliente?: { nombre: string; dni_ruc: string } | null
  } | null
}

// ── API ───────────────────────────────────────────────────────────────────────

const comprobantesApi = {
  list: (params: { estado_sunat?: string; tipo?: string; page?: number }) =>
    api.get<PaginatedResponse<Comprobante>>('/v1/comprobantes', { params }).then(r => r.data),
  reenviar: (id: number) =>
    api.post<{ message: string }>(`/v1/comprobantes/${id}/reenviar`).then(r => r.data),
  destroy: (id: number) =>
    api.delete(`/v1/comprobantes/${id}`),
}

// ── Status badge ──────────────────────────────────────────────────────────────

const ESTADO_CONFIG: Record<string, { label: string; class: string; Icon: React.ComponentType<{ size?: number }> }> = {
  PENDIENTE:    { label: 'Pendiente',    class: 'bg-yellow-100 text-yellow-800', Icon: Clock },
  ACEPTADO:     { label: 'Aceptado',     class: 'bg-green-100 text-green-800',  Icon: CheckCircle2 },
  ACEPTADO_OBS: { label: 'Aceptado c/obs', class: 'bg-blue-100 text-blue-800', Icon: FileCheck },
  ERROR:        { label: 'Error',        class: 'bg-red-100 text-red-800',      Icon: AlertCircle },
}

function EstadoBadge({ estado }: { estado: string }) {
  const cfg = ESTADO_CONFIG[estado] ?? ESTADO_CONFIG.ERROR
  const { Icon } = cfg
  return (
    <span className={`inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full font-medium ${cfg.class}`}>
      <Icon size={11} /> {cfg.label}
    </span>
  )
}

const pen = (v: number | string | null | undefined) =>
  v != null ? new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' }).format(Number(v)) : '—'

const TIPO_LABEL: Record<string, string> = { '03': 'Boleta', '01': 'Factura' }

// ── Page ──────────────────────────────────────────────────────────────────────

export function ComprobantesPage() {
  const qc = useQueryClient()
  const [filtroEstado, setFiltroEstado] = useState('')
  const [filtroTipo,   setFiltroTipo]   = useState('')
  const [page,         setPage]         = useState(1)

  const { data, isLoading } = useQuery({
    queryKey: ['comprobantes', filtroEstado, filtroTipo, page],
    queryFn: () => comprobantesApi.list({
      estado_sunat: filtroEstado || undefined,
      tipo:         filtroTipo   || undefined,
      page,
    }),
  })

  const reenviarMutation = useMutation({
    mutationFn: comprobantesApi.reenviar,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['comprobantes'] }),
  })

  const deleteMutation = useMutation({
    mutationFn: comprobantesApi.destroy,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['comprobantes'] }),
  })

  const comprobantes = data?.data ?? []
  const totalPags    = data?.last_page ?? 1
  const total        = data?.total ?? comprobantes.length

  return (
    <div className="space-y-6">
      {/* Header */}
      <PageHeader title="Comprobantes Electrónicos" subtitle="Gestión de boletas y facturas enviadas a SUNAT" accent="#10b981">
        <Button variant="outline" onClick={() => qc.invalidateQueries({ queryKey: ['comprobantes'] })}>
          <RefreshCw size={14} className="mr-2" /> Actualizar
        </Button>
      </PageHeader>

      {/* Filtros */}
      <ListToolbar description="Segmenta los comprobantes por estado de envío y tipo">
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex items-center gap-2">
            <label className="text-sm text-gray-600 shrink-0">Estado:</label>
            <Select
              value={filtroEstado}
              onChange={e => { setFiltroEstado(e.target.value); setPage(1) }}
              className="w-44"
            >
              <option value="">Pendientes</option>
              <option value="PENDIENTE">Pendiente</option>
              <option value="ACEPTADO">Aceptado</option>
              <option value="ACEPTADO_OBS">Aceptado c/obs</option>
              <option value="ERROR">Error</option>
            </Select>
          </div>
          <div className="flex items-center gap-2">
            <label className="text-sm text-gray-600 shrink-0">Tipo:</label>
            <Select
              value={filtroTipo}
              onChange={e => { setFiltroTipo(e.target.value); setPage(1) }}
              className="w-36"
            >
              <option value="">Todos</option>
              <option value="03">Boleta</option>
              <option value="01">Factura</option>
            </Select>
          </div>
          <span className="text-sm text-gray-500 ml-auto">{total} comprobante{total !== 1 ? 's' : ''}</span>
        </div>
      </ListToolbar>

      {/* Tabla */}
      <div className="premium-surface">
        <div className="overflow-x-auto">
          <table className="premium-table w-full text-sm">
            <thead>
              <tr>
                <th className="px-4 py-3 text-left">Número</th>
                <th className="px-4 py-3 text-left">Tipo</th>
                <th className="px-4 py-3 text-left">Cliente</th>
                <th className="px-4 py-3 text-right">Monto</th>
                <th className="px-4 py-3 text-center">Estado SUNAT</th>
                <th className="px-4 py-3 text-center">Intentos</th>
                <th className="px-4 py-3 text-left">Mensaje</th>
                <th className="px-4 py-3 text-left">Creado</th>
                <th className="px-4 py-3 text-center">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {isLoading ? (
                <tr><td colSpan={9} className="px-4 py-8 text-center text-gray-400">Cargando...</td></tr>
              ) : comprobantes.length === 0 ? (
                <tr><td colSpan={9} className="px-4 py-8 text-center text-gray-400">No hay comprobantes.</td></tr>
              ) : comprobantes.map(c => (
                <tr key={c.id} className="hover:bg-gray-50 transition-colors">
                  <td className="px-4 py-3 font-mono font-medium text-gray-900">{c.nombre_completo}</td>
                  <td className="px-4 py-3 text-gray-600">{TIPO_LABEL[c.tipo_comprobante] ?? c.tipo_comprobante}</td>
                  <td className="px-4 py-3">
                    {c.venta?.cliente ? (
                      <div>
                        <div className="font-medium text-gray-800">{c.venta.cliente.nombre}</div>
                        <div className="text-xs text-gray-400">{c.venta.cliente.dni_ruc}</div>
                      </div>
                    ) : (
                      <span className="text-gray-400">—</span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-right font-medium">{pen(c.venta?.monto_total)}</td>
                  <td className="px-4 py-3 text-center"><EstadoBadge estado={c.estado_sunat} /></td>
                  <td className="px-4 py-3 text-center text-gray-600">{c.intentos}</td>
                  <td className="px-4 py-3 max-w-[200px]">
                    {c.mensaje_sunat ? (
                      <span className="text-xs text-gray-500 truncate block" title={c.mensaje_sunat}>
                        {c.mensaje_sunat}
                      </span>
                    ) : <span className="text-gray-300">—</span>}
                  </td>
                  <td className="px-4 py-3 text-xs text-gray-500">
                    {c.creado_en ? new Date(c.creado_en).toLocaleDateString('es-PE') : '—'}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center justify-center gap-2">
                      {c.estado_sunat !== 'ACEPTADO' && (
                        <button
                          onClick={() => reenviarMutation.mutate(c.id)}
                          disabled={reenviarMutation.isPending}
                          title="Reenviar a SUNAT"
                          className="p-1.5 rounded-md text-gray-400 hover:bg-blue-50 hover:text-blue-600 transition-colors"
                        >
                          <Send size={13} />
                        </button>
                      )}
                      {c.estado_sunat !== 'ACEPTADO' && (
                        <button
                          onClick={() => {
                            if (window.confirm(`¿Eliminar ${c.nombre_completo}?`)) deleteMutation.mutate(c.id)
                          }}
                          disabled={deleteMutation.isPending}
                          title="Eliminar"
                          className="p-1.5 rounded-md text-gray-400 hover:bg-red-50 hover:text-red-600 transition-colors"
                        >
                          <Trash2 size={13} />
                        </button>
                      )}
                      {c.xml_path && (
                        <a
                          href={c.xml_path}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="p-1.5 rounded-md text-gray-400 hover:bg-green-50 hover:text-green-600 transition-colors"
                          title="Ver XML"
                        >
                          <FileCheck size={13} />
                        </a>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Paginación */}
        {totalPags > 1 && (
          <div className="border-t border-gray-100 px-4 py-3 flex items-center justify-between text-sm text-gray-600">
            <span>Página {page} de {totalPags}</span>
            <div className="flex gap-2">
              <Button variant="outline" size="sm" onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page <= 1}>
                Anterior
              </Button>
              <Button variant="outline" size="sm" onClick={() => setPage(p => Math.min(totalPags, p + 1))} disabled={page >= totalPags}>
                Siguiente
              </Button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
