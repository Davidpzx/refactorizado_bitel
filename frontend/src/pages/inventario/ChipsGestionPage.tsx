import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../../services/api'
import { PageHeader } from '../../components/PageHeader'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { Dialog } from '../../components/ui/dialog'
import { useAuth } from '../../hooks/useAuth'

interface ChipTienda {
  codigo: string
  nombre: string
}

interface Chip {
  id: number
  tienda_id: number
  tienda_origen: string
  tipo_chip: string
  stock_actual: number
  series_info?: Array<{ inicio: string; fin: string | null }> | null
  tienda: ChipTienda
}

interface TimelineEvento {
  fecha_hora: string
  tipo: string
  cantidad: string
  detalle: string
  agente: string
  stock_anterior: number
  stock_nuevo: number
}

interface HistorialResponse {
  stock_restante: number
  timeline: TimelineEvento[]
}

type BadgeVariant = 'default' | 'success' | 'warning' | 'destructive' | 'outline'

function tipoEventoBadge(tipo: string): BadgeVariant {
  if (tipo === 'ENTRADA') return 'success'
  if (tipo === 'SALIDA')  return 'destructive'
  if (tipo === 'TRASLADO') return 'warning'
  return 'default'
}

export function ChipsGestionPage() {
  const qc = useQueryClient()
  const { usuario } = useAuth()
  const isAdmin = usuario?.rol === 'admin'

  const [cambiarDialog, setCambiarDialog]   = useState<Chip | null>(null)
  const [historialDialog, setHistorialDialog] = useState<Chip | null>(null)
  const [agregarDialog, setAgregarDialog]   = useState(false)
  const [ajusteDialog, setAjusteDialog]     = useState<Chip | null>(null)

  const [codigoDestino, setCodigoDestino] = useState('')
  const [cantidadCambiar, setCantidadCambiar] = useState('')

  const [agregarForm, setAgregarForm] = useState<{
    tienda_id: string
    tienda_origen: string
    tipo_chip: string
    cantidad: string
    series?: string
  }>({
    tienda_id:    '',
    tienda_origen: '',
    tipo_chip:    'FÍSICO',
    cantidad:     '',
    series:       '',
  })
  const [cantidadReal, setCantidadReal] = useState('')
  const [observacionAjuste, setObservacionAjuste] = useState('')

  const { data, isLoading } = useQuery<{ data: Chip[] }>({
    queryKey: ['chips'],
    queryFn:  () => api.get<{ data: Chip[] }>('/v1/chips').then((r) => r.data),
  })

  const { data: historialData } = useQuery<HistorialResponse>({
    queryKey: ['chips-historial', historialDialog?.id],
    queryFn:  () =>
      api.get<HistorialResponse>(`/v1/chips/${historialDialog!.id}/historial`).then((r) => r.data),
    enabled: !!historialDialog,
  })

  const cambiarCodigo = useMutation({
    mutationFn: ({ id, codigo_destino, cantidad }: { id: number; codigo_destino: string; cantidad: number }) =>
      api.post(`/v1/chips/${id}/cambiar-codigo`, { codigo_destino, cantidad }).then((r) => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['chips'] })
      setCambiarDialog(null)
      setCodigoDestino('')
      setCantidadCambiar('')
    },
  })

  const agregarStock = useMutation({
    mutationFn: (body: { tienda_id: number; tienda_origen: string; tipo_chip: string; cantidad: number; series: Array<{ inicio: string; fin: string | null }> }) =>
      api.post('/v1/chips', body).then((r) => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['chips'] })
      setAgregarDialog(false)
      setAgregarForm({ tienda_id: '', tienda_origen: '', tipo_chip: 'FÍSICO', cantidad: '' })
    },
  })

  const eliminar = useMutation({
    mutationFn: (id: number) => api.delete(`/v1/chips/${id}`).then((r) => r.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['chips'] }),
  })

  const ajustarStock = useMutation({
    mutationFn: (body: { id: number; cantidad_real: number; observacion: string }) =>
      api.post(`/v1/chips/${body.id}/ajustar-stock-real`, body).then((r) => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['chips'] })
      setAjusteDialog(null)
      setCantidadReal('')
      setObservacionAjuste('')
    },
  })

  const chips = data?.data ?? []

  const handleCambiar = () => {
    if (!cambiarDialog) return
    cambiarCodigo.mutate({
      id:             cambiarDialog.id,
      codigo_destino: codigoDestino,
      cantidad:       Number(cantidadCambiar),
    })
  }

  const handleAgregar = () => {
    agregarStock.mutate({
      tienda_id:    Number(agregarForm.tienda_id),
      tienda_origen: agregarForm.tienda_origen,
      tipo_chip:    agregarForm.tipo_chip,
      cantidad:     Number(agregarForm.cantidad),
      series: (agregarForm.series ?? '').split(/\r?\n/).map(line => {
        const [inicio, fin] = line.split(/[-|,]/).map(v => v.trim())
        return { inicio, fin: fin || null }
      }).filter(r => r.inicio),
    })
  }

  const handleEliminar = (chip: Chip) => {
    if (!confirm(`¿Eliminar lote de chips "${chip.tienda_origen}"?`)) return
    eliminar.mutate(chip.id)
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Gestión de Chips"
        description="Stock y movimientos de chips por tienda."
        actions={
          isAdmin ? (
            <Button variant="gold" onClick={() => setAgregarDialog(true)}>+ Agregar Stock</Button>
          ) : undefined
        }
      />

      {isLoading ? (
        <div className="kyro-card flex h-48 items-center justify-center text-sm text-kyro-muted">
          <span className="inline-flex items-center gap-2">
            <span className="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
            Cargando...
          </span>
        </div>
      ) : (
        <div className="kyro-card relative overflow-hidden">
          <div aria-hidden className="hidden" />
          <div className="overflow-x-auto">
          <table className="min-w-full border-separate border-spacing-0 text-sm">
            <thead>
              <tr>
                <th className="kyro-table-head px-4 py-3 text-left">Tienda</th>
                <th className="kyro-table-head px-4 py-3 text-left">Código Origen</th>
                <th className="kyro-table-head px-4 py-3 text-left">Tipo</th>
                <th className="kyro-table-head px-4 py-3 text-center">Stock</th>
                <th className="kyro-table-head px-4 py-3 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {chips.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-4 py-14 text-center text-gray-400 dark:text-zinc-500">Sin chips registrados</td>
                </tr>
              ) : (
                chips.map((chip) => (
                  <tr key={chip.id} className="group transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-400/[0.035]">
                    <td className="border-b border-kyro-border px-4 py-3 text-kyro-text">
                      <span className="font-semibold">{chip.tienda.codigo}</span>
                      <span className="ml-2 text-xs text-gray-400 dark:text-zinc-500">{chip.tienda.nombre}</span>
                    </td>
                    <td className="border-b border-kyro-border px-4 py-3 font-mono text-kyro-body">{chip.tienda_origen}</td>
                    <td className="border-b border-gray-100 px-4 py-3 dark:border-white/[0.05]">
                      <Badge variant="outline">{chip.tipo_chip}</Badge>
                    </td>
                    <td className="border-b border-gray-100 px-4 py-3 text-center dark:border-white/[0.05]">
                      <span className={chip.stock_actual > 0 ? 'font-mono font-bold text-emerald-600 dark:text-emerald-400' : 'font-mono text-gray-400 dark:text-zinc-600'}>
                        {chip.stock_actual}
                      </span>
                    </td>
                    <td className="border-b border-gray-100 px-4 py-3 dark:border-white/[0.05]">
                      <div className="flex flex-wrap items-center justify-end gap-2">
                        <Button
                          size="sm"
                          variant="glassIndigo"
                          onClick={() => {
                            setCambiarDialog(chip)
                            setCodigoDestino('')
                            setCantidadCambiar('')
                          }}
                        >
                          Cambiar Código
                        </Button>
                        <Button
                          size="sm"
                          variant="glassInfo"
                          onClick={() => setHistorialDialog(chip)}
                        >
                          Ver Historial
                        </Button>
                        {isAdmin && (
                          <Button
                            size="sm"
                            variant="glassWarning"
                            onClick={() => {
                              setAjusteDialog(chip)
                              setCantidadReal(String(chip.stock_actual))
                              setObservacionAjuste('')
                            }}
                          >
                            Ajustar stock
                          </Button>
                        )}
                        {isAdmin && (
                          <Button
                            size="sm"
                            variant="glassDanger"
                            onClick={() => handleEliminar(chip)}
                            disabled={eliminar.isPending}
                          >
                            Eliminar
                          </Button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
          </div>
        </div>
      )}

      <Dialog
        open={!!cambiarDialog}
        onClose={() => setCambiarDialog(null)}
        title="Cambiar Código de Chips"
      >
        <div className="space-y-4">
          <div>
            <label className="mb-1 block text-sm font-medium text-kyro-body">Código destino</label>
            <Input
              value={codigoDestino}
              onChange={(e) => setCodigoDestino(e.target.value)}
              placeholder="Ej: PUNDA50"
            />
          </div>
          <div>
            <label className="mb-1 block text-sm font-medium text-kyro-body">Cantidad</label>
            <Input
              type="number"
              value={cantidadCambiar}
              onChange={(e) => setCantidadCambiar(e.target.value)}
              placeholder="0"
              min={1}
            />
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="ghost" onClick={() => setCambiarDialog(null)}>Cancelar</Button>
            <Button
              variant="gold"
              onClick={handleCambiar}
              disabled={cambiarCodigo.isPending || !codigoDestino || !cantidadCambiar}
            >
              {cambiarCodigo.isPending ? 'Moviendo...' : 'Confirmar'}
            </Button>
          </div>
        </div>
      </Dialog>

      <Dialog
        open={!!ajusteDialog}
        onClose={() => setAjusteDialog(null)}
        title={`Ajustar stock - ${ajusteDialog?.tienda_origen ?? ''}`}
      >
        <div className="space-y-4">
          <div>
            <label className="mb-1 block text-sm font-medium text-kyro-body">Cantidad fisica real</label>
            <Input type="number" min="0" value={cantidadReal} onChange={e => setCantidadReal(e.target.value)} />
          </div>
          <div>
            <label className="mb-1 block text-sm font-medium text-kyro-body">Observacion</label>
            <textarea
              rows={3}
              value={observacionAjuste}
              onChange={e => setObservacionAjuste(e.target.value)}
              className="kyro-input w-full"
              placeholder="Motivo y referencia del conteo fisico"
            />
          </div>
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setAjusteDialog(null)}>Cancelar</Button>
            <Button
              variant="gold"
              disabled={!ajusteDialog || cantidadReal === '' || observacionAjuste.trim().length < 10 || ajustarStock.isPending}
              onClick={() => ajusteDialog && ajustarStock.mutate({
                id: ajusteDialog.id,
                cantidad_real: Number(cantidadReal),
                observacion: observacionAjuste.trim(),
              })}
            >
              {ajustarStock.isPending ? 'Ajustando...' : 'Aplicar ajuste'}
            </Button>
          </div>
        </div>
      </Dialog>

      <Dialog
        open={!!historialDialog}
        onClose={() => setHistorialDialog(null)}
        title={`Historial — ${historialDialog?.tienda_origen ?? ''}`}
        maxWidth="lg"
      >
        {historialData ? (
          <div className="space-y-3">
            <p className="rounded-xl border border-indigo-200/70 bg-indigo-50/60 px-3 py-2.5 text-sm text-gray-600 dark:border-indigo-400/15 dark:bg-indigo-500/[0.07] dark:text-zinc-300">
              Stock restante: <span className="font-mono font-bold text-indigo-700 dark:text-indigo-300">{historialData.stock_restante}</span>
            </p>
            <div className="space-y-2 max-h-96 overflow-y-auto pr-1">
              {historialData.timeline.length === 0 ? (
                <p className="text-sm text-gray-400 text-center py-4">Sin movimientos registrados</p>
              ) : (
                historialData.timeline.map((ev, idx) => (
                  <div key={idx} className="kyro-card flex items-start gap-3 p-3 transition-colors hover:border-kyro-indigo">
                    <div className="shrink-0 pt-0.5">
                      <Badge variant={tipoEventoBadge(ev.tipo)}>{ev.tipo}</Badge>
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center justify-between gap-2">
                        <span className="font-mono text-sm font-semibold text-gray-900 dark:text-zinc-100">{ev.cantidad}</span>
                        <span className="text-xs text-gray-400 dark:text-zinc-500">{ev.fecha_hora}</span>
                      </div>
                      <p className="mt-0.5 text-sm text-gray-600 dark:text-zinc-400">{ev.detalle}</p>
                      <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-gray-400 dark:text-zinc-500">
                        <span>Agente: {ev.agente}</span>
                        <span>Antes: {ev.stock_anterior} → Después: {ev.stock_nuevo}</span>
                      </div>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>
        ) : (
          <div className="py-8 text-center text-sm text-gray-400">Cargando historial...</div>
        )}
      </Dialog>

      {isAdmin && (
        <Dialog
          open={agregarDialog}
          onClose={() => setAgregarDialog(false)}
          title="Agregar Stock de Chips"
        >
          <div className="space-y-4">
            <div>
              <label className="mb-1 block text-sm font-medium text-kyro-body">Tienda ID</label>
              <Input
                type="number"
                value={agregarForm.tienda_id}
                onChange={(e) => setAgregarForm((f) => ({ ...f, tienda_id: e.target.value }))}
                placeholder="1"
              />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-kyro-body">Código Origen</label>
              <Input
                value={agregarForm.tienda_origen}
                onChange={(e) => setAgregarForm((f) => ({ ...f, tienda_origen: e.target.value }))}
                placeholder="Ej: PUNDA50"
              />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-kyro-body">Tipo de Chip</label>
              <Input
                value={agregarForm.tipo_chip}
                onChange={(e) => setAgregarForm((f) => ({ ...f, tipo_chip: e.target.value }))}
                placeholder="FÍSICO"
              />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-kyro-body">Cantidad</label>
              <Input
                type="number"
                value={agregarForm.cantidad}
                onChange={(e) => setAgregarForm((f) => ({ ...f, cantidad: e.target.value }))}
                placeholder="0"
                min={1}
              />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-kyro-body">Rangos de series (opcional)</label>
              <textarea
                rows={4}
                value={agregarForm.series ?? ''}
                onChange={(e) => setAgregarForm((f) => ({ ...f, series: e.target.value }))}
                className="kyro-input w-full font-mono text-sm"
                placeholder={'8951150000000000001 - 8951150000000000050\n8951150000000000100'}
              />
              <p className="mt-1 text-xs text-kyro-muted">Un rango por linea: inicio - fin.</p>
            </div>
            <div className="flex justify-end gap-2 pt-2">
              <Button variant="ghost" onClick={() => setAgregarDialog(false)}>Cancelar</Button>
              <Button
                variant="gold"
                onClick={handleAgregar}
                disabled={
                  agregarStock.isPending ||
                  !agregarForm.tienda_id ||
                  !agregarForm.tienda_origen ||
                  !agregarForm.cantidad
                }
              >
                {agregarStock.isPending ? 'Guardando...' : 'Agregar'}
              </Button>
            </div>
          </div>
        </Dialog>
      )}
    </div>
  )
}
