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

  const [codigoDestino, setCodigoDestino] = useState('')
  const [cantidadCambiar, setCantidadCambiar] = useState('')

  const [agregarForm, setAgregarForm] = useState({
    tienda_id:    '',
    tienda_origen: '',
    tipo_chip:    'FÍSICO',
    cantidad:     '',
  })

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
    mutationFn: (body: { tienda_id: number; tienda_origen: string; tipo_chip: string; cantidad: number }) =>
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
    })
  }

  const handleEliminar = (chip: Chip) => {
    if (!confirm(`¿Eliminar lote de chips "${chip.tienda_origen}"?`)) return
    eliminar.mutate(chip.id)
  }

  return (
    <div>
      <PageHeader
        title="Gestión de Chips"
        description="Stock y movimientos de chips por tienda."
        actions={
          isAdmin ? (
            <Button onClick={() => setAgregarDialog(true)}>+ Agregar Stock</Button>
          ) : undefined
        }
      />

      {isLoading ? (
        <div className="flex items-center justify-center h-48 text-sm text-gray-400">
          Cargando...
        </div>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="min-w-full text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left font-semibold text-gray-700">Tienda</th>
                <th className="px-4 py-3 text-left font-semibold text-gray-700">Código Origen</th>
                <th className="px-4 py-3 text-left font-semibold text-gray-700">Tipo</th>
                <th className="px-4 py-3 text-center font-semibold text-gray-700">Stock</th>
                <th className="px-4 py-3 text-right font-semibold text-gray-700">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {chips.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-4 py-8 text-center text-gray-400">Sin chips registrados</td>
                </tr>
              ) : (
                chips.map((chip) => (
                  <tr key={chip.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 text-gray-900">
                      <span className="font-medium">{chip.tienda.codigo}</span>
                      <span className="ml-2 text-gray-500 text-xs">{chip.tienda.nombre}</span>
                    </td>
                    <td className="px-4 py-3 text-gray-700">{chip.tienda_origen}</td>
                    <td className="px-4 py-3">
                      <Badge variant="outline">{chip.tipo_chip}</Badge>
                    </td>
                    <td className="px-4 py-3 text-center">
                      <span className={chip.stock_actual > 0 ? 'font-semibold text-green-700' : 'text-gray-400'}>
                        {chip.stock_actual}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center justify-end gap-2">
                        <Button
                          size="sm"
                          variant="outline"
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
                          variant="outline"
                          onClick={() => setHistorialDialog(chip)}
                        >
                          Ver Historial
                        </Button>
                        {isAdmin && (
                          <Button
                            size="sm"
                            variant="destructive"
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
      )}

      <Dialog
        open={!!cambiarDialog}
        onClose={() => setCambiarDialog(null)}
        title="Cambiar Código de Chips"
      >
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Código destino</label>
            <Input
              value={codigoDestino}
              onChange={(e) => setCodigoDestino(e.target.value)}
              placeholder="Ej: PUNDA50"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Cantidad</label>
            <Input
              type="number"
              value={cantidadCambiar}
              onChange={(e) => setCantidadCambiar(e.target.value)}
              placeholder="0"
              min={1}
            />
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="outline" onClick={() => setCambiarDialog(null)}>Cancelar</Button>
            <Button
              onClick={handleCambiar}
              disabled={cambiarCodigo.isPending || !codigoDestino || !cantidadCambiar}
            >
              {cambiarCodigo.isPending ? 'Moviendo...' : 'Confirmar'}
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
            <p className="text-sm text-gray-600">
              Stock restante: <span className="font-semibold text-gray-900">{historialData.stock_restante}</span>
            </p>
            <div className="space-y-2 max-h-96 overflow-y-auto pr-1">
              {historialData.timeline.length === 0 ? (
                <p className="text-sm text-gray-400 text-center py-4">Sin movimientos registrados</p>
              ) : (
                historialData.timeline.map((ev, idx) => (
                  <div key={idx} className="flex items-start gap-3 p-3 rounded-lg bg-gray-50 border border-gray-100">
                    <div className="shrink-0 pt-0.5">
                      <Badge variant={tipoEventoBadge(ev.tipo)}>{ev.tipo}</Badge>
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center justify-between gap-2">
                        <span className="text-sm font-medium text-gray-900">{ev.cantidad}</span>
                        <span className="text-xs text-gray-400">{ev.fecha_hora}</span>
                      </div>
                      <p className="text-sm text-gray-600 mt-0.5">{ev.detalle}</p>
                      <div className="flex items-center gap-3 mt-1 text-xs text-gray-400">
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
              <label className="block text-sm font-medium text-gray-700 mb-1">Tienda ID</label>
              <Input
                type="number"
                value={agregarForm.tienda_id}
                onChange={(e) => setAgregarForm((f) => ({ ...f, tienda_id: e.target.value }))}
                placeholder="1"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Código Origen</label>
              <Input
                value={agregarForm.tienda_origen}
                onChange={(e) => setAgregarForm((f) => ({ ...f, tienda_origen: e.target.value }))}
                placeholder="Ej: PUNDA50"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Tipo de Chip</label>
              <Input
                value={agregarForm.tipo_chip}
                onChange={(e) => setAgregarForm((f) => ({ ...f, tipo_chip: e.target.value }))}
                placeholder="FÍSICO"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Cantidad</label>
              <Input
                type="number"
                value={agregarForm.cantidad}
                onChange={(e) => setAgregarForm((f) => ({ ...f, cantidad: e.target.value }))}
                placeholder="0"
                min={1}
              />
            </div>
            <div className="flex justify-end gap-2 pt-2">
              <Button variant="outline" onClick={() => setAgregarDialog(false)}>Cancelar</Button>
              <Button
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
