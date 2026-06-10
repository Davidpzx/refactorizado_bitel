import { useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { useQuery, useMutation } from '@tanstack/react-query'
import { ArrowLeft, Save, AlertCircle, CheckCircle2 } from 'lucide-react'
import { reportesApi } from '../../services/reportes.api'
import { Button } from '../../components/ui/button'

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })
const fmt = (v: number | string | null | undefined) => pen.format(Number(v ?? 0))

const DESTINOS = ['TIENDA', 'BANCO', 'GERENCIA', 'AGENTE']

export function EditarReportePage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const reporteId = Number(id)

  const { data: reporte, isLoading, error: loadError } = useQuery({
    queryKey: ['reporte', reporteId],
    queryFn: () => reportesApi.obtener(reporteId),
    enabled: !!reporteId,
  })

  const [form, setForm] = useState({
    efectivo_entregado: '',
    destino_efectivo: '',
    observaciones: '',
    motivo_edicion: '',
  })
  const [initialized, setInitialized] = useState(false)
  const [success, setSuccess] = useState(false)

  if (reporte && !initialized) {
    setForm({
      efectivo_entregado: String(Number(reporte.efectivo_entregado)),
      destino_efectivo: reporte.destino_efectivo ?? 'TIENDA',
      observaciones: reporte.observaciones ?? '',
      motivo_edicion: reporte.motivo_edicion ?? '',
    })
    setInitialized(true)
  }

  const { mutate, isPending, error: saveError } = useMutation({
    mutationFn: () =>
      reportesApi.editarAprobado(reporteId, {
        efectivo_entregado: Number(form.efectivo_entregado),
        destino_efectivo: form.destino_efectivo,
        observaciones: form.observaciones || undefined,
        motivo_edicion: form.motivo_edicion,
      }),
    onSuccess: () => {
      setSuccess(true)
      setTimeout(() => navigate('/mi-historial'), 1800)
    },
  })

  const canEdit = reporte?.estado_edicion === 'APROBADO'

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64 text-sm text-gray-400">
        Cargando reporte...
      </div>
    )
  }

  if (loadError || !reporte) {
    return (
      <div className="space-y-4">
        <Link to="/mi-historial" className="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700">
          <ArrowLeft size={15} /> Volver a Mi Historial
        </Link>
        <div className="bg-red-50 border border-red-200 rounded-xl p-6 text-center">
          <AlertCircle size={20} className="mx-auto mb-2 text-red-500" />
          <p className="text-sm text-red-700">No se pudo cargar el reporte.</p>
        </div>
      </div>
    )
  }

  if (!canEdit) {
    return (
      <div className="space-y-4">
        <Link to="/mi-historial" className="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700">
          <ArrowLeft size={15} /> Volver a Mi Historial
        </Link>
        <div className="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
          <AlertCircle size={20} className="mx-auto mb-2 text-yellow-600" />
          <p className="text-sm text-yellow-800 font-medium">Este reporte no tiene edición aprobada.</p>
          <p className="text-xs text-yellow-700 mt-1">
            Estado actual: <span className="font-semibold">{reporte.estado_edicion}</span>
          </p>
        </div>
      </div>
    )
  }

  if (success) {
    return (
      <div className="flex flex-col items-center justify-center h-64 gap-3">
        <CheckCircle2 size={40} className="text-green-500" />
        <p className="text-sm font-medium text-gray-700">Reporte actualizado correctamente.</p>
        <p className="text-xs text-gray-400">Redirigiendo a Mi Historial...</p>
      </div>
    )
  }

  const motivo = reporte.motivo_edicion

  return (
    <div className="space-y-6 max-w-2xl">
      {/* Breadcrumb */}
      <div className="flex items-center gap-2">
        <Link to="/mi-historial" className="flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700">
          <ArrowLeft size={14} /> Mi Historial
        </Link>
        <span className="text-gray-300">/</span>
        <span className="text-sm font-medium text-gray-700">
          Editar Reporte #{String(reporteId).padStart(4, '0')}
        </span>
      </div>

      {/* Banner: edición aprobada */}
      <div className="bg-indigo-50 border border-indigo-200 rounded-xl p-4 flex items-start gap-3">
        <CheckCircle2 size={18} className="text-indigo-500 mt-0.5 shrink-0" />
        <div>
          <p className="text-sm font-semibold text-indigo-800">Edición aprobada por administración</p>
          {motivo && (
            <p className="text-xs text-indigo-700 mt-0.5">
              Motivo registrado: <em>"{motivo}"</em>
            </p>
          )}
        </div>
      </div>

      {/* Datos actuales — resumen de referencia */}
      <div className="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
        <h2 className="text-sm font-semibold text-gray-900">Datos actuales del reporte</h2>
        <div className="grid grid-cols-2 gap-4 text-sm">
          <div>
            <p className="text-xs text-gray-500 mb-0.5">Fecha</p>
            <p className="font-medium text-gray-800">
              {new Date(reporte.fecha + 'T00:00:00').toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long' })}
            </p>
          </div>
          <div>
            <p className="text-xs text-gray-500 mb-0.5">Total calculado</p>
            <p className="font-mono font-bold text-gray-900">{fmt(reporte.total_calculado)}</p>
          </div>
          <div>
            <p className="text-xs text-gray-500 mb-0.5">Efectivo esperado (sistema)</p>
            <p className="font-mono text-gray-700">{fmt(reporte.efectivo_esperado)}</p>
          </div>
          <div>
            <p className="text-xs text-gray-500 mb-0.5">Diferencia actual</p>
            <p className={`font-mono font-bold ${Number(reporte.diferencia) < 0 ? 'text-red-600' : Number(reporte.diferencia) > 0 ? 'text-yellow-600' : 'text-gray-600'}`}>
              {Number(reporte.diferencia) > 0 ? '+' : ''}{fmt(reporte.diferencia)}
            </p>
          </div>
        </div>
      </div>

      {/* Formulario de edición */}
      <div className="bg-white rounded-xl border border-gray-200 p-5 space-y-5">
        <h2 className="text-sm font-semibold text-gray-900">Campos a corregir</h2>

        {/* Efectivo entregado */}
        <div>
          <label className="block text-xs font-medium text-gray-600 mb-1.5">
            Efectivo entregado físico
            <span className="ml-1 text-gray-400 font-normal">(valor que declaras haber entregado)</span>
          </label>
          <div className="relative">
            <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">S/</span>
            <input
              type="number"
              step="0.01"
              min="0"
              value={form.efectivo_entregado}
              onChange={(e) => setForm((f) => ({ ...f, efectivo_entregado: e.target.value }))}
              className="w-full border border-gray-300 rounded-md pl-8 pr-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
            />
          </div>
        </div>

        {/* Destino efectivo */}
        <div>
          <label className="block text-xs font-medium text-gray-600 mb-1.5">Destino del efectivo</label>
          <select
            value={form.destino_efectivo}
            onChange={(e) => setForm((f) => ({ ...f, destino_efectivo: e.target.value }))}
            className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >
            {DESTINOS.map((d) => <option key={d} value={d}>{d}</option>)}
          </select>
        </div>

        {/* Observaciones */}
        <div>
          <label className="block text-xs font-medium text-gray-600 mb-1.5">Observaciones (opcional)</label>
          <textarea
            rows={2}
            value={form.observaciones}
            onChange={(e) => setForm((f) => ({ ...f, observaciones: e.target.value }))}
            placeholder="Notas adicionales sobre el reporte..."
            className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"
          />
        </div>

        {/* Motivo de la corrección */}
        <div>
          <label className="block text-xs font-medium text-gray-600 mb-1.5">
            Motivo de esta corrección
            <span className="text-red-500 ml-1">*</span>
          </label>
          <textarea
            rows={3}
            value={form.motivo_edicion}
            onChange={(e) => setForm((f) => ({ ...f, motivo_edicion: e.target.value }))}
            placeholder="Explica brevemente qué fue lo que se corrigió y por qué..."
            className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"
          />
        </div>

        {saveError && (
          <div className="bg-red-50 border border-red-200 rounded-lg p-3 text-xs text-red-700 flex items-center gap-2">
            <AlertCircle size={14} />
            {(saveError as any)?.response?.data?.error ?? 'Error al guardar. Intenta de nuevo.'}
          </div>
        )}

        <div className="flex items-center justify-between pt-2 border-t border-gray-100">
          <Link to="/mi-historial">
            <Button variant="outline">Cancelar</Button>
          </Link>
          <Button
            disabled={isPending || !form.motivo_edicion.trim() || !form.efectivo_entregado}
            onClick={() => mutate()}
            className="gap-2"
          >
            <Save size={14} />
            {isPending ? 'Guardando...' : 'Guardar corrección'}
          </Button>
        </div>
      </div>
    </div>
  )
}
