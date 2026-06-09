import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../../services/api'
import { useAuth } from '../../hooks/useAuth'
import { Button } from '../../components/ui/button'
import { CreditCard, AlertTriangle, CheckCircle } from 'lucide-react'

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })

const TURNOS = ['MAÑANA', 'TARDE', 'NOCHE', 'COMPLETO']

interface BcpData {
  warning?: string
  kpis: { total_efectivo: number; total_tarjeta: number; total_operaciones: number; total_registros: number }
  alertas: { sucursal_id: number; nombre_tienda: string; total_ops: number }[]
  reportes: {
    id: number; fecha: string; nombre_tienda: string; nombre_agente: string
    turno: string; agente_nombre_bcp: string | null
    cantidad_operaciones: number; queda_efectivo: number; queda_tarjeta: number
    incidencias_sistema: string | null
  }[]
}

export function ReporteBcpPage() {
  const { usuario } = useAuth()
  const qc = useQueryClient()
  const esAdmin = usuario?.rol === 'admin'

  const [filters, setFilters] = useState({
    fecha_desde: new Date().toISOString().slice(0, 10),
    fecha_hasta: new Date().toISOString().slice(0, 10),
    tienda_id: '',
  })
  const [applied, setApplied] = useState({ ...filters })

  const [form, setForm] = useState({
    fecha: new Date().toISOString().slice(0, 10),
    turno_hora: 'MAÑANA',
    nombre_agente_bcp: '',
    cantidad_operaciones: '',
    queda_efectivo: '',
    queda_tarjeta: '',
    incidencias_sistema: '',
  })
  const [success, setSuccess] = useState('')
  const [error, setError] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['reporte-bcp', applied],
    queryFn: () => api.get<BcpData>('/v1/reporte-bcp', { params: applied }).then(r => r.data),
    enabled: esAdmin,
  })

  const enviar = useMutation({
    mutationFn: (payload: Record<string, unknown>) =>
      api.post('/v1/reporte-bcp', payload).then(r => r.data),
    onSuccess: (res) => {
      setSuccess(res.message ?? 'Reporte BCP enviado.')
      setError('')
      setForm(f => ({ ...f, cantidad_operaciones: '', queda_efectivo: '', queda_tarjeta: '', incidencias_sistema: '', nombre_agente_bcp: '' }))
      qc.invalidateQueries({ queryKey: ['reporte-bcp'] })
    },
    onError: (err: any) => {
      setError(err?.response?.data?.message ?? 'Error al enviar reporte.')
    },
  })

  if (data?.warning && !esAdmin) {
    return (
      <div className="flex items-center gap-3 p-4 bg-yellow-50 border border-yellow-200 rounded-xl text-yellow-800 text-sm">
        <AlertTriangle size={18} /> {data.warning}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <h1 className="text-xl font-semibold text-gray-900 flex items-center gap-2">
        <CreditCard size={20} className="text-blue-600" /> Módulo BCP
      </h1>

      {data?.warning && (
        <div className="flex items-center gap-3 p-4 bg-yellow-50 border border-yellow-200 rounded-xl text-yellow-800 text-sm">
          <AlertTriangle size={18} /> {data.warning}
        </div>
      )}

      {/* Agente: formulario de envío */}
      {!esAdmin && !data?.warning && (
        <div className="bg-white rounded-xl border border-gray-200 p-6 max-w-xl">
          <h2 className="font-semibold text-gray-800 mb-4 text-sm">Enviar Reporte BCP</h2>
          {success && (
            <div className="flex items-center gap-2 p-3 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm mb-4">
              <CheckCircle size={16} /> {success}
            </div>
          )}
          {error && (
            <div className="p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm mb-4">
              {error}
            </div>
          )}
          <div className="space-y-3">
            <div>
              <label className="block text-xs text-gray-500 mb-1">Fecha</label>
              <input type="date" value={form.fecha}
                onChange={e => setForm(f => ({ ...f, fecha: e.target.value }))}
                className="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-xs text-gray-500 mb-1">Turno</label>
              <select value={form.turno_hora} onChange={e => setForm(f => ({ ...f, turno_hora: e.target.value }))}
                className="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                {TURNOS.map(t => <option key={t} value={t}>{t}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-xs text-gray-500 mb-1">Nombre del Agente BCP</label>
              <input type="text" placeholder="Nombre en sistema BCP" value={form.nombre_agente_bcp}
                onChange={e => setForm(f => ({ ...f, nombre_agente_bcp: e.target.value }))}
                className="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-xs text-gray-500 mb-1">Cantidad de Operaciones</label>
              <input type="number" min="0" value={form.cantidad_operaciones}
                onChange={e => setForm(f => ({ ...f, cantidad_operaciones: e.target.value }))}
                className="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs text-gray-500 mb-1">Queda Efectivo (S/)</label>
                <input type="number" min="0" step="0.01" value={form.queda_efectivo}
                  onChange={e => setForm(f => ({ ...f, queda_efectivo: e.target.value }))}
                  className="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div>
                <label className="block text-xs text-gray-500 mb-1">Queda Tarjeta (S/)</label>
                <input type="number" min="0" step="0.01" value={form.queda_tarjeta}
                  onChange={e => setForm(f => ({ ...f, queda_tarjeta: e.target.value }))}
                  className="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
            </div>
            <div>
              <label className="block text-xs text-gray-500 mb-1">Incidencias del Sistema</label>
              <textarea rows={2} value={form.incidencias_sistema}
                onChange={e => setForm(f => ({ ...f, incidencias_sistema: e.target.value }))}
                placeholder="Opcional..."
                className="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
              />
            </div>
            <Button
              disabled={enviar.isPending || !form.cantidad_operaciones}
              onClick={() => enviar.mutate({
                fecha: form.fecha,
                sucursal_id: 0,
                turno_hora: form.turno_hora,
                nombre_agente_bcp: form.nombre_agente_bcp || undefined,
                cantidad_operaciones: Number(form.cantidad_operaciones),
                queda_efectivo: Number(form.queda_efectivo || 0),
                queda_tarjeta: Number(form.queda_tarjeta || 0),
                incidencias_sistema: form.incidencias_sistema || undefined,
              })}
            >
              {enviar.isPending ? 'Enviando...' : 'Enviar Reporte BCP'}
            </Button>
          </div>
        </div>
      )}

      {/* Admin: filtros + tabla */}
      {esAdmin && (
        <>
          {/* Filtros */}
          <div className="bg-white rounded-xl border border-gray-200 p-4">
            <div className="flex flex-wrap items-end gap-3">
              <div>
                <label className="block text-xs text-gray-500 mb-1">Desde</label>
                <input type="date" value={filters.fecha_desde}
                  onChange={e => setFilters(f => ({ ...f, fecha_desde: e.target.value }))}
                  className="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div>
                <label className="block text-xs text-gray-500 mb-1">Hasta</label>
                <input type="date" value={filters.fecha_hasta}
                  onChange={e => setFilters(f => ({ ...f, fecha_hasta: e.target.value }))}
                  className="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <Button onClick={() => setApplied({ ...filters })}>Buscar</Button>
            </div>
          </div>

          {/* KPIs */}
          {data?.kpis && (
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
              {[
                { label: 'Total Efectivo',    value: pen.format(data.kpis.total_efectivo) },
                { label: 'Total Tarjeta',     value: pen.format(data.kpis.total_tarjeta) },
                { label: 'Total Operaciones', value: String(data.kpis.total_operaciones) },
                { label: 'Registros',         value: String(data.kpis.total_registros) },
              ].map(k => (
                <div key={k.label} className="bg-white rounded-xl border border-gray-200 p-4">
                  <p className="text-xs text-gray-500 mb-1">{k.label}</p>
                  <p className="text-xl font-bold text-gray-900">{k.value}</p>
                </div>
              ))}
            </div>
          )}

          {/* Alertas */}
          {(data?.alertas ?? []).length > 0 && (
            <div className="bg-amber-50 border border-amber-200 rounded-xl p-4">
              <h3 className="font-semibold text-amber-800 text-sm mb-2 flex items-center gap-1.5">
                <AlertTriangle size={15} /> Tiendas con menos de 200 operaciones hoy
              </h3>
              <div className="flex flex-wrap gap-2">
                {data!.alertas.map(a => (
                  <span key={a.sucursal_id} className="text-xs bg-amber-100 text-amber-800 px-2 py-1 rounded-full font-medium">
                    {a.nombre_tienda ?? `Tienda #${a.sucursal_id}`}: {a.total_ops} ops
                  </span>
                ))}
              </div>
            </div>
          )}

          {/* Tabla */}
          <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div className="p-4 border-b border-gray-200">
              <h2 className="font-semibold text-gray-800 text-sm">
                {isLoading ? 'Cargando...' : `${data?.reportes?.length ?? 0} registros`}
              </h2>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="bg-gray-50 border-b border-gray-200">
                    {['Fecha', 'Tienda', 'Agente', 'Turno', 'Operaciones', 'Efectivo', 'Tarjeta', 'Incidencias'].map(h => (
                      <th key={h} className="px-4 py-3 text-xs font-semibold text-gray-500 text-left">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {isLoading && <tr><td colSpan={8} className="px-4 py-10 text-center text-gray-400">Cargando...</td></tr>}
                  {!isLoading && (data?.reportes ?? []).length === 0 && (
                    <tr><td colSpan={8} className="px-4 py-10 text-center text-gray-400">Sin registros para el período</td></tr>
                  )}
                  {(data?.reportes ?? []).map(r => (
                    <tr key={r.id} className={`border-b border-gray-100 hover:bg-gray-50/60 ${r.cantidad_operaciones < 200 ? 'bg-amber-50/30' : ''}`}>
                      <td className="px-4 py-3">{new Date(r.fecha + 'T00:00:00').toLocaleDateString('es-PE')}</td>
                      <td className="px-4 py-3 font-mono text-xs text-slate-600">{r.nombre_tienda ?? '—'}</td>
                      <td className="px-4 py-3">{r.agente_nombre_bcp ?? r.nombre_agente ?? '—'}</td>
                      <td className="px-4 py-3 text-xs">{r.turno}</td>
                      <td className={`px-4 py-3 font-bold ${r.cantidad_operaciones < 200 ? 'text-amber-600' : 'text-gray-800'}`}>
                        {r.cantidad_operaciones}
                      </td>
                      <td className="px-4 py-3 font-mono text-gray-700">{pen.format(r.queda_efectivo)}</td>
                      <td className="px-4 py-3 font-mono text-gray-700">{pen.format(r.queda_tarjeta)}</td>
                      <td className="px-4 py-3 text-xs text-gray-500 max-w-xs truncate">{r.incidencias_sistema ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </div>
  )
}
