import { useState } from 'react'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { CaretLeft as ChevronLeft, CaretRight as ChevronRight, DeviceMobile, CheckCircle, XCircle, Clock, Warning, Percent } from '@phosphor-icons/react'
import { PageHeader } from '../../components/PageHeader'
import { KpiCard } from '../../components/ui/KpiCard'
import { Input } from '../../components/ui/input'
import { Select } from '../../components/ui/select'
import { Button } from '../../components/ui/button'
import { useAuth } from '../../hooks/useAuth'
import { esJefeTienda } from '../../utils/roles'
import { useTiendasSelect } from '../../hooks/useTiendasSelect'
import { listarVentasOnline, type VentasOnlineFiltros, type EstadoVenta } from '../../services/ventasOnline.api'

const OPERADORES = ['Movistar', 'Claro', 'Entel', 'Bitel', 'Otro', 'Nueva']

const estadoBadge: Record<EstadoVenta, string> = {
  pendiente: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
  exitoso: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
  fallido: 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-300',
}

const TIPO_LABEL: Record<string, string> = { delivery_chip: 'Delivery chip', plan_online: 'Plan online' }

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

  const aplicar = (e: React.FormEvent) => {
    e.preventDefault()
    setFiltros({ ...form, page: 1, per_page: 25 })
  }

  const irPagina = (p: number) => setFiltros((f) => ({ ...f, page: p }))

  return (
    <div className="space-y-5">
      <PageHeader title="Ventas Online" subtitle="Registros generados desde la app de venta (delivery de chips y planes online)." />

      <div className="grid grid-cols-2 gap-3 md:grid-cols-6">
        <KpiCard title="Total" value={isLoading ? undefined : kpis?.total ?? 0} icon={<DeviceMobile />} accent="#6366f1" />
        <KpiCard title="Exitosas" value={isLoading ? undefined : kpis?.exitosos ?? 0} icon={<CheckCircle />} accent="#10b981" />
        <KpiCard title="Fallidas" value={isLoading ? undefined : kpis?.fallidos ?? 0} icon={<XCircle />} accent="#f43f5e" />
        <KpiCard title="Pendientes" value={isLoading ? undefined : kpis?.pendientes ?? 0} icon={<Clock />} accent="#f59e0b" />
        <KpiCard title="% Éxito" value={isLoading ? undefined : `${kpis?.pct_exito ?? 0}%`} icon={<Percent />} accent="#6366f1" />
        <KpiCard title="Incumplimientos" value={isLoading ? undefined : kpis?.incumplimientos ?? 0} icon={<Warning />} accent="#f43f5e" />
      </div>

      <form onSubmit={aplicar} className="grid grid-cols-2 gap-3 rounded-xl border border-gray-200 bg-white/60 p-4 dark:border-white/10 dark:bg-zinc-950/40 md:grid-cols-4 lg:grid-cols-8">
        <div><label className="mb-1 block text-xs text-gray-500">Desde</label><Input type="date" onChange={(e) => setForm((f) => ({ ...f, fecha_desde: e.target.value }))} /></div>
        <div><label className="mb-1 block text-xs text-gray-500">Hasta</label><Input type="date" onChange={(e) => setForm((f) => ({ ...f, fecha_hasta: e.target.value }))} /></div>
        {!jefe && (
          <div>
            <label className="mb-1 block text-xs text-gray-500">Tienda</label>
            <Select onChange={(e) => setForm((f) => ({ ...f, tienda: e.target.value }))}>
              <option value="">Todas</option>
              {tiendas.map((t) => <option key={t.codigo} value={t.codigo}>{t.nombre || t.codigo}</option>)}
            </Select>
          </div>
        )}
        <div>
          <label className="mb-1 block text-xs text-gray-500">Estado</label>
          <Select onChange={(e) => setForm((f) => ({ ...f, estado: e.target.value }))}>
            <option value="">Todos</option><option value="pendiente">Pendiente</option><option value="exitoso">Exitoso</option><option value="fallido">Fallido</option>
          </Select>
        </div>
        <div>
          <label className="mb-1 block text-xs text-gray-500">Operador origen</label>
          <Select onChange={(e) => setForm((f) => ({ ...f, operador: e.target.value }))}>
            <option value="">Todos</option>
            {OPERADORES.map((o) => <option key={o} value={o}>{o === 'Nueva' ? 'Línea nueva' : o}</option>)}
          </Select>
        </div>
        <div>
          <label className="mb-1 block text-xs text-gray-500">Tipo</label>
          <Select onChange={(e) => setForm((f) => ({ ...f, tipo: e.target.value }))}>
            <option value="">Todos</option><option value="delivery_chip">Delivery chip</option><option value="plan_online">Plan online</option>
          </Select>
        </div>
        <div><label className="mb-1 block text-xs text-gray-500">DNI / Teléfono</label><Input inputMode="numeric" onChange={(e) => setForm((f) => ({ ...f, busqueda: e.target.value }))} /></div>
        <div className="flex items-end"><Button type="submit" className="w-full">Filtrar</Button></div>
      </form>

      <div className="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-white/5">
            <tr>
              <th className="px-3 py-2">Fecha</th><th className="px-3 py-2">Agente</th><th className="px-3 py-2">Tienda</th>
              <th className="px-3 py-2">DNI</th><th className="px-3 py-2">Cliente</th><th className="px-3 py-2">Teléfono</th>
              <th className="px-3 py-2">Operador</th><th className="px-3 py-2">Tipo</th><th className="px-3 py-2">Plan</th>
              <th className="px-3 py-2">Estado</th><th className="px-3 py-2">CRM</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 dark:divide-white/5">
            {isError && <tr><td colSpan={11} className="px-3 py-6 text-center text-rose-500">Error al cargar ventas.</td></tr>}
            {!isError && !isLoading && (data?.ventas.length ?? 0) === 0 && (
              <tr><td colSpan={11} className="px-3 py-6 text-center text-gray-400">Sin ventas.</td></tr>
            )}
            {data?.ventas.map((v) => (
              <tr key={v.id} className="hover:bg-gray-50/70 dark:hover:bg-white/5">
                <td className="whitespace-nowrap px-3 py-2 text-xs text-gray-500">{v.created_at ?? ''}</td>
                <td className="px-3 py-2 text-xs">{v.agente_ref}</td>
                <td className="px-3 py-2 text-xs">{v.tienda_codigo}</td>
                <td className="px-3 py-2">{v.dni}</td>
                <td className="px-3 py-2">{v.nombres}</td>
                <td className="px-3 py-2">{v.telefono ?? ''}</td>
                <td className="px-3 py-2">{v.operador_origen}</td>
                <td className="px-3 py-2 text-xs">{TIPO_LABEL[v.tipo] ?? v.tipo}</td>
                <td className="px-3 py-2 text-xs">{v.plan_ofrecido ?? ''}</td>
                <td className="px-3 py-2">
                  <span className={`inline-block rounded px-2 py-0.5 text-xs font-medium ${estadoBadge[v.estado]}`}>{v.estado}</span>
                  {v.estado === 'fallido' && v.motivo_falla && <div className="mt-0.5 text-xs text-rose-500">{v.motivo_falla}</div>}
                </td>
                <td className="px-3 py-2">
                  {v.crm_cliente_id && <span className="inline-block rounded bg-sky-100 px-2 py-0.5 text-xs text-sky-800 dark:bg-sky-500/15 dark:text-sky-300">contactado CRM</span>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {pg && pg.paginas > 1 && (
        <div className="flex items-center justify-end gap-2">
          <Button variant="outline" size="sm" disabled={pg.pagina <= 1} onClick={() => irPagina(pg.pagina - 1)}><ChevronLeft /></Button>
          <span className="text-sm text-gray-500">{pg.pagina} / {pg.paginas}</span>
          <Button variant="outline" size="sm" disabled={pg.pagina >= pg.paginas} onClick={() => irPagina(pg.pagina + 1)}><ChevronRight /></Button>
        </div>
      )}
    </div>
  )
}
