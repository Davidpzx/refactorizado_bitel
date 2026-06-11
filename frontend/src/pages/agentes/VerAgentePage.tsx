import { useParams, Link } from 'react-router-dom'
import { useQuery, useMutation } from '@tanstack/react-query'
import { api } from '../../services/api'
import type { Agente } from '../../types/agente'
import type { Reporte } from '../../types/reporte'
import type { PaginatedResponse } from '../../types/pagination'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { ArrowLeft, User, MapPin, DollarSign, Phone, Mail, Calendar, Key, ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useAuth } from '../../hooks/useAuth'

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })
const fmt = (v: number | string | null | undefined) => pen.format(Number(v ?? 0))

interface AgenteVentasResponse {
  agente: Agente
  stats: {
    total_reportes: number
    total_vendido: string
    diferencia_acumulada: string
  }
  reportes: PaginatedResponse<Reporte>
}

interface TokenResult {
  success: boolean
  token?: string
  expiracion?: string
  tipo?: string
  accion?: string
}

function FechasLaboralesPanel({ agenteId, agente }: { agenteId: string; agente: Agente }) {
  const [fechaIngreso,      setFechaIngreso]      = useState((agente as any).fecha_ingreso ?? '')
  const [fechaPruebaInicio, setFechaPruebaInicio] = useState((agente as any).fecha_prueba_inicio ?? '')
  const [fechaPruebaFin,    setFechaPruebaFin]    = useState((agente as any).fecha_prueba_fin ?? '')
  const [msg, setMsg]                              = useState<string | null>(null)

  const mut = useMutation({
    mutationFn: (data: Record<string, string>) =>
      api.patch(`/v1/agentes/${agenteId}/fechas-laborales`, data).then(r => r.data),
    onSuccess: (res: any) => setMsg(res.msg ?? 'Fechas actualizadas.'),
    onError: () => setMsg('Error al guardar fechas.'),
  })

  return (
    <div className="bg-white rounded-xl border border-gray-200 p-5">
      <h3 className="font-semibold text-sm text-gray-900 mb-4 flex items-center gap-2">
        <Calendar size={15} className="text-gray-500" /> Fechas Laborales
      </h3>
      <div className="grid grid-cols-3 gap-4">
        <div>
          <label className="block text-xs text-gray-500 mb-1">Fecha ingreso</label>
          <Input type="date" value={fechaIngreso} onChange={e => setFechaIngreso(e.target.value)} />
        </div>
        <div>
          <label className="block text-xs text-gray-500 mb-1">Inicio prueba</label>
          <Input type="date" value={fechaPruebaInicio} onChange={e => setFechaPruebaInicio(e.target.value)} />
        </div>
        <div>
          <label className="block text-xs text-gray-500 mb-1">Fin prueba</label>
          <Input type="date" value={fechaPruebaFin} onChange={e => setFechaPruebaFin(e.target.value)} />
        </div>
      </div>
      {msg && <p className={`mt-2 text-xs ${msg.startsWith('Error') ? 'text-red-500' : 'text-green-600'}`}>{msg}</p>}
      <div className="mt-3 flex justify-end">
        <Button
          size="sm"
          disabled={mut.isPending}
          onClick={() => {
            setMsg(null)
            mut.mutate({
              fecha_ingreso:       fechaIngreso       || '',
              fecha_prueba_inicio: fechaPruebaInicio  || '',
              fecha_prueba_fin:    fechaPruebaFin     || '',
            })
          }}
        >
          {mut.isPending ? 'Guardando...' : 'Guardar fechas'}
        </Button>
      </div>
    </div>
  )
}

function TokenSeguridadPanel({ agenteId }: { agenteId: string }) {
  const [resultado, setResultado] = useState<TokenResult | null>(null)
  const [msg, setMsg]             = useState<string | null>(null)

  const mut = useMutation({
    mutationFn: (tipo: string) =>
      api.post<TokenResult>(`/v1/agentes/${agenteId}/token-seguridad`, { tipo }).then(r => r.data),
    onSuccess: (data) => {
      setResultado(data)
      if (data.accion === 'revocado') setMsg('Token revocado.')
      else setMsg(null)
    },
    onError: () => setMsg('Error al procesar la solicitud.'),
  })

  return (
    <div className="bg-white rounded-xl border border-gray-200 p-5">
      <h3 className="font-semibold text-sm text-gray-900 mb-4 flex items-center gap-2">
        <ShieldCheck size={15} className="text-gray-500" /> Token de Seguridad
      </h3>

      {resultado?.token && (
        <div className="mb-4 p-3 rounded-lg bg-blue-50 border border-blue-200">
          <p className="text-xs text-blue-600 mb-1">Token generado ({resultado.tipo}):</p>
          <p className="font-mono text-2xl font-bold text-blue-800 tracking-widest">{resultado.token}</p>
          <p className="text-xs text-blue-500 mt-1">Expira: {resultado.expiracion}</p>
        </div>
      )}

      {msg && <p className="mb-3 text-xs text-gray-500">{msg}</p>}

      <div className="flex flex-wrap gap-2">
        <Button size="sm" variant="outline" disabled={mut.isPending} onClick={() => mut.mutate('diario')}>
          <Key size={13} className="mr-1" /> Generar diario
        </Button>
        <Button size="sm" variant="outline" disabled={mut.isPending} onClick={() => mut.mutate('permanente')}>
          <Key size={13} className="mr-1" /> Generar permanente
        </Button>
        <Button
          size="sm"
          variant="outline"
          disabled={mut.isPending}
          className="text-red-600 border-red-200 hover:bg-red-50"
          onClick={() => { setResultado(null); mut.mutate('revocar') }}
        >
          Revocar token
        </Button>
      </div>
    </div>
  )
}

export function VerAgentePage() {
  const { id }       = useParams<{ id: string }>()
  const { usuario }  = useAuth()
  const isAdmin      = usuario?.rol === 'admin'
  const [page, setPage] = useState(1)

  const { data, isLoading } = useQuery({
    queryKey: ['agente-ventas', id, page],
    queryFn: () =>
      api.get<AgenteVentasResponse>(`/v1/agentes/${id}/ventas`, { params: { page, per_page: 15 } }).then(r => r.data),
    enabled: !!id,
  })

  const agente  = data?.agente
  const stats   = data?.stats
  const reportes = data?.reportes?.data ?? []
  const meta    = data?.reportes

  if (isLoading) {
    return <div className="flex items-center justify-center h-64 text-gray-400 text-sm">Cargando perfil...</div>
  }

  if (!agente) {
    return (
      <div className="text-center py-12 space-y-4">
        <p className="text-gray-500">Agente no encontrado</p>
        <Link to="/agentes"><Button variant="outline"><ArrowLeft size={14} /> Volver a Agentes</Button></Link>
      </div>
    )
  }

  const initials = agente.nombres
    .split(' ')
    .slice(0, 2)
    .map((p: string) => p[0])
    .join('')
    .toUpperCase()

  const estadoColor = agente.estado === 'ACTIVO' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'
  const dif = Number(stats?.diferencia_acumulada ?? 0)

  return (
    <div className="space-y-6">
      <Link to="/agentes" className="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-800">
        <ArrowLeft size={15} /> Volver a Agentes
      </Link>

      {/* Profile header */}
      <div className="bg-white rounded-xl border border-gray-200 p-6">
        <div className="flex items-start gap-5 flex-wrap">
          <div className="w-16 h-16 rounded-full bg-blue-600 flex items-center justify-center text-white text-2xl font-bold shrink-0">
            {initials}
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-3 flex-wrap">
              <h1 className="text-xl font-bold text-gray-900">{agente.nombres}</h1>
              <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${estadoColor}`}>{agente.estado}</span>
              {agente.es_gerencia && String(agente.es_gerencia) !== 'NO' && (
                <span className="text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 font-medium uppercase">
                  {agente.es_gerencia}
                </span>
              )}
            </div>
            <div className="mt-2 flex flex-wrap gap-4 text-sm text-gray-500">
              {agente.dni && (
                <span className="flex items-center gap-1"><User size={13} /> DNI: {agente.dni}</span>
              )}
              {agente.tienda_base && (
                <span className="flex items-center gap-1"><MapPin size={13} /> Tienda: {agente.tienda_base}</span>
              )}
              {agente.correo && (
                <span className="flex items-center gap-1"><Mail size={13} /> {agente.correo}</span>
              )}
              {agente.telefono && (
                <span className="flex items-center gap-1"><Phone size={13} /> {agente.telefono}</span>
              )}
              {agente.fecha_ingreso && (
                <span className="flex items-center gap-1">
                  <Calendar size={13} /> Ingreso: {new Date(agente.fecha_ingreso + 'T00:00:00').toLocaleDateString('es-PE')}
                </span>
              )}
            </div>
          </div>
          <div className="text-right">
            <p className="text-xs text-gray-400">Sueldo Base</p>
            <p className="text-lg font-bold text-gray-900">{fmt(agente.sueldo_base)}</p>
          </div>
        </div>
      </div>

      {/* Stats */}
      {stats && (
        <div className="grid grid-cols-3 gap-4">
          <div className="bg-white rounded-xl border border-gray-200 p-4">
            <p className="text-xs text-gray-500 mb-1">Total Reportes</p>
            <p className="text-xl font-bold text-gray-900">{stats.total_reportes}</p>
          </div>
          <div className="bg-white rounded-xl border border-blue-200 p-4">
            <div className="flex items-center gap-1 mb-1">
              <DollarSign size={13} className="text-blue-500" />
              <p className="text-xs text-gray-500">Total Vendido</p>
            </div>
            <p className="text-xl font-bold text-blue-700">{fmt(stats.total_vendido)}</p>
          </div>
          <div className={`bg-white rounded-xl border p-4 ${dif < 0 ? 'border-red-200' : dif > 0 ? 'border-yellow-200' : 'border-gray-200'}`}>
            <p className="text-xs text-gray-500 mb-1">Diferencia Acumulada</p>
            <p className={`text-xl font-bold ${dif < 0 ? 'text-red-600' : dif > 0 ? 'text-yellow-600' : 'text-gray-600'}`}>
              {dif > 0 ? '+' : ''}{fmt(dif)}
            </p>
          </div>
        </div>
      )}

      {/* Paneles admin */}
      {isAdmin && id && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <FechasLaboralesPanel agenteId={id} agente={agente} />
          <TokenSeguridadPanel agenteId={id} />
        </div>
      )}

      {/* Reports history */}
      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div className="p-4 border-b border-gray-200 flex items-center justify-between">
          <h2 className="font-semibold text-gray-900 text-sm">Historial de Reportes</h2>
          <span className="text-xs text-gray-400">
            {meta?.total ?? 0} reportes · Pág. {meta?.current_page ?? 1}/{meta?.last_page ?? 1}
          </span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-gray-50 border-b border-gray-200">
                {['ID', 'Fecha', 'Tienda', 'Total', 'F. Entregado', 'Diferencia', 'Estado', ''].map(h => (
                  <th key={h} className={`px-4 py-3 text-xs font-semibold text-gray-500 ${['Total', 'F. Entregado', 'Diferencia'].includes(h) ? 'text-right' : 'text-left'}`}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {reportes.length === 0 && (
                <tr><td colSpan={8} className="px-4 py-10 text-center text-gray-400">Sin reportes</td></tr>
              )}
              {reportes.map(r => {
                const difR = Number(r.diferencia)
                return (
                  <tr key={r.id} className={`border-b ${difR < 0 ? 'border-red-100 bg-red-50/30' : 'border-gray-100 hover:bg-gray-50/60'}`}>
                    <td className="px-4 py-3 font-mono text-xs text-gray-600">#{String(r.id).padStart(4, '0')}</td>
                    <td className="px-4 py-3 text-gray-700">{new Date(r.fecha + 'T00:00:00').toLocaleDateString('es-PE')}</td>
                    <td className="px-4 py-3"><span className="text-xs bg-slate-100 text-slate-700 px-1.5 py-0.5 rounded font-mono">{r.tienda_id}</span></td>
                    <td className="px-4 py-3 text-right font-mono text-gray-800">{fmt(r.total_calculado)}</td>
                    <td className="px-4 py-3 text-right font-mono text-gray-800">{fmt(r.efectivo_entregado)}</td>
                    <td className="px-4 py-3 text-right">
                      <span className={`inline-block px-2 py-0.5 rounded-md text-xs font-bold ${difR === 0 ? 'bg-gray-100 text-gray-600' : difR < 0 ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'}`}>
                        {difR > 0 ? '+' : ''}{fmt(difR)}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <span className={`inline-block px-2 py-0.5 text-xs rounded-full font-medium ${{ borrador: 'bg-gray-100 text-gray-600', enviado: 'bg-blue-100 text-blue-700', editado: 'bg-orange-100 text-orange-700', aprobado: 'bg-green-100 text-green-700' }[r.estado] ?? 'bg-gray-100 text-gray-500'}`}>
                        {r.estado}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <Link to={`/reportes/${r.id}`} className="text-xs text-blue-600 hover:text-blue-800">Ver</Link>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
        {meta && meta.last_page > 1 && (
          <div className="p-4 border-t border-gray-200 flex items-center justify-between">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>
              <ChevronLeft size={14} /> Anterior
            </Button>
            <span className="text-xs text-gray-500">{meta.from}–{meta.to} de {meta.total}</span>
            <Button variant="outline" size="sm" disabled={page >= meta.last_page} onClick={() => setPage(p => p + 1)}>
              Siguiente <ChevronRight size={14} />
            </Button>
          </div>
        )}
      </div>
    </div>
  )
}
