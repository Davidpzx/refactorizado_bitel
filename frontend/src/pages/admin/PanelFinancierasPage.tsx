import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../../services/api'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { Button } from '../../components/ui/button'
import { Badge } from '../../components/ui/badge'
import { Select } from '../../components/ui/select'
import { Input } from '../../components/ui/input'
import { StatCard } from '../../components/ui/StatCard'
import { SegmentedToggle } from '../../components/ui/SegmentedToggle'
import { Warning as AlertTriangle, Funnel as Filter, Handshake, ArrowCounterClockwise as RotateCcw, MagnifyingGlass as Search } from '@phosphor-icons/react'
import { useConfirmDialog } from '../../components/ui/confirm-dialog'

const DIAS_ALERTA_AMARILLA = 15
const DIAS_ALERTA_ROJA     = 30

/** Días transcurridos desde la fecha de venta (para alertar desembolsos pendientes que se estancan). */
function diasTranscurridos(fecha: string): number {
  const f = new Date(fecha.slice(0, 10) + 'T00:00:00')
  const hoy = new Date()
  hoy.setHours(0, 0, 0, 0)
  return Math.max(0, Math.round((hoy.getTime() - f.getTime()) / 86_400_000))
}

function antiguedadVariant(dias: number): 'outline' | 'warning' | 'destructive' {
  if (dias >= DIAS_ALERTA_ROJA) return 'destructive'
  if (dias >= DIAS_ALERTA_AMARILLA) return 'warning'
  return 'outline'
}

interface TiendaFiltro {
  codigo: string
  nombre: string
}

interface FiltrosDisponibles {
  financieras: string[]
  tiendas: TiendaFiltro[]
}

interface FinancieraItem {
  id: number
  financiera: string
  comision_estado: string
  fecha: string
  tienda_id: number
  tienda_nombre: string
  vendedor_nombre: string
  por_cobrar: number
  detalle: {
    producto_nombre?: string
    [key: string]: unknown
  }
  desembolso_confirmado_en?: string | null
  desembolso_confirmado_por_nombre?: string | null
}

interface Totales {
  pendiente: number
  confirmado: number
  total: number
  count_pendiente: number
}

interface FinancierasResponse {
  data: FinancieraItem[]
  totales: Totales
  filtros_disponibles: FiltrosDisponibles
}

type EstadoVariant = 'warning' | 'success' | 'default'
const estadoBadge: Record<string, EstadoVariant> = {
  PENDIENTE: 'warning',
  APROBADA:  'success',
}

const ESTADOS = [
  { value: 'TODAS', label: 'Todos', tone: 'indigo' as const },
  { value: 'PENDIENTE', label: 'Pendiente', tone: 'warning' as const },
  { value: 'APROBADA', label: 'Aprobada', tone: 'success' as const },
]

function formatMes() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}

export function PanelFinancierasPage() {
  const qc = useQueryClient()

  const [mes, setMes]           = useState(formatMes())
  const [financiera, setFinanciera] = useState('')
  const [tienda, setTienda]     = useState('')
  const [estado, setEstado]     = useState('TODAS')

  const { data, isLoading } = useQuery<FinancierasResponse>({
    queryKey: ['financieras', { mes, financiera, tienda, estado }],
    queryFn: () =>
      api
        .get<FinancierasResponse>('/v1/financieras', {
          params: { mes, financiera: financiera || undefined, tienda: tienda || undefined, estado },
        })
        .then((r) => r.data),
  })

  const confirmar = useMutation({
    mutationFn: (id: number) =>
      api.post(`/v1/financieras/${id}/confirmar-desembolso`).then((r) => r.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['financieras'] }),
  })

  const revertir = useMutation({
    mutationFn: (id: number) =>
      api.post(`/v1/financieras/${id}/revertir-desembolso`).then((r) => r.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['financieras'] }),
  })

  const confirmDialog = useConfirmDialog()

  const items    = data?.data ?? []
  const totales  = data?.totales
  const filtros  = data?.filtros_disponibles

  const pendientesVencidos = items.filter(
    (it) => it.comision_estado === 'PENDIENTE' && diasTranscurridos(it.fecha) >= DIAS_ALERTA_ROJA,
  )

  return (
    <div className="space-y-6">
      <PageHeader
        title="Panel de Financieras"
        description="Seguimiento de desembolsos y comisiones por financiera."
        Icon={Handshake}
        accent="#6366f1"
      />

      {pendientesVencidos.length > 0 && (
        <div className="flex items-center gap-3 rounded-kyro-lg border border-kyro-danger/30 bg-kyro-danger/10 p-4 text-sm text-kyro-danger shadow-kyro-card">
          <AlertTriangle size={18} className="shrink-0" />
          <p>
            <strong>{pendientesVencidos.length}</strong> desembolso{pendientesVencidos.length > 1 ? 's' : ''} con más de {DIAS_ALERTA_ROJA} días pendiente{pendientesVencidos.length > 1 ? 's' : ''} de cobro. Revisa con la financiera antes de que se acumulen más.
          </p>
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3 mb-6">
        <StatCard
          title="Pendiente de cobro"
          value={totales ? Number(totales.pendiente) : null}
          formatMoney
          align="top"
          accent="#ffc200"
          valueColorClass="text-amber-600 dark:text-amber-400"
          subtitle={`${totales?.count_pendiente ?? 0} venta${(totales?.count_pendiente ?? 0) === 1 ? '' : 's'} — ${mes}`}
        />
        <StatCard
          title="Confirmado este mes"
          value={totales ? Number(totales.confirmado) : null}
          formatMoney
          align="top"
          accent="#10b981"
          valueColorClass="text-emerald-700 dark:text-emerald-400"
          subtitle="Desembolsos aprobados"
        />
        <StatCard
          title="Total facturado (cuotas)"
          value={totales ? Number(totales.total) : null}
          formatMoney
          align="top"
          accent="#6366f1"
          valueColorClass="text-indigo-700 dark:text-indigo-400"
          subtitle="Inicial + Saldo financieras"
        />
      </div>

      <ListToolbar description="Segmenta desembolsos por periodo, financiera, tienda y estado.">
        <div>
          <label className="block text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-zinc-400 mb-1">Mes</label>
          <Input
            type="month"
            value={mes}
            onChange={(e) => setMes(e.target.value)}
            className="w-40"
          />
        </div>

        <div>
          <label className="block text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-zinc-400 mb-1">Financiera</label>
          <Select
            value={financiera}
            onChange={(e) => setFinanciera(e.target.value)}
            className="w-44"
          >
            <option value="">Todas las financieras</option>
            {filtros?.financieras.map((f) => (
              <option key={f} value={f}>{f}</option>
            ))}
          </Select>
        </div>

        <div>
          <label className="block text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-zinc-400 mb-1">Tienda</label>
          <Select
            value={tienda}
            onChange={(e) => setTienda(e.target.value)}
            className="w-44"
          >
            <option value="">Todas las tiendas</option>
            {filtros?.tiendas.map((t) => (
              <option key={t.codigo} value={t.codigo}>{t.codigo} — {t.nombre}</option>
            ))}
          </Select>
        </div>

        <div>
          <label className="block text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-zinc-400 mb-1">Estado</label>
          <SegmentedToggle
            ariaLabel="Filtrar financieras por estado"
            size="sm"
            options={ESTADOS}
            value={estado}
            onChange={setEstado}
          />
        </div>

        <Button
          variant="glassInfo"
          onClick={() => qc.invalidateQueries({ queryKey: ['financieras'] })}
          className="gap-1.5"
        >
          <Filter size={14} /> Filtrar
        </Button>
      </ListToolbar>

      {isLoading ? (
        <div className="flex h-48 items-center justify-center text-sm text-kyro-muted">
          Cargando...
        </div>
      ) : (
        <div className="kyro-card relative overflow-x-auto">
          <div
            aria-hidden
            className="absolute top-0 left-0 right-0 h-px"
            style={{ background: 'linear-gradient(90deg, rgba(99,102,241,0.55), transparent 60%)' }}
          />
          <table className="min-w-full border-separate border-spacing-0 text-sm">
            <thead className="kyro-table-head">
              <tr>
                <th className="px-4 py-3 text-left font-semibold">Fecha</th>
                <th className="px-4 py-3 text-left font-semibold">Tienda</th>
                <th className="px-4 py-3 text-left font-semibold">Financiera</th>
                <th className="px-4 py-3 text-left font-semibold">Vendedor</th>
                <th className="px-4 py-3 text-left font-semibold">Equipo</th>
                <th className="px-4 py-3 text-center font-semibold">Estado</th>
                <th className="px-4 py-3 text-center font-semibold">Antigüedad</th>
                <th className="px-4 py-3 text-right font-semibold">Por cobrar</th>
                <th className="px-4 py-3 text-right font-semibold">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {items.length === 0 ? (
                <tr>
                  <td colSpan={9} className="px-4 py-10 text-center text-kyro-muted">
                    <span className="inline-flex flex-col items-center gap-2">
                      <span className="flex h-9 w-9 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/[0.04] dark:text-zinc-500">
                        <Search size={16} />
                      </span>
                      Sin registros para los filtros seleccionados
                    </span>
                  </td>
                </tr>
              ) : (
                items.map((item) => {
                  const dias = diasTranscurridos(item.fecha)
                  return (
                  <tr key={item.id} className="transition-colors hover:bg-kyro-elevated [&>td]:border-b [&>td]:border-kyro-border">
                    <td className="whitespace-nowrap px-4 py-3 text-kyro-body">
                      {item.fecha?.slice(0, 10) ?? '—'}
                    </td>
                    <td className="px-4 py-3 text-kyro-text">{item.tienda_nombre}</td>
                    <td className="px-4 py-3 text-kyro-body">{item.financiera}</td>
                    <td className="px-4 py-3 text-kyro-body">{item.vendedor_nombre}</td>
                    <td className="px-4 py-3 text-kyro-body">
                      {item.detalle?.producto_nombre ?? '—'}
                    </td>
                    <td className="px-4 py-3 text-center">
                      <Badge variant={estadoBadge[item.comision_estado] ?? 'default'}>
                        {item.comision_estado}
                      </Badge>
                      {item.comision_estado === 'APROBADA' && item.desembolso_confirmado_por_nombre && (
                        <p className="mt-1 text-xs text-kyro-subtle">
                          Confirmado por {item.desembolso_confirmado_por_nombre}
                          {item.desembolso_confirmado_en ? ` el ${item.desembolso_confirmado_en.slice(0, 10)}` : ''}
                        </p>
                      )}
                    </td>
                    <td className="px-4 py-3 text-center">
                      {item.comision_estado === 'PENDIENTE' ? (
                        <Badge variant={antiguedadVariant(dias)}>
                          {dias} día{dias !== 1 ? 's' : ''}
                        </Badge>
                      ) : (
                        <span className="text-xs text-kyro-subtle">—</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-right font-semibold text-kyro-text">
                      S/ {Number(item.por_cobrar).toFixed(2)}
                    </td>
                    <td className="px-4 py-3 text-right">
                      {item.comision_estado === 'PENDIENTE' && (
                        <Button
                          size="sm"
                          variant="glassSuccess"
                          onClick={async () => {
                            const ok = await confirmDialog({
                              title: `¿Confirmar desembolso de ${item.financiera} por S/ ${Number(item.por_cobrar).toFixed(2)}?`,
                              description: `Vendedor: ${item.vendedor_nombre}. Esta acción no se puede deshacer directamente.`,
                              intent: 'success',
                              icon: Handshake,
                              confirmLabel: 'Confirmar desembolso',
                            })
                            if (!ok) return
                            confirmar.mutate(item.id)
                          }}
                          disabled={confirmar.isPending}
                        >
                          Confirmar Desembolso
                        </Button>
                      )}
                      {item.comision_estado === 'APROBADA' && (
                        <Button
                          size="sm"
                          variant="glassWarning"
                          onClick={async () => {
                            const ok = await confirmDialog({
                              title: `¿Revertir el desembolso confirmado de ${item.financiera} por S/ ${Number(item.por_cobrar).toFixed(2)}?`,
                              description: `Vendedor: ${item.vendedor_nombre}.`,
                              intent: 'gold',
                              icon: RotateCcw,
                              confirmLabel: 'Revertir',
                            })
                            if (!ok) return
                            revertir.mutate(item.id)
                          }}
                          disabled={revertir.isPending}
                        >
                          Revertir
                        </Button>
                      )}
                    </td>
                  </tr>
                  )
                })
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
