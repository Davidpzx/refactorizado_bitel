import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { RefreshCw, Send, Trash2, FileCheck, AlertCircle, Clock, CheckCircle2 } from 'lucide-react'
import { api } from '../../services/api'
import { Button } from '../../components/ui/button'
import { ActionIconButton, TableActions } from '../../components/ui/ActionIconButton'
import { SegmentedToggle } from '../../components/ui/SegmentedToggle'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { useConfirmDialog } from '../../components/ui/confirm-dialog'
import type { PaginatedResponse } from '../../types/pagination'

// ── Types ─────────────────────────────────────────────────────────────────────

interface Comprobante {
  id: number
  venta_id: number
  tipo_comprobante: '03' | '01'
  serie: string
  numero: number
  estado_sunat: 'PENDIENTE' | 'ENVIADO' | 'ACEPTADO' | 'RECHAZADO' | 'ANULADO'
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

// BD enum: PENDIENTE | ENVIADO | ACEPTADO | RECHAZADO | ANULADO
const ESTADO_CONFIG: Record<string, { label: string; class: string; Icon: React.ComponentType<{ size?: number }> }> = {
  PENDIENTE:  { label: 'Pendiente',  class: 'bg-kyro-warning/10 text-kyro-warning', Icon: Clock },
  ENVIADO:    { label: 'Enviado',    class: 'bg-kyro-info/10 text-kyro-info',       Icon: Send },
  ACEPTADO:   { label: 'Aceptado',   class: 'bg-kyro-success/10 text-kyro-success', Icon: CheckCircle2 },
  RECHAZADO:  { label: 'Rechazado',  class: 'bg-kyro-danger/10 text-kyro-danger',   Icon: AlertCircle },
  ANULADO:    { label: 'Anulado',    class: 'bg-gray-400/10 text-gray-500',         Icon: FileCheck },
}

function EstadoBadge({ estado }: { estado: string }) {
  const cfg = ESTADO_CONFIG[estado] ?? ESTADO_CONFIG.RECHAZADO
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

// Valores alineados al ENUM de BD: PENDIENTE | ENVIADO | ACEPTADO | RECHAZADO | ANULADO
const ESTADOS = [
  { value: '',           label: 'Todos',     tone: 'indigo'   as const },
  { value: 'PENDIENTE',  label: 'Pendiente', tone: 'warning'  as const },
  { value: 'ENVIADO',    label: 'Enviado',   tone: 'info'     as const },
  { value: 'ACEPTADO',   label: 'Aceptado',  tone: 'success'  as const },
  { value: 'RECHAZADO',  label: 'Rechazado', tone: 'danger'   as const },
  { value: 'ANULADO',    label: 'Anulado',   tone: 'indigo'   as const },
]

const TIPOS = [
  { value: '', label: 'Todos', tone: 'indigo' as const },
  { value: '03', label: 'Boleta', tone: 'info' as const },
  { value: '01', label: 'Factura', tone: 'gold' as const },
]

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

  const confirmDialog = useConfirmDialog()

  const comprobantes = data?.data ?? []
  const totalPags    = data?.last_page ?? 1
  const total        = data?.total ?? comprobantes.length

  return (
    <div className="space-y-6">
      {/* Header */}
      <PageHeader title="Comprobantes Electrónicos" subtitle="Gestión de boletas y facturas enviadas a SUNAT" Icon={FileCheck}>
        <Button variant="glassInfo" onClick={() => qc.invalidateQueries({ queryKey: ['comprobantes'] })}>
          <RefreshCw size={14} className="mr-2" /> Actualizar
        </Button>
      </PageHeader>

      {/* Filtros */}
      <ListToolbar description="Segmenta los comprobantes por estado de envío y tipo">
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex items-center gap-2">
            <label className="shrink-0 text-sm text-kyro-muted">Estado:</label>
            <SegmentedToggle
              ariaLabel="Filtrar comprobantes por estado"
              size="sm"
              options={ESTADOS}
              value={filtroEstado}
              onChange={(value) => { setFiltroEstado(value); setPage(1) }}
            />
          </div>
          <div className="flex items-center gap-2">
            <label className="shrink-0 text-sm text-kyro-muted">Tipo:</label>
            <SegmentedToggle
              ariaLabel="Filtrar comprobantes por tipo"
              size="sm"
              options={TIPOS}
              value={filtroTipo}
              onChange={(value) => { setFiltroTipo(value); setPage(1) }}
            />
          </div>
          <span className="ml-auto text-sm text-kyro-muted">{total} comprobante{total !== 1 ? 's' : ''}</span>
        </div>
      </ListToolbar>

      {/* Tabla */}
      <div className="kyro-card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr>
                <th className="kyro-table-head px-4 py-3 text-left">Número</th>
                <th className="kyro-table-head px-4 py-3 text-left">Tipo</th>
                <th className="kyro-table-head px-4 py-3 text-left">Cliente</th>
                <th className="kyro-table-head px-4 py-3 text-right">Monto</th>
                <th className="kyro-table-head px-4 py-3 text-center">Estado SUNAT</th>
                <th className="kyro-table-head px-4 py-3 text-center">Intentos</th>
                <th className="kyro-table-head px-4 py-3 text-left">Mensaje</th>
                <th className="kyro-table-head px-4 py-3 text-left">Creado</th>
                <th className="kyro-table-head px-4 py-3 text-center">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-kyro-border">
              {isLoading ? (
                <tr><td colSpan={9} className="px-4 py-8 text-center text-kyro-muted">Cargando...</td></tr>
              ) : comprobantes.length === 0 ? (
                <tr><td colSpan={9} className="px-4 py-8 text-center text-kyro-muted">No hay comprobantes.</td></tr>
              ) : comprobantes.map(c => (
                <tr key={c.id} className="transition-colors hover:bg-kyro-elevated">
                  <td className="px-4 py-3 font-mono font-medium text-kyro-text">{c.nombre_completo}</td>
                  <td className="px-4 py-3 text-kyro-body">{TIPO_LABEL[c.tipo_comprobante] ?? c.tipo_comprobante}</td>
                  <td className="px-4 py-3">
                    {c.venta?.cliente ? (
                      <div>
                        <div className="font-medium text-kyro-body">{c.venta.cliente.nombre}</div>
                        <div className="text-xs text-kyro-subtle">{c.venta.cliente.dni_ruc}</div>
                      </div>
                    ) : (
                      <span className="text-kyro-subtle">—</span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-right font-medium">{pen(c.venta?.monto_total)}</td>
                  <td className="px-4 py-3 text-center"><EstadoBadge estado={c.estado_sunat} /></td>
                  <td className="px-4 py-3 text-center text-kyro-body">{c.intentos}</td>
                  <td className="px-4 py-3 max-w-[200px]">
                    {c.mensaje_sunat ? (
                      <span className="block truncate text-xs text-kyro-muted" title={c.mensaje_sunat}>
                        {c.mensaje_sunat}
                      </span>
                    ) : <span className="text-kyro-subtle">—</span>}
                  </td>
                  <td className="px-4 py-3 text-xs text-kyro-muted">
                    {c.creado_en ? new Date(c.creado_en).toLocaleDateString('es-PE') : '—'}
                  </td>
                  <td className="px-4 py-3">
                    <TableActions className="justify-center">
                      {c.estado_sunat !== 'ACEPTADO' && (
                        <Button
                          onClick={() => reenviarMutation.mutate(c.id)}
                          disabled={reenviarMutation.isPending}
                          title="Reenviar a SUNAT"
                          aria-label="Reenviar a SUNAT"
                          variant="glassInfo"
                          size="iconSm"
                        >
                          <Send size={13} />
                        </Button>
                      )}
                      {c.estado_sunat !== 'ACEPTADO' && (
                        <ActionIconButton
                          onClick={async () => {
                            const ok = await confirmDialog({
                              title: `¿Eliminar ${c.nombre_completo}?`,
                              intent: 'danger',
                              icon: Trash2,
                              confirmLabel: 'Eliminar',
                            })
                            if (ok) deleteMutation.mutate(c.id)
                          }}
                          disabled={deleteMutation.isPending}
                          tone="delete"
                          label="Eliminar comprobante"
                          icon={<Trash2 size={15} />}
                        />
                      )}
                      {c.xml_path && (
                        <ActionIconButton
                          tone="view"
                          label="Ver XML"
                          icon={<FileCheck size={15} />}
                          onClick={() => window.open(c.xml_path!, '_blank', 'noopener,noreferrer')}
                        />
                      )}
                    </TableActions>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Paginación */}
        {totalPags > 1 && (
          <div className="flex items-center justify-between border-t border-kyro-border bg-kyro-elevated px-4 py-3 text-sm text-kyro-muted">
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
