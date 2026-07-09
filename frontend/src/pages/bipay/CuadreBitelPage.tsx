import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CaretLeft as ChevronLeft, CaretRight as ChevronRight, BellRinging as BellRing } from '@phosphor-icons/react'
import { Button } from '../../components/ui/button'
import {
  cuadreBitelApi, auditoriaBipayApi, integradorApi,
  type CuadreCategorial, type CierreAuditoria, type WebhookConfig,
} from '../../services/integradorBitel.api'

const hoy = () => new Date().toISOString().slice(0, 10)
const soles = (n: number) => `S/ ${Number(n ?? 0).toFixed(2)}`

type Tab = 'panel' | 'global' | 'movimientos' | 'auditoria' | 'morosidad'

const TABS: { id: Tab; label: string }[] = [
  { id: 'panel',       label: 'Cuadre diario' },
  { id: 'global',      label: 'Cuadre global' },
  { id: 'movimientos', label: 'Movimientos por agente' },
  { id: 'auditoria',   label: 'Auditoría Bipay' },
  { id: 'morosidad',   label: 'Morosidad' },
]

function EstadoBadge({ ok, estado }: { ok?: boolean; estado: string }) {
  const cuadrado = ok ?? estado === 'CUADRADO'
  const ajustado = estado === 'AJUSTADO'
  const cls = ajustado
    ? 'border-kyro-warning/30 bg-kyro-warning/10 text-kyro-warning'
    : cuadrado
      ? 'border-kyro-success/30 bg-kyro-success/10 text-kyro-success'
      : 'border-kyro-danger/30 bg-kyro-danger/10 text-kyro-danger'
  return (
    <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[0.68rem] font-bold ${cls}`}>
      {estado}
    </span>
  )
}

/* ── Tab: Cuadre diario (panel de control con apoyos) ─────────────────────── */

function TabPanel({ fecha }: { fecha: string }) {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['cuadre-bitel-panel', fecha],
    queryFn:  () => cuadreBitelApi.panel(fecha),
  })

  const confirmarApoyo = useMutation({
    mutationFn: ({ origen, destino }: { origen: string; destino: string }) =>
      cuadreBitelApi.confirmarApoyo(fecha, origen, destino),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['cuadre-bitel-panel', fecha] }),
  })

  const solicitarHistorico = useMutation({
    mutationFn: () => integradorApi.solicitarBitelHistorico(fecha),
    onSuccess:  () => qc.invalidateQueries({ queryKey: ['cuadre-bitel-panel', fecha] }),
  })

  const [destinoApoyo, setDestinoApoyo] = useState<Record<string, string>>({})

  if (isLoading) return <p className="py-10 text-center text-sm text-kyro-muted">Cargando cuadre...</p>
  if (!data) return null

  const cuadres = Object.entries(data.cuadres)

  return (
    <div className="space-y-4">
      {/* Estado de la cola de histórico */}
      <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-kyro-muted">
        <div>
          {data.queue
            ? <>Histórico Bitel: <b>{data.queue.estado}</b> ({data.queue.movs_procesados} movs) {data.queue.mensaje && <span className="text-kyro-danger">· {data.queue.mensaje}</span>}</>
            : 'Sin solicitud de histórico para esta fecha.'}
        </div>
        <button
          onClick={() => solicitarHistorico.mutate()}
          disabled={solicitarHistorico.isPending || data.queue?.estado === 'PENDIENTE' || data.queue?.estado === 'PROCESANDO'}
          className="rounded-lg border border-kyro-gold/40 bg-kyro-gold/10 px-3 py-1.5 text-xs font-semibold text-kyro-gold disabled:opacity-50"
        >
          {data.queue?.estado === 'LISTO' || data.queue?.estado === 'ERROR' ? 'Re-solicitar histórico Bitel' : 'Solicitar histórico Bitel'}
        </button>
      </div>

      {/* Anomalías de apoyo */}
      {data.anomalias.length > 0 && (
        <div className="kyro-card border border-kyro-warning/30 p-4">
          <h3 className="mb-2 text-xs font-bold uppercase tracking-wider text-kyro-warning">
            Posibles apoyos sin confirmar (venta Bitel alta, ERP casi vacío)
          </h3>
          <div className="space-y-2">
            {data.anomalias.map((a) => (
              <div key={a.tienda} className="flex flex-wrap items-center gap-3 text-sm">
                <b>{a.tienda}</b>
                <span className="text-kyro-muted">Bitel {soles(a.total_bitel)} · ERP {soles(a.total_erp)}</span>
                <select
                  className="rounded border border-white/10 bg-transparent px-2 py-1 text-xs"
                  value={destinoApoyo[a.tienda] ?? ''}
                  onChange={(e) => setDestinoApoyo((s) => ({ ...s, [a.tienda]: e.target.value }))}
                >
                  <option value="">Tienda que declaró la venta...</option>
                  {data.tiendas.filter((t) => t.codigo !== a.tienda).map((t) => (
                    <option key={t.codigo} value={t.codigo}>{t.codigo} — {t.nombre}</option>
                  ))}
                </select>
                <button
                  disabled={!destinoApoyo[a.tienda] || confirmarApoyo.isPending}
                  onClick={() => confirmarApoyo.mutate({ origen: a.tienda, destino: destinoApoyo[a.tienda] })}
                  className="rounded bg-kyro-gold px-2.5 py-1 text-xs font-bold text-black disabled:opacity-40"
                >
                  Confirmar apoyo
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Cuadres por tienda / grupo */}
      <div className="grid gap-4 lg:grid-cols-2">
        {cuadres.map(([cod, c]: [string, CuadreCategorial]) => (
          <div key={cod} className="kyro-card p-4">
            <div className="mb-3 flex items-center justify-between">
              <div>
                <span className="font-bold">{cod}</span>
                {c.es_apoyo_destino && (
                  <span className="ml-2 text-[0.68rem] text-kyro-muted">+ apoyo de {c.apoyo_origen}</span>
                )}
                {!data.reportes_enviados[cod] && !c.es_apoyo_destino && (
                  <span className="ml-2 text-[0.68rem] text-kyro-warning">sin reporte ERP</span>
                )}
              </div>
              <EstadoBadge ok={c.ok} estado={c.estado} />
            </div>
            <table className="w-full text-xs">
              <thead className="text-[0.65rem] uppercase text-kyro-muted">
                <tr><th className="text-left">Categoría</th><th className="text-right">ERP</th><th className="text-right">Bitel</th><th className="text-right">Dif.</th></tr>
              </thead>
              <tbody>
                {Object.entries(c.categorias).map(([k, cat]) => (
                  <tr key={k} className="border-t border-white/5">
                    <td className="py-1">{cat.etiqueta} {cat.ops > 0 && <span className="text-kyro-muted">({cat.ops})</span>}</td>
                    <td className="text-right">{soles(cat.erp)}</td>
                    <td className="text-right">{soles(cat.bitel)}</td>
                    <td className={`text-right font-semibold ${Math.abs(cat.diff) < 0.01 ? 'text-kyro-success' : 'text-kyro-danger'}`}>{soles(cat.diff)}</td>
                  </tr>
                ))}
                <tr className="border-t border-white/10 font-bold">
                  <td className="py-1">TOTAL</td>
                  <td className="text-right">{soles(c.total_erp)}</td>
                  <td className="text-right">{soles(c.total_bitel)}</td>
                  <td className={`text-right ${c.ok ? 'text-kyro-success' : 'text-kyro-danger'}`}>{soles(c.total_diff)}</td>
                </tr>
              </tbody>
            </table>
          </div>
        ))}
        {cuadres.length === 0 && <p className="col-span-2 py-8 text-center text-sm text-kyro-muted">Sin datos para esta fecha.</p>}
      </div>
    </div>
  )
}

/* ── Tab: Cuadre global de recargas BiPay ─────────────────────────────────── */

function TabGlobal({ fecha }: { fecha: string }) {
  const { data, isLoading } = useQuery({
    queryKey: ['cuadre-bitel-global', fecha],
    queryFn:  () => cuadreBitelApi.global(fecha),
  })
  if (isLoading) return <p className="py-10 text-center text-sm text-kyro-muted">Cargando...</p>
  if (!data) return null

  return (
    <div className="space-y-4">
      <div className="kyro-card flex flex-wrap items-center justify-between gap-4 p-4">
        <div className="text-sm">
          <div>Recarga BiPay declarada (reportes): <b className="text-kyro-gold">{soles(data.declarado_total)}</b></div>
          <div>Movimientos sin agente (scraping): <b>{soles(data.scrapeado_total)}</b></div>
          <div>Diferencia: <b className={data.ok ? 'text-kyro-success' : 'text-kyro-danger'}>{soles(data.diferencia)}</b></div>
        </div>
        <EstadoBadge ok={data.ok} estado={data.estado} />
      </div>
      <div className="kyro-card overflow-x-auto">
        <table className="w-full text-xs">
          <thead className="text-[0.65rem] uppercase text-kyro-muted">
            <tr>
              <th className="px-3 py-2 text-left">Tienda</th>
              <th className="px-3 py-2 text-right">Recarga declarada</th>
              <th className="px-3 py-2 text-right">Movs sin agente</th>
              <th className="px-3 py-2 text-center">Cant.</th>
              <th className="px-3 py-2 text-right">Diferencia</th>
            </tr>
          </thead>
          <tbody>
            {data.tiendas.map((t) => (
              <tr key={t.tienda} className="border-t border-white/5">
                <td className="px-3 py-1.5 font-semibold">{t.tienda}</td>
                <td className="px-3 py-1.5 text-right">{soles(t.declarado)}</td>
                <td className="px-3 py-1.5 text-right">{soles(t.scrapeado)}</td>
                <td className="px-3 py-1.5 text-center text-kyro-muted">{t.cantidad}</td>
                <td className={`px-3 py-1.5 text-right font-bold ${Math.abs(t.diferencia) < 0.01 ? 'text-kyro-success' : 'text-kyro-danger'}`}>{soles(t.diferencia)}</td>
              </tr>
            ))}
            {data.tiendas.length === 0 && (
              <tr><td colSpan={5} className="px-3 py-6 text-center text-kyro-muted">Sin datos para esta fecha.</td></tr>
            )}
          </tbody>
        </table>
      </div>
      <p className="text-[0.7rem] text-kyro-muted">
        Los movimientos CON agente se cuadran por tienda en el detalle de cada reporte. Aquí solo van los SIN agente
        (recargas de saldo), que es donde se mezclan las cuentas compartidas.
      </p>
    </div>
  )
}

/* ── Tab: Movimientos del día por agente ──────────────────────────────────── */

function TabMovimientos({ fecha }: { fecha: string }) {
  const [agentes, setAgentes] = useState<string>('')
  const [abierto, setAbierto] = useState<Record<string, boolean>>({})

  const { data, isLoading } = useQuery({
    queryKey: ['cuadre-bitel-movs', fecha, agentes],
    queryFn:  () => cuadreBitelApi.movimientosDia(fecha, agentes || undefined),
  })

  const seleccion = useMemo(() => new Set(agentes ? agentes.split(',') : []), [agentes])
  const toggleAgente = (ag: string, todos: string[]) => {
    const set = new Set(seleccion.size ? seleccion : todos)
    if (set.has(ag)) { set.delete(ag) } else { set.add(ag) }
    setAgentes(Array.from(set).join(','))
  }

  if (isLoading) return <p className="py-10 text-center text-sm text-kyro-muted">Cargando movimientos...</p>
  if (!data || Object.keys(data.tiendas).length === 0) {
    return <p className="py-10 text-center text-sm text-kyro-muted">No hay movimientos del integrador para esta fecha.</p>
  }

  const cols: [string, string][] = [
    ['prepago', 'Prepago'], ['postpago', 'Postpago'], ['paquetes', 'Paquetes'], ['recargas', 'Recargas'],
    ['recarga_bipay', 'Rec. BiPay'], ['otros', 'Otros'], ['reembolsos', 'Reembolsos'], ['neto', 'Total Neto'],
  ]

  return (
    <div className="space-y-4">
      {Object.entries(data.tiendas).map(([tienda, info]) => {
        const todosAgentes = data.agentes_por_tienda[tienda] ?? []
        return (
          <div key={tienda} className="kyro-card p-4">
            <h3 className="mb-2 font-bold text-kyro-gold">{tienda}</h3>

            {todosAgentes.length > 0 && (
              <div className="mb-3 flex flex-wrap gap-2">
                {todosAgentes.map((ag) => {
                  const activo = seleccion.size === 0 || seleccion.has(ag)
                  return (
                    <button
                      key={ag}
                      onClick={() => toggleAgente(ag, todosAgentes)}
                      className={`rounded-full border px-2.5 py-1 text-[0.68rem] font-semibold ${
                        activo ? 'border-kyro-gold/40 bg-kyro-gold/10 text-kyro-gold' : 'border-white/10 text-kyro-muted'
                      }`}
                    >
                      {ag}
                    </button>
                  )
                })}
              </div>
            )}

            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead className="text-[0.62rem] uppercase text-kyro-muted">
                  <tr>{cols.map(([k, lbl]) => <th key={k} className="px-2 py-1 text-right first:text-left">{lbl}</th>)}</tr>
                </thead>
                <tbody>
                  <tr className="border-t border-white/5 font-semibold">
                    {cols.map(([k], i) => (
                      <td key={k} className={`px-2 py-1.5 ${i === 0 ? 'text-left' : 'text-right'} ${k === 'neto' ? 'text-kyro-success' : ''}`}>
                        {soles(info.resumen[k] ?? 0)}
                      </td>
                    ))}
                  </tr>
                </tbody>
              </table>
            </div>

            <div className="mt-3 grid gap-3 md:grid-cols-2">
              <div>
                <h4 className="mb-1 text-[0.65rem] font-bold uppercase text-kyro-muted">Por agente</h4>
                <table className="w-full text-xs">
                  <tbody>
                    {Object.entries(info.agentes).map(([ag, s]) => (
                      <tr key={ag} className="border-t border-white/5">
                        <td className="py-1">{ag}</td>
                        <td className="text-center text-kyro-muted">{s.cantidad} movs</td>
                        <td className={`text-right font-bold ${s.total >= 0 ? 'text-kyro-success' : 'text-kyro-warning'}`}>{soles(s.total)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div>
                <button
                  onClick={() => setAbierto((s) => ({ ...s, [tienda]: !s[tienda] }))}
                  className="mb-1 text-[0.68rem] font-semibold text-kyro-gold"
                >
                  {abierto[tienda] ? '▾ Ocultar' : '▸ Ver'} desglose ({info.registros.length} registros)
                </button>
                {abierto[tienda] && (
                  <div className="max-h-64 overflow-y-auto">
                    <table className="w-full text-[0.68rem]">
                      <tbody>
                        {info.registros.map((r, i) => (
                          <tr key={i} className="border-t border-white/5">
                            <td className="py-1 whitespace-nowrap text-kyro-muted">{r.fecha_hora.slice(11, 19)}</td>
                            <td className="px-2 text-kyro-gold">{r.agente}</td>
                            <td className="max-w-[280px] truncate" title={r.descripcion}>{r.descripcion}</td>
                            <td className={`text-right font-semibold ${r.monto >= 0 ? 'text-kyro-success' : 'text-kyro-warning'}`}>{soles(r.monto)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </div>
          </div>
        )
      })}
    </div>
  )
}

/* ── Tab: Auditoría Bipay ─────────────────────────────────────────────────── */

function TabAuditoria({ fecha, webhookOpenDefault = false }: { fecha: string; webhookOpenDefault?: boolean }) {
  const qc = useQueryClient()
  const [detalleId, setDetalleId]     = useState<number | null>(null)
  const [ajusteId, setAjusteId]       = useState<number | null>(null)
  const [observacion, setObservacion] = useState('')
  const [webhookOpen, setWebhookOpen] = useState(webhookOpenDefault)
  const [webhook, setWebhook]         = useState<WebhookConfig>({})

  const invalidar = () => qc.invalidateQueries({ queryKey: ['auditoria-bipay', fecha] })

  const { data, isLoading } = useQuery({
    queryKey: ['auditoria-bipay', fecha],
    queryFn:  () => auditoriaBipayApi.index(fecha),
  })
  const { data: detalle } = useQuery({
    queryKey: ['auditoria-bipay-detalle', detalleId],
    queryFn:  () => auditoriaBipayApi.detalles(detalleId!),
    enabled:  detalleId !== null,
  })
  useQuery({
    queryKey: ['auditoria-webhook'],
    queryFn:  async () => {
      const cfg = await auditoriaBipayApi.webhookConfig()
      setWebhook(cfg)
      return cfg
    },
    enabled: webhookOpen,
  })

  const cruce = useMutation({ mutationFn: () => auditoriaBipayApi.ejecutarCruce(fecha), onSuccess: invalidar })
  const ajustar = useMutation({
    mutationFn: () => auditoriaBipayApi.ajustar(ajusteId!, observacion),
    onSuccess:  () => { setAjusteId(null); setObservacion(''); invalidar() },
  })
  const guardarWebhook = useMutation({
    mutationFn: () => auditoriaBipayApi.guardarWebhook(webhook),
    onSuccess:  () => setWebhookOpen(false),
  })

  if (isLoading) return <p className="py-10 text-center text-sm text-kyro-muted">Cargando auditoría...</p>

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap justify-end gap-2">
        <Button variant="glassDanger" size="sm" className="gap-1.5" onClick={() => setWebhookOpen((v) => !v)}>
          <BellRing size={14} /> Alertas Webhook
        </Button>
        <Button variant="gold" size="sm" disabled={cruce.isPending} onClick={() => cruce.mutate()}>
          {cruce.isPending ? 'Cruzando...' : 'Ejecutar cruce del día'}
        </Button>
      </div>

      {webhookOpen && (
        <div className="kyro-card border-t-2 border-t-kyro-danger space-y-2 p-4">
          <h3 className="flex items-center gap-1.5 text-xs font-bold uppercase text-kyro-danger">
            <BellRing size={13} /> Alertas de descuadre (Discord / Slack)
          </h3>
          <input className="kyro-input w-full"
            placeholder="URL del webhook" value={webhook.bipay_webhook_url ?? ''}
            onChange={(e) => setWebhook((w) => ({ ...w, bipay_webhook_url: e.target.value }))} />
          <div className="flex flex-wrap gap-2">
            <select className="kyro-input w-32"
              value={webhook.bipay_webhook_tipo ?? 'discord'}
              onChange={(e) => setWebhook((w) => ({ ...w, bipay_webhook_tipo: e.target.value }))}>
              <option value="discord">Discord</option>
              <option value="slack">Slack</option>
            </select>
            <input type="number" step="0.01" className="kyro-input w-32"
              placeholder="Umbral S/" value={webhook.bipay_webhook_umbral ?? ''}
              onChange={(e) => setWebhook((w) => ({ ...w, bipay_webhook_umbral: e.target.value }))} />
            <Button variant="gold" size="sm" onClick={() => guardarWebhook.mutate()}>Guardar</Button>
          </div>
        </div>
      )}

      <div className="kyro-card overflow-x-auto">
        <table className="w-full text-xs">
          <thead className="text-[0.65rem] uppercase text-kyro-muted">
            <tr>
              <th className="px-3 py-2 text-left">Tienda</th>
              <th className="px-3 py-2 text-right">Bipay real (scraping)</th>
              <th className="px-3 py-2 text-right">Declarado ERP</th>
              <th className="px-3 py-2 text-right">Diferencia</th>
              <th className="px-3 py-2 text-center">Estado</th>
              <th className="px-3 py-2 text-right">Acciones</th>
            </tr>
          </thead>
          <tbody>
            {(data?.cierres ?? []).map((c: CierreAuditoria) => (
              <tr key={c.id} className="border-t border-white/5">
                <td className="px-3 py-1.5 font-semibold">{c.tienda_nombre ?? c.tienda_codigo}</td>
                <td className="px-3 py-1.5 text-right">{soles(c.saldo_bipay_real)}</td>
                <td className="px-3 py-1.5 text-right">{soles(c.ventas_sistema)}</td>
                <td className={`px-3 py-1.5 text-right font-bold ${Number(c.diferencia) === 0 ? 'text-kyro-success' : 'text-kyro-danger'}`}>{soles(c.diferencia)}</td>
                <td className="px-3 py-1.5 text-center"><EstadoBadge estado={c.estado} /></td>
                <td className="px-3 py-1.5 text-right">
                  <button className="mr-2 text-kyro-gold" onClick={() => setDetalleId(detalleId === c.id ? null : c.id)}>Detalles</button>
                  {c.estado === 'DESCUADRADO' && (
                    <button className="text-kyro-warning" onClick={() => setAjusteId(c.id)}>Justificar</button>
                  )}
                </td>
              </tr>
            ))}
            {(data?.cierres ?? []).length === 0 && (
              <tr><td colSpan={6} className="px-3 py-6 text-center text-kyro-muted">Sin cierres auditados para esta fecha. Ejecuta el cruce.</td></tr>
            )}
          </tbody>
        </table>
      </div>

      {ajusteId !== null && (
        <div className="kyro-card space-y-2 p-4">
          <h3 className="text-xs font-bold uppercase text-kyro-muted">Justificar descuadre #{ajusteId}</h3>
          <textarea className="w-full rounded border border-white/10 bg-transparent px-2 py-1.5 text-xs" rows={2}
            placeholder="Observación / justificación (obligatoria)" value={observacion}
            onChange={(e) => setObservacion(e.target.value)} />
          <div className="flex gap-2">
            <button onClick={() => ajustar.mutate()} disabled={!observacion.trim() || ajustar.isPending}
              className="rounded bg-kyro-gold px-3 py-1 text-xs font-bold text-black disabled:opacity-40">Ajustar</button>
            <button onClick={() => setAjusteId(null)} className="text-xs text-kyro-muted">Cancelar</button>
          </div>
        </div>
      )}

      {detalle && detalleId !== null && (
        <div className="grid gap-4 lg:grid-cols-2">
          <div className="kyro-card p-4">
            <h3 className="mb-2 text-xs font-bold uppercase text-kyro-gold">Movimientos Bitel (scraper)</h3>
            <div className="max-h-80 overflow-y-auto">
              <table className="w-full text-[0.68rem]">
                <tbody>
                  {detalle.scraper_logs.map((s, i) => (
                    <tr key={i} className="border-t border-white/5">
                      <td className="py-1 whitespace-nowrap text-kyro-muted">{String(s.fecha_hora).slice(11, 16)}</td>
                      <td className="max-w-[260px] truncate px-2" title={s.descripcion}>
                        <b>{s.tipo_operacion}</b> {s.descripcion}
                        {s.codigo_personal && <span className="ml-1 text-kyro-gold">({s.codigo_personal})</span>}
                      </td>
                      <td className="text-right font-semibold">{soles(s.monto)}</td>
                    </tr>
                  ))}
                  {detalle.scraper_logs.length === 0 && <tr><td className="py-4 text-center text-kyro-muted">Sin registros de scraping.</td></tr>}
                </tbody>
              </table>
            </div>
          </div>
          <div className="kyro-card p-4">
            <h3 className="mb-2 text-xs font-bold uppercase text-kyro-gold">Declaraciones en reportes (ERP)</h3>
            <div className="max-h-80 overflow-y-auto">
              <table className="w-full text-[0.68rem]">
                <thead className="text-[0.6rem] uppercase text-kyro-muted">
                  <tr><th className="text-left">Cajero</th><th className="text-right">Bipay</th><th className="text-right">Recarga</th><th className="text-right">Retiro</th><th className="text-right">Neto</th></tr>
                </thead>
                <tbody>
                  {detalle.erp_logs.map((e) => (
                    <tr key={e.id} className="border-t border-white/5">
                      <td className="py-1">{e.usuario} <span className="text-kyro-muted">({e.agente ?? '—'})</span></td>
                      <td className="text-right">{soles(e.bipay)}</td>
                      <td className="text-right">+{soles(e.recarga_bipay)}</td>
                      <td className="text-right">-{soles(e.retiro_bipay)}</td>
                      <td className="text-right font-bold">{soles(e.total_calculado)}</td>
                    </tr>
                  ))}
                  {detalle.erp_logs.length === 0 && <tr><td colSpan={5} className="py-4 text-center text-kyro-muted">Sin reportes declarados.</td></tr>}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

/* ── Tab: Morosidad / churn ───────────────────────────────────────────────── */

function TabMorosidad() {
  const qc = useQueryClient()
  const [periodo, setPeriodo] = useState(hoy().slice(0, 7))

  const { data, isLoading } = useQuery({
    queryKey: ['integrador-morosidad', periodo],
    queryFn:  () => integradorApi.morosidad(periodo),
  })
  const solicitar = useMutation({
    mutationFn: () => integradorApi.solicitarExtraccion(periodo),
    onSuccess:  () => qc.invalidateQueries({ queryKey: ['integrador-morosidad', periodo] }),
  })

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <input type="month" value={periodo} onChange={(e) => setPeriodo(e.target.value)}
          className="rounded border border-white/10 bg-transparent px-2 py-1.5 text-xs" />
        <button onClick={() => solicitar.mutate()} disabled={solicitar.isPending}
          className="rounded-lg bg-kyro-gold px-3 py-1.5 text-xs font-bold text-black disabled:opacity-50">
          Solicitar extracción de deudas
        </button>
        {data?.solicitud && (
          <span className="text-xs text-kyro-muted">
            Solicitud: <b>{data.solicitud.estado}</b> · {data.solicitud.total_lineas} líneas
          </span>
        )}
      </div>

      {data?.clientes_estado && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {[
            ['Líneas', data.clientes_estado.total],
            ['Churn (bajas)', data.clientes_estado.churn],
            ['Suspendidas', data.clientes_estado.suspendidos],
            ['Deuda total', soles(Number(data.clientes_estado.deuda_total ?? 0))],
          ].map(([lbl, val]) => (
            <div key={String(lbl)} className="kyro-card p-3 text-center">
              <div className="text-lg font-bold">{val ?? 0}</div>
              <div className="text-[0.65rem] uppercase text-kyro-muted">{lbl}</div>
            </div>
          ))}
        </div>
      )}

      <div className="kyro-card overflow-x-auto">
        {isLoading ? <p className="py-8 text-center text-sm text-kyro-muted">Cargando...</p> : (
          <table className="w-full text-xs">
            <thead className="text-[0.65rem] uppercase text-kyro-muted">
              <tr>
                <th className="px-3 py-2 text-left">Línea</th>
                <th className="px-3 py-2 text-left">Cliente</th>
                <th className="px-3 py-2 text-left">Tienda</th>
                <th className="px-3 py-2 text-left">Plan</th>
                <th className="px-3 py-2 text-center">Estado</th>
                <th className="px-3 py-2 text-right">Días mora</th>
                <th className="px-3 py-2 text-right">Deuda</th>
              </tr>
            </thead>
            <tbody>
              {(data?.lineas ?? []).map((l, i) => (
                <tr key={i} className="border-t border-white/5">
                  <td className="px-3 py-1.5 font-mono">{l.numero_linea}</td>
                  <td className="px-3 py-1.5">{l.cliente ?? '—'}</td>
                  <td className="px-3 py-1.5">{l.cod_tienda ?? '—'}</td>
                  <td className="px-3 py-1.5">{l.plan ?? '—'}</td>
                  <td className="px-3 py-1.5 text-center">
                    <EstadoBadge ok={l.estado_linea === 'ACTIVO'} estado={l.estado_linea} />
                  </td>
                  <td className="px-3 py-1.5 text-right">{l.dias_mora}</td>
                  <td className="px-3 py-1.5 text-right font-semibold text-kyro-danger">{soles(l.monto_deuda)}</td>
                </tr>
              ))}
              {(data?.lineas ?? []).length === 0 && (
                <tr><td colSpan={7} className="px-3 py-6 text-center text-kyro-muted">Sin líneas para este período. Solicita la extracción y espera el siguiente ciclo del agente.</td></tr>
              )}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}

/* ── Panel embebido (pestaña "Cuadre Bitel" del Panel Bipay) ──────────────── */

function sumarDias(fecha: string, delta: number): string {
  const d = new Date(`${fecha}T00:00:00`)
  d.setDate(d.getDate() + delta)
  return d.toISOString().slice(0, 10)
}

export function CuadreBitelPanel({ initialTab, openWebhook = false }: { initialTab?: Tab; openWebhook?: boolean }) {
  const [tab, setTab]     = useState<Tab>(initialTab ?? 'panel')
  const [fecha, setFecha] = useState(hoy())

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="kyro-card flex w-fit flex-wrap gap-1 p-1">
          {TABS.map((t) => (
            <Button key={t.id} variant="ghost" size="sm" onClick={() => setTab(t.id)}
              className={`rounded-md px-3 py-1.5 text-xs font-semibold transition-colors ${
                tab === t.id ? 'bg-kyro-elevated text-kyro-text shadow-sm' : 'text-kyro-muted hover:bg-kyro-elevated hover:text-kyro-text'
              }`}>
              {t.label}
            </Button>
          ))}
        </div>
        {tab !== 'morosidad' && (
          <div className="flex items-center gap-1.5">
            <Button variant="outline" size="sm" onClick={() => setFecha((f) => sumarDias(f, -1))} aria-label="Día anterior">
              <ChevronLeft size={14} />
            </Button>
            <input type="date" value={fecha} max={hoy()} onChange={(e) => setFecha(e.target.value)} className="kyro-input" />
            <Button variant="outline" size="sm" disabled={fecha >= hoy()} onClick={() => setFecha((f) => sumarDias(f, 1))} aria-label="Día siguiente">
              <ChevronRight size={14} />
            </Button>
            <Button variant="gold" size="sm" onClick={() => setFecha(hoy())}>Hoy</Button>
          </div>
        )}
      </div>
      <p className="text-[0.7rem] text-kyro-muted">
        Conciliación de lo declarado en los cuadres del ERP contra el detalle scrapeado del portal Bitel.
      </p>

      {tab === 'panel'       && <TabPanel fecha={fecha} />}
      {tab === 'global'      && <TabGlobal fecha={fecha} />}
      {tab === 'movimientos' && <TabMovimientos fecha={fecha} />}
      {tab === 'auditoria'   && <TabAuditoria fecha={fecha} webhookOpenDefault={openWebhook} />}
      {tab === 'morosidad'   && <TabMorosidad />}
    </div>
  )
}
