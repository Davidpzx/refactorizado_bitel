import { useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { useQuery, useMutation } from '@tanstack/react-query'
import { ArrowLeft, Save, AlertCircle, CheckCircle2 } from 'lucide-react'
import { reportesApi } from '../../services/reportes.api'
import { Button } from '../../components/ui/button'
import { PageHeader } from '../../components/PageHeader'

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
      <div className="flex items-center justify-center h-64 text-sm text-kyro-muted">
        Cargando reporte...
      </div>
    )
  }

  if (loadError || !reporte) {
    return (
      <div className="space-y-4">
        <Link to="/mi-historial" className="flex items-center gap-2 text-sm text-kyro-muted hover:text-kyro-gold">
          <ArrowLeft size={15} /> Volver a Mi Historial
        </Link>
        <div className="kyro-card bg-kyro-danger/10 border border-kyro-danger/30 rounded-kyro-xl p-6 text-center">
          <AlertCircle size={20} className="mx-auto mb-2 text-kyro-danger" />
          <p className="text-sm text-kyro-danger">No se pudo cargar el reporte.</p>
        </div>
      </div>
    )
  }

  if (!canEdit) {
    return (
      <div className="space-y-4">
        <Link to="/mi-historial" className="flex items-center gap-2 text-sm text-kyro-muted hover:text-kyro-gold">
          <ArrowLeft size={15} /> Volver a Mi Historial
        </Link>
        <div className="kyro-card bg-kyro-warning/10 border border-kyro-warning/30 rounded-kyro-xl p-6 text-center">
          <AlertCircle size={20} className="mx-auto mb-2 text-kyro-warning" />
          <p className="text-sm text-kyro-warning font-medium">Este reporte no tiene edición aprobada.</p>
          <p className="text-xs text-kyro-warning mt-1">
            Estado actual: <span className="font-semibold">{reporte.estado_edicion}</span>
          </p>
        </div>
      </div>
    )
  }

  if (success) {
    return (
      <div className="flex flex-col items-center justify-center h-64 gap-3">
        <CheckCircle2 size={40} className="text-kyro-success" />
        <p className="text-sm font-medium text-kyro-text">Reporte actualizado correctamente.</p>
        <p className="text-xs text-kyro-muted">Redirigiendo a Mi Historial...</p>
      </div>
    )
  }

  const motivo = reporte.motivo_edicion

  return (
    <div className="space-y-6 max-w-2xl">
      {/* Breadcrumb */}
      <div className="flex items-center gap-2">
        <Link to="/mi-historial" className="flex items-center gap-1.5 text-sm text-kyro-muted hover:text-kyro-gold">
          <ArrowLeft size={14} /> Mi Historial
        </Link>
        <span className="text-kyro-subtle">/</span>
        <span className="text-sm font-medium text-kyro-body">
          Editar Reporte #{String(reporteId).padStart(4, '0')}
        </span>
      </div>

      <PageHeader
        title={`Editar Reporte #${String(reporteId).padStart(4, '0')}`}
        subtitle="Corrige únicamente los campos autorizados por administración"
        accent="var(--color-kyro-indigo)"
      />

      {/* Banner: edición aprobada */}
      <div className="kyro-card bg-kyro-indigo/10 border border-kyro-indigo/30 rounded-kyro-xl p-4 flex items-start gap-3">
        <CheckCircle2 size={18} className="text-kyro-indigo mt-0.5 shrink-0" />
        <div>
          <p className="text-sm font-semibold text-kyro-text">Edición aprobada por administración</p>
          {motivo && (
            <p className="text-xs text-kyro-body mt-0.5">
              Motivo registrado: <em>"{motivo}"</em>
            </p>
          )}
        </div>
      </div>

      {/* Datos actuales — resumen de referencia */}
      <div className="kyro-card space-y-4 p-5">
        <h2 className="text-sm font-semibold text-kyro-text">Datos actuales del reporte</h2>
        <div className="grid grid-cols-2 gap-4 text-sm">
          <div>
            <p className="text-xs text-kyro-muted mb-0.5">Fecha</p>
            <p className="font-medium text-kyro-body">
              {new Date(reporte.fecha + 'T00:00:00').toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long' })}
            </p>
          </div>
          <div>
            <p className="text-xs text-kyro-muted mb-0.5">Total calculado</p>
            <p className="font-mono font-bold text-kyro-text">{fmt(reporte.total_calculado)}</p>
          </div>
          <div>
            <p className="text-xs text-kyro-muted mb-0.5">Efectivo esperado (sistema)</p>
            <p className="font-mono text-kyro-body">{fmt(reporte.efectivo_esperado)}</p>
          </div>
          <div>
            <p className="text-xs text-kyro-muted mb-0.5">Diferencia actual</p>
            <p className={`font-mono font-bold ${Number(reporte.diferencia) < 0 ? 'text-kyro-danger' : Number(reporte.diferencia) > 0 ? 'text-kyro-warning' : 'text-kyro-muted'}`}>
              {Number(reporte.diferencia) > 0 ? '+' : ''}{fmt(reporte.diferencia)}
            </p>
          </div>
        </div>
      </div>

      {/* Formulario de edición */}
      <div className="kyro-card space-y-5 p-5">
        <h2 className="text-sm font-semibold text-kyro-text">Campos a corregir</h2>

        {/* Efectivo entregado */}
        <div>
          <label className="block text-xs font-medium text-kyro-body mb-1.5">
            Efectivo entregado físico
            <span className="ml-1 text-kyro-muted font-normal">(valor que declaras haber entregado)</span>
          </label>
          <div className="relative">
            <span className="absolute left-3 top-1/2 -translate-y-1/2 text-kyro-muted text-sm">S/</span>
            <input
              type="number"
              step="0.01"
              min="0"
              value={form.efectivo_entregado}
              onChange={(e) => setForm((f) => ({ ...f, efectivo_entregado: e.target.value }))}
              className="kyro-input w-full pl-8 pr-3 py-2 text-sm"
            />
          </div>
        </div>

        {/* Destino efectivo */}
        <div>
          <label className="block text-xs font-medium text-kyro-body mb-1.5">Destino del efectivo</label>
          <select
            value={form.destino_efectivo}
            onChange={(e) => setForm((f) => ({ ...f, destino_efectivo: e.target.value }))}
            className="kyro-input w-full px-3 py-2 text-sm"
          >
            {DESTINOS.map((d) => <option key={d} value={d}>{d}</option>)}
          </select>
        </div>

        {/* Observaciones */}
        <div>
          <label className="block text-xs font-medium text-kyro-body mb-1.5">Observaciones (opcional)</label>
          <textarea
            rows={2}
            value={form.observaciones}
            onChange={(e) => setForm((f) => ({ ...f, observaciones: e.target.value }))}
            placeholder="Notas adicionales sobre el reporte..."
            className="kyro-input w-full px-3 py-2 text-sm resize-none"
          />
        </div>

        {/* Motivo de la corrección */}
        <div>
          <label className="block text-xs font-medium text-kyro-body mb-1.5">
            Motivo de esta corrección
            <span className="text-kyro-danger ml-1">*</span>
          </label>
          <textarea
            rows={3}
            value={form.motivo_edicion}
            onChange={(e) => setForm((f) => ({ ...f, motivo_edicion: e.target.value }))}
            placeholder="Explica brevemente qué fue lo que se corrigió y por qué..."
            className="kyro-input w-full px-3 py-2 text-sm resize-none"
          />
        </div>

        {saveError && (
          <div className="bg-kyro-danger/10 border border-kyro-danger/30 rounded-kyro-lg p-3 text-xs text-kyro-danger flex items-center gap-2">
            <AlertCircle size={14} />
            {(saveError as any)?.response?.data?.error ?? 'Error al guardar. Intenta de nuevo.'}
          </div>
        )}

        <div className="flex items-center justify-between pt-2 border-t border-kyro-border">
          <Link to="/mi-historial">
            <Button variant="outline">Cancelar</Button>
          </Link>
          <Button
            disabled={isPending || !form.motivo_edicion.trim() || !form.efectivo_entregado}
            onClick={() => mutate()}
            className="gap-2 bg-kyro-gold text-kyro-gold-ink border-kyro-gold hover:brightness-110"
          >
            <Save size={14} />
            {isPending ? 'Guardando...' : 'Guardar corrección'}
          </Button>
        </div>
      </div>
    </div>
  )
}
