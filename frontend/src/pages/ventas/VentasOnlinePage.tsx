import { useState } from 'react'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { CaretLeft as ChevronLeft, CaretRight as ChevronRight, DeviceMobile, CheckCircle, XCircle, Clock, Warning, Percent } from '@phosphor-icons/react'
import { PageHeader } from '../../components/PageHeader'
import { KpiCard } from '../../components/ui/KpiCard'
import { ListToolbar } from '../../components/ListToolbar'
import { Input } from '../../components/ui/input'
import { Select } from '../../components/ui/select'
import { Button } from '../../components/ui/button'
import { useAuth } from '../../hooks/useAuth'
import { esJefeTienda } from '../../utils/roles'
import { useTiendasSelect } from '../../hooks/useTiendasSelect'
import { listarVentasOnline, type VentasOnlineFiltros, type EstadoVenta } from '../../services/ventasOnline.api'
import { AppVentaDescarga } from './AppVentaDescarga'
import { AppTerminalDescarga } from '../asistencias/AppTerminalDescarga'

const OPERADORES = ['Movistar', 'Claro', 'Entel', 'Bitel', 'Otro', 'Nueva']
const TIPO_LABEL: Record<string, string> = { delivery_chip: 'Delivery chip', plan_online: 'Plan online' }

const estadoBadge: Record<EstadoVenta, string> = {
  pendiente: 'border-amber-200/70 bg-amber-50 text-amber-700 dark:border-amber-400/15 dark:bg-amber-500/10 dark:text-amber-300',
  exitoso: 'border-emerald-200/70 bg-emerald-50 text-emerald-700 dark:border-emerald-400/15 dark:bg-emerald-500/10 dark:text-emerald-300',
  fallido: 'border-red-200/70 bg-red-50 text-red-700 dark:border-red-400/15 dark:bg-red-500/10 dark:text-red-300',
}

export function VentasOnlinePage() {
  const { usuario } = useAuth()
  const jefe = esJefeTienda(usuario)
  const { tiendas } = useTiendasSelect()

  const [filtros, setFiltros] = useState<VentasOnlineFiltros>({ page: 1, per_page: 25 })
  const [form, setForm] = useState<VentasOnlineFiltros>({})

  const { data, isLoading, isError } = useQuery({
    queryKey: ['ventas-online', filtros],
    queryFn: () => listarVentasOnline(filtros),
    placeholderData: keepPreviousData,
  })

  const kpis = data?.kpis
  const pg = data?.paginacion

  const aplicar = () => setFiltros({ ...form, page: 1, per_page: 25 })
  const limpiar = () => { setForm({}); setFiltros({ page: 1, per_page: 25 }) }
  const irPagina = (p: number) => setFiltros((f) => ({ ...f, page: p }))

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        title="Ventas Online"
        description="Registros generados desde la app de venta (delivery de chips y planes online), cruzados con el CRM."
      />

      {/* Descarga de apps — asistencia y venta online, mismo panel de gestión */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <AppVentaDescarga />
        <AppTerminalDescarga />
      </div>

      {/* KPIs */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-6">
        <KpiCard title="Total" value={kpis?.total ?? (data ? '—' : undefined)} loading={isLoading} tone="indigo" icon={<DeviceMobile size={18} />} />
        <KpiCard title="Exitosas" value={kpis?.exitosos ?? (data ? '—' : undefined)} loading={isLoading} tone="success" accent="var(--color-kyro-success)" icon={<CheckCircle size={18} />} />
        <KpiCard title="Fallidas" value={kpis?.fallidos ?? (data ? '—' : undefined)} loading={isLoading} tone="danger" accent="var(--color-kyro-danger)" icon={<XCircle size={18} />} />
        <KpiCard title="Pendientes" value={kpis?.pendientes ?? (data ? '—' : undefined)} loading={isLoading} tone="indigo" icon={<Clock size={18} />} />
        <KpiCard title="% Éxito" value={kpis ? `${kpis.pct_exito}%` : (data ? '—' : undefined)} loading={isLoading} tone="success" accent="var(--color-kyro-success)" icon={<Percent size={18} />} />
        <KpiCard title="Incumplimientos" value={kpis?.incumplimientos ?? (data ? '—' : undefined)} loading={isLoading} tone="danger" accent="var(--color-kyro-danger)" icon={<Warning size={18} />} />
      </div>

      {/* Filtros */}
      <ListToolbar description="Acota los registros por fecha, tienda, agente, estado, operador o tipo de venta.">
        <div>
          <label className="mb-1 block text-xs font-medium text-kyro-muted">Desde</label>
          <Input type="date" className="w-auto" onChange={(e) => setForm((f) => ({ ...f, fecha_desde: e.target.value }))} />
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-kyro-muted">Hasta</label>
          <Input type="date" className="w-auto" onChange={(e) => setForm((f) => ({ ...f, fecha_hasta: e.target.value }))} />
        </div>
        {!jefe && (
          <div>
            <label className="mb-1 block text-xs font-medium text-kyro-muted">Tienda</label>
            <Select className="w-44" onChange={(e) => setForm((f) => ({ ...f, tienda: e.target.value }))}>
              <option value="">Todas</option>
              {tiendas.map((t) => <option key={t.codigo} value={t.codigo}>{t.nombre || t.codigo}</option>)}
            </Select>
          </div>
        )}
        <div>
          <label className="mb-1 block text-xs font-medium text-kyro-muted">Estado</label>
          <Select className="w-36" onChange={(e) => setForm((f) => ({ ...f, estado: e.target.value }))}>
            <option value="">Todos</option><option value="pendiente">Pendiente</option><option value="exitoso">Exitoso</option><option value="fallido">Fallido</option>
          </Select>
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-kyro-muted">Operador origen</label>
          <Select className="w-40" onChange={(e) => setForm((f) => ({ ...f, operador: e.target.value }))}>
            <option value="">Todos</option>
            {OPERADORES.map((o) => <option key={o} value={o}>{o === 'Nueva' ? 'Línea nueva' : o}</option>)}
          </Select>
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-kyro-muted">Tipo</label>
          <Select className="w-40" onChange={(e) => setForm((f) => ({ ...f, tipo: e.target.value }))}>
            <option value="">Todos</option><option value="delivery_chip">Delivery chip</option><option value="plan_online">Plan online</option>
          </Select>
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-kyro-muted">DNI / Teléfono</label>
          <Input inputMode="numeric" className="w-40" onChange={(e) => setForm((f) => ({ ...f, busqueda: e.target.value }))} />
        </div>
        <Button onClick={aplicar}>Filtrar</Button>
        <Button variant="ghost" onClick={limpiar}>Limpiar</Button>
      </ListToolbar>

      {/* Tabla */}
      <div className="kyro-card overflow-hidden rounded-[18px]">
        <div className="flex items-center justify-between border-b border-kyro-border p-4">
          <h2 className="text-sm font-semibold text-kyro-text">
            {isLoading ? 'Cargando...' : `${pg?.total ?? 0} ventas`}
          </h2>
          {pg && <span className="text-xs text-kyro-subtle">Pág. {pg.pagina}/{pg.paginas || 1}</span>}
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="kyro-table-head">
                {['Fecha', 'Agente', 'Tienda', 'DNI', 'Cliente', 'Teléfono', 'Operador', 'Tipo', 'Plan', 'Estado', 'CRM'].map((h) => (
                  <th key={h} className="px-4 py-3 text-left text-[0.68rem] font-semibold uppercase tracking-[0.08em] text-kyro-muted">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {isLoading && <tr><td colSpan={11} className="px-4 py-10 text-center text-kyro-muted">Cargando...</td></tr>}
              {isError && <tr><td colSpan={11} className="px-4 py-10 text-center text-kyro-danger">Error al cargar ventas.</td></tr>}
              {!isLoading && !isError && (data?.ventas.length ?? 0) === 0 && (
                <tr><td colSpan={11} className="px-4 py-10 text-center text-kyro-muted">Sin ventas en el período seleccionado.</td></tr>
              )}
              {data?.ventas.map((v) => (
                <tr key={v.id} className="border-t border-kyro-border/60 hover:bg-kyro-elevated/60">
                  <td className="px-4 py-3 text-xs text-kyro-subtle">{v.created_at ?? ''}</td>
                  <td className="px-4 py-3 text-xs text-kyro-body">{v.agente_ref}</td>
                  <td className="px-4 py-3 text-xs text-kyro-body">{v.tienda_codigo}</td>
                  <td className="px-4 py-3 font-mono text-xs text-kyro-body">{v.dni}</td>
                  <td className="px-4 py-3 font-medium text-kyro-text">{v.nombres}</td>
                  <td className="px-4 py-3 font-mono text-xs text-kyro-body">{v.telefono ?? '—'}</td>
                  <td className="px-4 py-3 text-kyro-body">{v.operador_origen}</td>
                  <td className="px-4 py-3 text-xs text-kyro-muted">{TIPO_LABEL[v.tipo] ?? v.tipo}</td>
                  <td className="px-4 py-3 text-xs text-kyro-muted">{v.plan_ofrecido ?? '—'}</td>
                  <td className="px-4 py-3">
                    <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-bold ${estadoBadge[v.estado]}`}>{v.estado}</span>
                    {v.estado === 'fallido' && v.motivo_falla && <div className="mt-0.5 text-xs text-kyro-danger">{v.motivo_falla}</div>}
                  </td>
                  <td className="px-4 py-3">
                    {v.crm_cliente_id && (
                      <span className="inline-flex items-center rounded-full border border-sky-200/70 bg-sky-50 px-2.5 py-0.5 text-xs font-bold text-sky-700 dark:border-sky-400/15 dark:bg-sky-500/10 dark:text-sky-300">
                        contactado CRM
                      </span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {!isLoading && pg && pg.paginas > 1 && (
          <div className="flex items-center justify-between border-t border-kyro-border p-4">
            <Button variant="outline" size="sm" disabled={pg.pagina <= 1} onClick={() => irPagina(pg.pagina - 1)}>
              <ChevronLeft size={14} /> Anterior
            </Button>
            <span className="text-xs text-kyro-subtle">{pg.pagina} / {pg.paginas}</span>
            <Button variant="outline" size="sm" disabled={pg.pagina >= pg.paginas} onClick={() => irPagina(pg.pagina + 1)}>
              Siguiente <ChevronRight size={14} />
            </Button>
          </div>
        )}
      </div>
    </div>
  )
}
