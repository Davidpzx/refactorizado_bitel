import { useParams, Link } from 'react-router-dom'
import { useQuery, useMutation } from '@tanstack/react-query'
import { api } from '../../services/api'
import type { Agente } from '../../types/agente'
import type { Reporte } from '../../types/reporte'
import type { PaginatedResponse } from '../../types/pagination'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { ArrowLeft, User, MapPin, DollarSign, Phone, Mail, Calendar, Key, ShieldCheck, FileText, TrendingUp } from 'lucide-react'
import { useState } from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useAuth } from '../../hooks/useAuth'

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })
const fmt = (v: number | string | null | undefined) => pen.format(Number(v ?? 0))

interface AgenteVentasResponse {
  agente: AgenteLaboral
  stats: {
    total_reportes: number
    total_vendido: string
    diferencia_acumulada: string
  }
  reportes: PaginatedResponse<Reporte>
}

interface AgenteLaboral extends Agente {
  fecha_prueba_inicio?: string | null
  fecha_prueba_fin?: string | null
}

interface TokenResult {
  success: boolean
  token?: string
  expiracion?: string
  tipo?: string
  accion?: string
}

interface FechasLaboralesResult {
  msg?: string
}

function FechasLaboralesPanel({ agenteId, agente }: { agenteId: string; agente: AgenteLaboral }) {
  const [fechaIngreso,      setFechaIngreso]      = useState(agente.fecha_ingreso ?? '')
  const [fechaPruebaInicio, setFechaPruebaInicio] = useState(agente.fecha_prueba_inicio ?? '')
  const [fechaPruebaFin,    setFechaPruebaFin]    = useState(agente.fecha_prueba_fin ?? '')
  const [msg, setMsg]                              = useState<string | null>(null)

  const mut = useMutation({
    mutationFn: (data: Record<string, string>) =>
      api.patch<FechasLaboralesResult>(`/v1/agentes/${agenteId}/fechas-laborales`, data).then(r => r.data),
    onSuccess: (res) => setMsg(res.msg ?? 'Fechas actualizadas.'),
    onError: () => setMsg('Error al guardar fechas.'),
  })

  return (
    <section className="kyro-card relative overflow-hidden p-5">
      <h3 className="mb-1 flex items-center gap-2 text-sm font-semibold text-kyro-text">
        <Calendar size={15} className="text-kyro-gold" /> Fechas laborales
      </h3>
      <p className="mb-4 text-xs text-kyro-muted">Ingreso y vigencia del periodo de prueba.</p>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Fecha ingreso</label>
          <Input type="date" value={fechaIngreso} onChange={e => setFechaIngreso(e.target.value)} />
        </div>
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Inicio prueba</label>
          <Input type="date" value={fechaPruebaInicio} onChange={e => setFechaPruebaInicio(e.target.value)} />
        </div>
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Fin prueba</label>
          <Input type="date" value={fechaPruebaFin} onChange={e => setFechaPruebaFin(e.target.value)} />
        </div>
      </div>
      {msg && <p className={`mt-3 rounded-kyro px-3 py-2 text-xs ${msg.startsWith('Error') ? 'bg-kyro-danger/10 text-kyro-danger' : 'bg-kyro-success/10 text-kyro-success'}`}>{msg}</p>}
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
    </section>
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
    <section className="kyro-card relative overflow-hidden p-5">
      <h3 className="mb-1 flex items-center gap-2 text-sm font-semibold text-kyro-text">
        <ShieldCheck size={15} className="text-kyro-gold" /> Token de seguridad
      </h3>
      <p className="mb-4 text-xs text-kyro-muted">Genera o revoca credenciales de acceso temporal.</p>

      {resultado?.token && (
        <div className="mb-4 rounded-kyro border border-kyro-indigo bg-kyro-indigo/10 p-3">
          <p className="mb-1 text-xs text-kyro-muted">Token generado ({resultado.tipo}):</p>
          <p className="break-all font-mono text-2xl font-bold tracking-widest text-kyro-gold">{resultado.token}</p>
          <p className="mt-1 text-xs text-kyro-subtle">Expira: {resultado.expiracion}</p>
        </div>
      )}

      {msg && <p className="mb-3 rounded-kyro bg-kyro-elevated px-3 py-2 text-xs text-kyro-muted">{msg}</p>}

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
          className="border-kyro-danger text-kyro-danger hover:bg-kyro-danger/10"
          onClick={() => { setResultado(null); mut.mutate('revocar') }}
        >
          Revocar token
        </Button>
      </div>
    </section>
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
    return (
      <div className="flex h-64 items-center justify-center text-sm text-kyro-muted">
        <span className="mr-2 h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
        Cargando perfil...
      </div>
    )
  }

  if (!agente) {
    return (
      <div className="space-y-4 py-12 text-center">
        <p className="text-kyro-muted">Agente no encontrado</p>
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

  const dif = Number(stats?.diferencia_acumulada ?? 0)

  return (
    <div className="space-y-6">
      <Link to="/agentes" className="inline-flex items-center gap-1.5 text-sm text-kyro-muted transition-colors hover:text-kyro-gold">
        <ArrowLeft size={15} /> Volver a agentes
      </Link>

      <section className="kyro-card relative overflow-hidden p-5 sm:p-6">
        <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-kyro-indigo via-kyro-gold to-transparent" />
        <div aria-hidden className="absolute -right-16 -top-20 h-48 w-48 rounded-full bg-kyro-indigo/10 blur-3xl" />
        <div className="relative flex flex-wrap items-start gap-5">
          <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-kyro-lg bg-kyro-indigo text-2xl font-bold text-kyro-text shadow-kyro-card">
            {initials}
          </div>
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-3">
              <h1 className="font-orbitron text-xl font-bold tracking-tight text-kyro-text">{agente.nombres}</h1>
              <Badge variant={agente.estado === 'ACTIVO' ? 'success' : agente.estado === 'INACTIVO' ? 'warning' : 'destructive'}>
                {agente.estado}
              </Badge>
              {agente.es_gerencia && String(agente.es_gerencia) !== 'NO' && (
                <Badge>Gerencia</Badge>
              )}
            </div>
            <div className="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-sm text-kyro-muted">
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
          <div className="ml-auto rounded-kyro border border-kyro-border bg-kyro-elevated px-4 py-3 text-right">
            <p className="text-[0.68rem] font-semibold uppercase tracking-wider text-kyro-subtle">Sueldo base</p>
            <p className="mt-1 font-mono text-lg font-bold tabular-nums text-kyro-text">{fmt(agente.sueldo_base)}</p>
          </div>
        </div>
      </section>

      {stats && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div className="kyro-card border-l-4 border-l-kpi-total p-4">
            <div className="mb-2 flex items-center gap-2 text-kyro-muted">
              <FileText size={14} />
              <p className="text-xs">Total reportes</p>
            </div>
            <p className="text-xl font-bold text-kyro-text">{stats.total_reportes}</p>
          </div>
          <div className="kyro-card border-l-4 border-l-kpi-total p-4">
            <div className="mb-2 flex items-center gap-2 text-kyro-gold">
              <DollarSign size={14} />
              <p className="text-xs">Total vendido</p>
            </div>
            <p className="font-mono text-xl font-bold tabular-nums text-kyro-text">{fmt(stats.total_vendido)}</p>
          </div>
          <div className={`kyro-card border-l-4 p-4 ${dif < 0 ? 'border-l-kyro-danger' : dif > 0 ? 'border-l-kyro-warning' : 'border-l-kpi-neutral'}`}>
            <div className="mb-2 flex items-center gap-2 text-kyro-muted">
              <TrendingUp size={14} />
              <p className="text-xs">Diferencia acumulada</p>
            </div>
            <p className={`font-mono text-xl font-bold tabular-nums ${dif < 0 ? 'text-kyro-danger' : dif > 0 ? 'text-kyro-warning' : 'text-kyro-body'}`}>
              {dif > 0 ? '+' : ''}{fmt(dif)}
            </p>
          </div>
        </div>
      )}

      {isAdmin && id && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <FechasLaboralesPanel agenteId={id} agente={agente} />
          <TokenSeguridadPanel agenteId={id} />
        </div>
      )}

      <section className="kyro-card relative overflow-hidden">
        <div aria-hidden className="absolute inset-x-0 top-0 z-20 h-px bg-gradient-to-r from-kyro-gold via-kyro-indigo to-transparent" />
        <div className="flex flex-col gap-2 border-b border-kyro-border p-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="text-sm font-semibold text-kyro-text">Historial de reportes</h2>
            <p className="mt-0.5 text-xs text-kyro-muted">Movimientos y cierres asociados al agente.</p>
          </div>
          <span className="text-xs tabular-nums text-kyro-muted">
            {meta?.total ?? 0} reportes · Pág. {meta?.current_page ?? 1}/{meta?.last_page ?? 1}
          </span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full border-separate border-spacing-0 text-sm">
            <thead>
              <tr>
                {['ID', 'Fecha', 'Tienda', 'Total', 'F. Entregado', 'Diferencia', 'Estado', ''].map(h => (
                  <th key={h} className={`kyro-table-head px-4 py-3 ${['Total', 'F. Entregado', 'Diferencia'].includes(h) ? 'text-right' : 'text-left'}`}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {reportes.length === 0 && (
                <tr><td colSpan={8} className="px-4 py-10 text-center text-kyro-muted">Sin reportes</td></tr>
              )}
              {reportes.map(r => {
                const difR = Number(r.diferencia)
                return (
                  <tr key={r.id} className={`transition-colors [&>td]:border-b [&>td]:border-kyro-border ${difR < 0 ? 'bg-kyro-danger/5' : 'hover:bg-kyro-elevated'}`}>
                    <td className="px-4 py-3 font-mono text-xs text-kyro-muted">#{String(r.id).padStart(4, '0')}</td>
                    <td className="px-4 py-3 text-kyro-body">{new Date(r.fecha + 'T00:00:00').toLocaleDateString('es-PE')}</td>
                    <td className="px-4 py-3"><span className="rounded-kyro bg-kyro-elevated px-1.5 py-0.5 font-mono text-xs text-kyro-body">{r.tienda_id}</span></td>
                    <td className="px-4 py-3 text-right font-mono text-kyro-body">{fmt(r.total_calculado)}</td>
                    <td className="px-4 py-3 text-right font-mono text-kyro-body">{fmt(r.efectivo_entregado)}</td>
                    <td className="px-4 py-3 text-right">
                      <span className={`inline-block rounded-kyro px-2 py-0.5 text-xs font-bold ${difR === 0 ? 'bg-kyro-elevated text-kyro-muted' : difR < 0 ? 'bg-kyro-danger/10 text-kyro-danger' : 'bg-kyro-warning/10 text-kyro-warning'}`}>
                        {difR > 0 ? '+' : ''}{fmt(difR)}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${{ borrador: 'bg-kyro-elevated text-kyro-muted', enviado: 'bg-kyro-info/10 text-kyro-info', editado: 'bg-kyro-warning/10 text-kyro-warning', aprobado: 'bg-kyro-success/10 text-kyro-success' }[r.estado] ?? 'bg-kyro-elevated text-kyro-muted'}`}>
                        {r.estado}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <Link to={`/reportes/${r.id}`} className="text-xs font-medium text-kyro-gold hover:text-kyro-text">Ver</Link>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
        {meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between border-t border-kyro-border bg-kyro-elevated p-3.5">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>
              <ChevronLeft size={14} /> Anterior
            </Button>
            <span className="text-xs text-kyro-muted">{meta.from}–{meta.to} de {meta.total}</span>
            <Button variant="outline" size="sm" disabled={page >= meta.last_page} onClick={() => setPage(p => p + 1)}>
              Siguiente <ChevronRight size={14} />
            </Button>
          </div>
        )}
      </section>
    </div>
  )
}
