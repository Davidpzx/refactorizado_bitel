import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useReporte } from '../../hooks/useReportes'
import { Button } from '../../components/ui/button'
import { Badge } from '../../components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '../../components/ui/card'
import { reportesApi, type HistorialReporteEntry } from '../../services/reportes.api'
import { useAuth } from '../../hooks/useAuth'
import { api } from '../../services/api'
import type { ReporteConVentas, VentaConDetalle } from '../../types/reporte'

// ─── Helpers ──────────────────────────────────────────────────────────────────

function sol(val: string | number | null | undefined): string {
  if (val == null) return 'S/ 0.00'
  const n = parseFloat(String(val))
  return `S/ ${isNaN(n) ? '0.00' : n.toFixed(2)}`
}

function fmtFecha(fecha: string): string {
  const [y, m, d] = fecha.slice(0, 10).split('-')
  const meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']
  return `${parseInt(d, 10)} ${meses[parseInt(m, 10) - 1]} ${y}`
}

function n(val: string | null | undefined): number {
  return parseFloat(val ?? '0') || 0
}

type EstadoVariant = 'default' | 'warning' | 'success' | 'outline'
const ESTADO_VARIANT: Record<ReporteConVentas['estado'], EstadoVariant> = {
  borrador: 'outline',
  enviado:  'default',
  editado:  'warning',
  aprobado: 'success',
}
const ESTADO_LABEL: Record<ReporteConVentas['estado'], string> = {
  borrador: 'Borrador',
  enviado:  'Enviado',
  editado:  'Editado',
  aprobado: 'Aprobado',
}
const DESTINO_LABEL: Record<string, string> = {
  TIENDA:     'Queda en tienda',
  ENTREGADO:  'Entregado al supervisor',
  EN_CAJA:    'Permanece en caja',
  BANCO:      'Depósito bancario',
}

// ─── Componentes de presentación ─────────────────────────────────────────────

function FilaRecibo({
  label,
  valor,
  colorValor = '',
  negrito = false,
  indent = false,
  separador = false,
}: {
  label: string
  valor: string
  colorValor?: string
  negrito?: boolean
  indent?: boolean
  separador?: boolean
}) {
  return (
    <>
      {separador && <div className="border-t border-kyro-border my-1.5" />}
      <div className={`flex justify-between items-center py-1 ${indent ? 'pl-3 border-l-2 border-kyro-border' : ''}`}>
        <span className={`text-sm ${negrito ? 'font-semibold text-kyro-text' : 'text-kyro-muted'}`}>
          {label}
        </span>
        <span className={`text-sm font-mono tabular-nums ${negrito ? 'font-bold' : ''} ${colorValor || 'text-kyro-text'}`}>
          {valor}
        </span>
      </div>
    </>
  )
}

function PillTag({ children, color = 'gray' }: { children: React.ReactNode; color?: 'gray' | 'blue' | 'amber' | 'green' | 'red' }) {
  const colors = {
    gray:  'bg-kyro-elevated text-kyro-muted',
    blue:  'bg-kyro-info/15 text-kyro-info',
    amber: 'bg-kyro-warning/15 text-kyro-warning',
    green: 'bg-kyro-success/15 text-kyro-success',
    red:   'bg-kyro-danger/15 text-kyro-danger',
  }
  return (
    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium ${colors[color]}`}>
      {children}
    </span>
  )
}

// ─── Fila de venta: Postpago / Prepago ────────────────────────────────────────

function FilaLinea({ venta, idx }: { venta: VentaConDetalle; idx: number }) {
  const l = venta.linea
  const comision = n(l?.comision_unitaria)

  return (
    <div className={`px-4 py-3 ${idx > 0 ? 'border-t border-kyro-border' : ''} hover:bg-kyro-elevated/60 transition-colors`}>
      <div className="flex items-start justify-between gap-3">
        <div className="flex-1 min-w-0">
          <p className="text-sm font-medium text-kyro-text leading-tight">
            {l?.plan_nombre_snap ?? '—'}
          </p>
          <div className="flex flex-wrap items-center gap-1.5 mt-1">
            <PillTag color="blue">{l?.tipo_alta ?? 'LN'}</PillTag>
            {(l?.cantidad ?? 1) > 1 && (
              <PillTag color="gray">× {l?.cantidad} unidades</PillTag>
            )}
            {l?.es_esim && <PillTag color="blue">eSIM</PillTag>}
            {venta.subtipo && <PillTag color="gray">{venta.subtipo}</PillTag>}
            {venta.es_remate && <PillTag color="amber">Remate</PillTag>}
            {venta.es_extranjero && <PillTag color="amber">Extranjero</PillTag>}
            {venta.cross_selling && (
              <PillTag color="blue">↗ Cross {venta.tienda_destino}</PillTag>
            )}
            {venta.cliente && (
              <PillTag color="gray">
                {venta.cliente.tipo_documento} {venta.cliente.dni_ruc}
                {venta.cliente.nombre !== 'Por identificar' ? ` · ${venta.cliente.nombre}` : ''}
              </PillTag>
            )}
          </div>
        </div>
        <div className="text-right shrink-0">
          <p className="text-sm font-semibold text-kyro-text tabular-nums">{sol(venta.monto_total)}</p>
          {comision > 0 && (
            <p className="text-[11px] text-kyro-success font-medium mt-0.5">
              comisión {sol(l?.comision_unitaria)}
            </p>
          )}
        </div>
      </div>
    </div>
  )
}

// ─── Fila de venta: Equipo / Accesorio ───────────────────────────────────────

function FilaEquipo({ venta, idx }: { venta: VentaConDetalle; idx: number }) {
  const e = venta.equipo
  const ganancia = n(e?.ganancia_snap)
  const porCobrar = n(e?.por_cobrar_financiera)

  return (
    <div className={`px-4 py-3 ${idx > 0 ? 'border-t border-kyro-border' : ''} hover:bg-kyro-elevated/60 transition-colors`}>
      <div className="flex items-start justify-between gap-3">
        <div className="flex-1 min-w-0">
          <p className="text-sm font-medium text-kyro-text leading-tight">
            {e?.producto_nombre_snap ?? venta.subtipo ?? '—'}
          </p>
          <div className="flex flex-wrap items-center gap-1.5 mt-1">
            <PillTag color={venta.tipo_venta === 'ACCESORIO' ? 'gray' : 'blue'}>
              {venta.tipo_venta}
            </PillTag>
            {e?.imei_serial_snap && (
              <PillTag color="gray">IMEI {e.imei_serial_snap}</PillTag>
            )}
            {e?.tipo_pago && (
              <PillTag color={e.tipo_pago === 'CUOTAS' ? 'amber' : 'gray'}>
                {e.tipo_pago}
              </PillTag>
            )}
            {e?.financiera && <PillTag color="blue">{e.financiera}</PillTag>}
            {venta.es_remate && <PillTag color="amber">Remate</PillTag>}
            {venta.cliente && (
              <PillTag color="gray">
                {venta.cliente.tipo_documento} {venta.cliente.dni_ruc}
              </PillTag>
            )}
          </div>
        </div>
        <div className="text-right shrink-0">
          <p className="text-sm font-semibold text-kyro-text tabular-nums">{sol(venta.monto_total)}</p>
          {ganancia > 0 && (
            <p className="text-[11px] text-kyro-success font-medium mt-0.5">
              ganancia {sol(e?.ganancia_snap)}
            </p>
          )}
          {porCobrar > 0 && (
            <p className="text-[11px] text-kyro-info mt-0.5">
              financiera {sol(e?.por_cobrar_financiera)}
            </p>
          )}
        </div>
      </div>
    </div>
  )
}

// ─── Fila de venta: Otros Flujo ──────────────────────────────────────────────

function FilaOtro({ venta, idx }: { venta: VentaConDetalle; idx: number }) {
  return (
    <div className={`px-4 py-3 ${idx > 0 ? 'border-t border-kyro-border' : ''} hover:bg-kyro-elevated/60 transition-colors`}>
      <div className="flex items-center justify-between gap-3">
        <div className="flex-1 min-w-0">
          <p className="text-sm font-medium text-kyro-text">
            {venta.subtipo ?? 'OTROS FLUJO'}
          </p>
          {venta.cliente && (
            <p className="text-xs text-kyro-muted mt-0.5">
              {venta.cliente.tipo_documento} {venta.cliente.dni_ruc} · {venta.cliente.nombre}
            </p>
          )}
        </div>
        <p className="text-sm font-semibold text-kyro-text tabular-nums shrink-0">{sol(venta.monto_total)}</p>
      </div>
    </div>
  )
}

// ─── Card de sección de ventas ───────────────────────────────────────────────

function SeccionVentas({
  titulo,
  ventas,
  totalLabel,
  vacias = 'Sin registros en esta sección.',
  renderFila,
}: {
  titulo: string
  ventas: VentaConDetalle[]
  totalLabel?: string
  vacias?: string
  renderFila: (v: VentaConDetalle, i: number) => React.ReactNode
}) {
  const total = ventas.reduce((acc, v) => acc + n(v.monto_total), 0)

  return (
    <Card className="kyro-card">
      <CardHeader className="py-2.5 px-4">
        <div className="flex items-center justify-between">
          <CardTitle className="text-xs font-bold uppercase tracking-widest text-kyro-muted">
            {titulo}
          </CardTitle>
          <div className="flex items-center gap-2">
            {ventas.length > 0 && (
              <>
                <span className="text-xs text-kyro-subtle">{ventas.length} registro{ventas.length !== 1 ? 's' : ''}</span>
                <span className="text-sm font-bold text-kyro-text tabular-nums">{sol(total)}</span>
              </>
            )}
          </div>
        </div>
      </CardHeader>
      <CardContent className="p-0">
        {ventas.length === 0 ? (
          <p className="px-4 py-4 text-sm text-kyro-subtle italic">{vacias}</p>
        ) : (
          <div>{ventas.map((v, i) => renderFila(v, i))}</div>
        )}
        {ventas.length > 0 && totalLabel && (
          <div className="px-4 py-2 border-t border-kyro-border bg-kyro-elevated/50 flex justify-between items-center">
            <span className="text-xs text-kyro-muted font-medium">{totalLabel}</span>
            <span className="text-sm font-bold text-kyro-text tabular-nums">{sol(total)}</span>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

// ─── Card: Dinero Digital / Retiros ──────────────────────────────────────────

function DineroDigitalCard({ reporte }: { reporte: ReporteConVentas }) {
  const yape        = n(reporte.yape)
  const bipay       = n(reporte.bipay)
  const transf      = n(reporte.transferencia)
  const retBipay    = n(reporte.retiro_bipay)
  const recBipay    = n(reporte.recarga_bipay)
  const pagoServ    = n(reporte.pago_servicio)
  const pagoKrece   = n(reporte.pago_krece)
  const pagoPayjoy  = n(reporte.pago_payjoy)
  const tickets     = n(reporte.tickets_tusamy)

  const totalDigital = yape + bipay + transf
  const totalRetiros = retBipay
  const totalAdicional = recBipay + pagoServ + pagoKrece + pagoPayjoy + tickets

  const hayDigital   = totalDigital > 0 || retBipay > 0
  const hayAdicional = totalAdicional > 0

  return (
    <Card className="kyro-card">
      <CardHeader className="py-2.5 px-4">
        <CardTitle className="text-xs font-bold uppercase tracking-widest text-kyro-muted">
          Dinero Digital y Retiros
        </CardTitle>
      </CardHeader>
      <CardContent className="px-4 py-3">
        {!hayDigital && !hayAdicional ? (
          <p className="text-sm text-kyro-subtle italic">Sin movimientos digitales.</p>
        ) : (
          <div className="space-y-0.5">
            {/* Medios de pago digital */}
            {yape > 0 && (
              <FilaRecibo label="Yape" valor={sol(yape)} colorValor="text-kyro-gold" indent />
            )}
            {bipay > 0 && (
              <FilaRecibo label="Bipay" valor={sol(bipay)} colorValor="text-kyro-info" indent />
            )}
            {transf > 0 && (
              <FilaRecibo label="Transferencia" valor={sol(transf)} colorValor="text-kyro-info" indent />
            )}
            {retBipay > 0 && (
              <FilaRecibo label="Retiro Bipay" valor={`− ${sol(retBipay)}`} colorValor="text-kyro-danger" indent />
            )}

            {hayDigital && (
              <FilaRecibo
                label="Subtotal digital"
                valor={sol(totalDigital - totalRetiros)}
                negrito
                separador
              />
            )}

            {/* Ingresos adicionales */}
            {hayAdicional && (
              <>
                <div className="pt-2 pb-1">
                  <p className="text-[10px] uppercase tracking-widest text-kyro-subtle font-semibold">
                    Ingresos adicionales
                  </p>
                </div>
                {recBipay > 0 && (
                  <FilaRecibo label="Recarga Bipay" valor={sol(recBipay)} indent />
                )}
                {pagoServ > 0 && (
                  <FilaRecibo label="Pago de servicio" valor={sol(pagoServ)} indent />
                )}
                {pagoKrece > 0 && (
                  <FilaRecibo label="Pago Krece" valor={sol(pagoKrece)} indent />
                )}
                {pagoPayjoy > 0 && (
                  <FilaRecibo label="Pago Payjoy" valor={sol(pagoPayjoy)} indent />
                )}
                {tickets > 0 && (
                  <FilaRecibo label="Tickets Tusamy" valor={sol(tickets)} indent />
                )}
                <FilaRecibo
                  label="Subtotal adicional"
                  valor={sol(totalAdicional)}
                  negrito
                  separador
                />
              </>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  )
}

function SalidasCard({ reporte }: { reporte: ReporteConVentas }) {
  if (!reporte.salidas?.length) return null

  return (
    <Card className="kyro-card">
      <CardHeader className="px-4 py-2.5">
        <CardTitle className="text-xs font-bold uppercase tracking-widest text-kyro-muted">
          Salidas y Gastos
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-2 px-4 py-3">
        {reporte.salidas.map(salida => (
          <div key={salida.id ?? `${salida.tipo}-${salida.monto}-${salida.observacion ?? ''}`} className="border-b border-kyro-border pb-2 last:border-0">
            <div className="flex items-center justify-between">
              <span className="text-sm font-medium capitalize text-kyro-text">{salida.tipo}</span>
              <span className="font-mono text-sm font-bold text-kyro-danger">− {sol(salida.monto)}</span>
            </div>
            {salida.observacion && <p className="mt-0.5 text-xs text-kyro-muted">{salida.observacion}</p>}
          </div>
        ))}
      </CardContent>
    </Card>
  )
}

// ─── Card: Cuadre Final de Efectivo ──────────────────────────────────────────

function CuadreFinalCard({
  reporte,
  isAdmin = false,
  onCambiarDestino,
  isPendingDestino = false,
}: {
  reporte: ReporteConVentas
  isAdmin?: boolean
  onCambiarDestino?: (destino: string, obs: string) => void
  isPendingDestino?: boolean
}) {
  const [showForm, setShowForm] = useState(false)
  const [nuevoDestino, setNuevoDestino] = useState('')
  const [obsDestino, setObsDestino] = useState('')
  const diff      = n(reporte.diferencia)
  const absDiff   = Math.abs(diff)
  const esOk      = absDiff < 0.01
  const esLeve    = !esOk && absDiff <= 10
  const esGrave   = absDiff > 10

  const diffColor = esOk ? 'text-kyro-success' : esLeve ? 'text-kyro-warning' : 'text-kyro-danger'
  const diffBg    = esOk
    ? 'bg-kyro-success/10 border-kyro-success'
    : esLeve
    ? 'bg-kyro-warning/10 border-kyro-warning'
    : 'bg-kyro-danger/10 border-kyro-danger'
  const diffLabel = esOk
    ? '✓ Cuadre exacto'
    : esLeve
    ? `Diferencia leve (${sol(diff)})`
    : `Descuadre (${sol(diff)})`

  const destino = reporte.destino_efectivo
    ? (DESTINO_LABEL[reporte.destino_efectivo] ?? reporte.destino_efectivo)
    : '—'

  return (
    <Card className="kyro-card">
      <CardHeader className="py-2.5 px-4">
        <CardTitle className="text-xs font-bold uppercase tracking-widest text-kyro-muted">
          Cuadre Final de Efectivo
        </CardTitle>
      </CardHeader>
      <CardContent className="px-4 py-3">
        <div className="space-y-0.5">
          <FilaRecibo label="Caja inicial" valor={sol(reporte.caja_inicial)} />
          <FilaRecibo label="Total ventas (calculado)" valor={sol(reporte.total_calculado)} />
          <FilaRecibo label="Total salidas" valor={`− ${sol(reporte.total_salidas)}`} />
          <FilaRecibo
            label="Esperado por sistema"
            valor={sol(reporte.efectivo_esperado)}
            negrito
            separador
          />
          <FilaRecibo
            label="Efectivo físico entregado"
            valor={sol(reporte.efectivo_entregado)}
            negrito
          />
        </div>

        {/* Diferencia — bloque destacado */}
        <div className={`mt-3 rounded-lg border px-3 py-2.5 ${diffBg}`}>
          <div className="flex justify-between items-center">
            <span className={`text-sm font-semibold ${diffColor}`}>{diffLabel}</span>
            {!esOk && (
              <span className={`text-sm font-bold tabular-nums ${diffColor}`}>
                {sol(reporte.diferencia)}
              </span>
            )}
          </div>
          {esGrave && reporte.requiere_aprobacion && (
            <p className="text-xs text-kyro-danger mt-1">Requiere aprobación del supervisor</p>
          )}
        </div>

        {/* Destino del efectivo */}
        <div className="mt-3 pt-3 border-t border-kyro-border">
          <div className="flex justify-between items-center">
            <span className="text-xs text-kyro-muted font-medium uppercase tracking-wide">
              Destino del efectivo
            </span>
            <div className="flex items-center gap-2">
              <span className="text-xs font-semibold text-kyro-body">{destino}</span>
              {isAdmin && onCambiarDestino && (
                <button
                  type="button"
                  onClick={() => { setShowForm(s => !s); setNuevoDestino(''); setObsDestino('') }}
                  className="text-[10px] text-kyro-info hover:text-kyro-gold underline"
                >
                  {showForm ? 'Cancelar' : 'Cambiar'}
                </button>
              )}
            </div>
          </div>
          {showForm && onCambiarDestino && (
            <div className="mt-2 space-y-2 rounded-kyro border border-kyro-border bg-kyro-elevated p-3">
              <select
                value={nuevoDestino}
                onChange={e => setNuevoDestino(e.target.value)}
                className="kyro-input w-full text-xs"
              >
                <option value="">— Seleccionar destino —</option>
                <option value="BANCO">Depositado en Banco</option>
                <option value="ENTREGADO">Entregado al Supervisor/Gerente</option>
                <option value="EN_CAJA">Guardado en Caja Fuerte Central</option>
                <option value="TIENDA">⚠ Revertir — Sigue en Tienda</option>
              </select>
              <textarea
                value={obsDestino}
                onChange={e => setObsDestino(e.target.value)}
                placeholder="Observación (opcional)"
                rows={2}
                className="kyro-input w-full resize-none text-xs"
              />
              <Button
                size="sm"
                className="w-full"
                disabled={!nuevoDestino || isPendingDestino}
                onClick={() => {
                  onCambiarDestino(nuevoDestino, obsDestino)
                  setShowForm(false)
                }}
              >
                {isPendingDestino ? 'Guardando...' : 'Guardar destino'}
              </Button>
            </div>
          )}
        </div>

        {/* Total salidas como referencia */}
        {n(reporte.total_salidas) > 0 && (
          <p className="text-[11px] text-kyro-subtle mt-1 text-right">
            Salidas registradas: {sol(reporte.total_salidas)}
          </p>
        )}
      </CardContent>
    </Card>
  )
}

// ─── Historial de Auditoría ───────────────────────────────────────────────────

const ACCION_CONFIG: Record<HistorialReporteEntry['accion'], { label: string; color: string; dot: string }> = {
  crear:               { label: 'Reporte creado',          color: 'text-kyro-success', dot: 'bg-kyro-success' },
  solicito_edicion:    { label: 'Edición solicitada',      color: 'text-kyro-warning', dot: 'bg-kyro-warning' },
  edicion_aprobada:    { label: 'Edición aprobada',        color: 'text-kyro-info',    dot: 'bg-kyro-info' },
  edicion_rechazada:   { label: 'Edición rechazada',       color: 'text-kyro-danger',  dot: 'bg-kyro-danger' },
  edicion_reporte:     { label: 'Reporte editado',         color: 'text-kyro-gold',    dot: 'bg-kyro-gold' },
  edicion_critica:     { label: 'Edición crítica',         color: 'text-kyro-danger',  dot: 'bg-kyro-danger' },
  edicion_restaurada:  { label: 'Reporte restaurado',      color: 'text-kyro-success', dot: 'bg-kyro-success' },
  destino_modificado:  { label: 'Destino modificado',      color: 'text-kyro-info',    dot: 'bg-kyro-info' },
}

function HistorialTimeline({ reporteId }: { reporteId: number }) {
  const { data: historial = [], isLoading } = useQuery<HistorialReporteEntry[]>({
    queryKey: ['historial-reporte', reporteId],
    queryFn: () => reportesApi.historial(reporteId),
    staleTime: 30_000,
  })

  if (isLoading) {
    return (
      <div className="space-y-3">
        {[1, 2].map(i => (
          <div key={i} className="flex gap-3 animate-pulse">
            <div className="w-2.5 h-2.5 rounded-full bg-kyro-elevated mt-1.5 shrink-0" />
            <div className="flex-1 space-y-1.5">
              <div className="h-3.5 bg-kyro-elevated rounded w-1/3" />
              <div className="h-3 bg-kyro-card rounded w-2/3" />
            </div>
          </div>
        ))}
      </div>
    )
  }

  if (!historial.length) {
    return <p className="text-xs text-kyro-subtle italic py-2">Sin eventos registrados.</p>
  }

  return (
    <ol className="relative border-l border-kyro-border ml-1.5 space-y-4">
      {historial.map((entry) => {
        const cfg = ACCION_CONFIG[entry.accion] ?? { label: entry.accion, color: 'text-kyro-body', dot: 'bg-kyro-muted' }
        const fecha = new Date(entry.created_at)
        const fechaStr = `${fecha.toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' })} ${fecha.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })}`

        return (
          <li key={entry.id} className="ml-4">
            <span className={`absolute -left-1.5 w-3 h-3 rounded-full border-2 border-white ${cfg.dot}`} />
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
              <span className={`text-sm font-semibold ${cfg.color}`}>{cfg.label}</span>
              <span className="text-[10px] text-kyro-subtle shrink-0">{fechaStr}</span>
            </div>
            {entry.usuario?.nombre && (
              <p className="text-xs text-kyro-muted mt-0.5">por {entry.usuario.nombre}</p>
            )}
            {entry.detalle && (
              <p className="text-xs text-kyro-body mt-1 bg-kyro-elevated rounded px-2 py-1 leading-relaxed">
                {entry.detalle}
              </p>
            )}
          </li>
        )
      })}
    </ol>
  )
}

// ─── Skeleton de carga ────────────────────────────────────────────────────────

function SkeletonBloque({ h = 'h-4', w = 'w-full' }: { h?: string; w?: string }) {
  return <div className={`${h} ${w} bg-kyro-elevated rounded animate-pulse`} />
}

function SkeletonDetalle() {
  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <SkeletonBloque h="h-8" w="w-24" />
        <SkeletonBloque h="h-5" w="w-48" />
      </div>
      <Card>
        <CardContent className="p-5 space-y-3">
          <SkeletonBloque h="h-6" w="w-1/3" />
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            {[...Array(4)].map((_, i) => <SkeletonBloque key={i} h="h-12" />)}
          </div>
        </CardContent>
      </Card>
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 space-y-4">
          {[...Array(3)].map((_, i) => <SkeletonBloque key={i} h="h-32" />)}
        </div>
        <div className="space-y-4">
          <SkeletonBloque h="h-40" />
          <SkeletonBloque h="h-48" />
        </div>
      </div>
    </div>
  )
}

// ─── Página principal ─────────────────────────────────────────────────────────

export function ReporteDetallePage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { usuario } = useAuth()
  const qc = useQueryClient()
  const { data: reporte, isLoading, isError } = useReporte(Number(id))

  const aprobarEdicion = useMutation({
    mutationFn: (reporteId: number) =>
      api.post(`/v1/reportes/${reporteId}/aprobar-edicion`).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['reporte', Number(id)] })
      qc.invalidateQueries({ queryKey: ['historial'] })
    },
  })

  const marcarDestino = useMutation({
    mutationFn: ({ destino, observacion }: { destino: string; observacion: string }) =>
      api.patch(`/v1/reportes/${id}/destino-efectivo`, { destino_efectivo: destino, observacion }).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['reporte', Number(id)] })
      qc.invalidateQueries({ queryKey: ['historial-reporte', Number(id)] })
    },
  })

  if (isLoading) return <SkeletonDetalle />

  if (isError || !reporte) {
    return (
      <div className="flex flex-col items-center justify-center py-24 gap-4">
        <p className="text-kyro-muted">No se pudo cargar el reporte o no existe.</p>
        <Button variant="outline" onClick={() => navigate('/reportes')}>
          ← Volver a reportes
        </Button>
      </div>
    )
  }

  const ventas    = reporte.ventas ?? []
  const postpago  = ventas.filter(v => v.tipo_venta === 'POSTPAGO')
  const prepago   = ventas.filter(v => v.tipo_venta === 'PREPAGO')
  const equipos   = ventas.filter(v => v.tipo_venta === 'EQUIPO' || v.tipo_venta === 'ACCESORIO')
  const otros     = ventas.filter(v => v.tipo_venta === 'OTROS_FLUJO')

  const totalVentas = ventas.reduce((acc, v) => acc + n(v.monto_total), 0)
  const diff        = n(reporte.diferencia)
  const diffOk      = Math.abs(diff) < 0.01
  const diffGrave   = Math.abs(diff) > 10

  return (
    <div className="pb-12">

      {/* ── Navegación ── */}
      <div className="flex items-center justify-between mb-5">
        <Button
          variant="ghost"
          onClick={() => navigate('/reportes')}
          className="text-kyro-muted hover:text-kyro-text"
        >
          <span>←</span>
          <span>Reportes</span>
        </Button>
        <p className="text-xs text-kyro-subtle">
          Reporte #{reporte.id} · creado {reporte.created_at?.slice(0, 10)}
        </p>
      </div>

      {/* ── Cabecera del reporte ── */}
      <div className="kyro-card mb-6 overflow-hidden">
        {/* Banda de estado */}
        <div
          className={`h-1.5 w-full ${
            reporte.estado === 'aprobado'
              ? 'bg-kyro-success'
              : reporte.estado === 'enviado'
              ? 'bg-kyro-info'
              : reporte.estado === 'editado'
              ? 'bg-kyro-warning'
              : 'bg-kyro-muted'
          }`}
        />

        <div className="p-5">
          {/* Fila superior: título + badges */}
          <div className="flex flex-wrap items-start justify-between gap-3 mb-4">
            <div>
              <h1 className="text-lg font-orbitron font-bold text-kyro-text leading-tight">
                {reporte.tienda_id} — {fmtFecha(reporte.fecha)}
              </h1>
              {reporte.nombre_cubre && (
                <p className="text-sm text-kyro-muted mt-0.5">
                  Cubre: <span className="font-medium text-kyro-body">{reporte.nombre_cubre}</span>
                </p>
              )}
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <Badge variant={ESTADO_VARIANT[reporte.estado]}>
                {ESTADO_LABEL[reporte.estado]}
              </Badge>
              {reporte.requiere_aprobacion && reporte.estado !== 'aprobado' && (
                <Badge variant="destructive">Requiere aprobación</Badge>
              )}
              {reporte.estado_edicion === 'SOLICITADO' && (
                <>
                  <Badge variant="warning">Edición solicitada</Badge>
                  {usuario?.rol === 'admin' && (
                    <Button
                      size="sm"
                      disabled={aprobarEdicion.isPending}
                      onClick={() => {
                        if (confirm('¿Aprobar la solicitud de edición de este reporte?')) {
                          aprobarEdicion.mutate(reporte.id)
                        }
                      }}
                      className="h-7 gap-1 bg-kyro-success/15 px-2 text-xs text-kyro-success hover:bg-kyro-success/30"
                    >
                      ✓ Aprobar edición
                    </Button>
                  )}
                </>
              )}
            </div>
          </div>

          {/* Métricas rápidas */}
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div className="kyro-card border-l-4 border-l-kpi-neutral px-3 py-2.5">
              <p className="text-[10px] uppercase tracking-widest text-kyro-subtle font-semibold mb-0.5">Tienda</p>
              <p className="text-sm font-bold text-kyro-text">{reporte.tienda_id}</p>
            </div>
            <div className="kyro-card border-l-4 border-l-kpi-neutral px-3 py-2.5">
              <p className="text-[10px] uppercase tracking-widest text-kyro-subtle font-semibold mb-0.5">Agente</p>
              <p className="text-sm font-bold text-kyro-text">#{reporte.agente_id}</p>
            </div>
            <div className="kyro-card border-l-4 border-l-kpi-total px-3 py-2.5">
              <p className="text-[10px] uppercase tracking-widest text-kyro-subtle font-semibold mb-0.5">Total ventas</p>
              <p className="text-sm font-bold text-kyro-text tabular-nums">{sol(totalVentas)}</p>
              {ventas.length > 0 && (
                <p className="text-[10px] text-kyro-subtle mt-0.5">{ventas.length} transacciones</p>
              )}
            </div>
            <div className={`kyro-card border-l-4 px-3 py-2.5 ${
              diffOk ? 'border-l-kyro-success' : diffGrave ? 'border-l-kyro-danger' : 'border-l-kyro-warning'
            }`}>
              <p className="text-[10px] uppercase tracking-widest text-kyro-subtle font-semibold mb-0.5">Diferencia</p>
              <p className={`text-sm font-bold tabular-nums ${
                diffOk ? 'text-kyro-success' : diffGrave ? 'text-kyro-danger' : 'text-kyro-warning'
              }`}>
                {diffOk ? '✓ Cuadrado' : sol(reporte.diferencia)}
              </p>
              <p className="text-[10px] text-kyro-subtle mt-0.5">
                Efectivo: {sol(reporte.efectivo_entregado)}
              </p>
            </div>
          </div>
        </div>
      </div>

      {/* ── Grid principal ── */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {/* ── Columna de ventas (2/3) ── */}
        <div className="lg:col-span-2 space-y-4">

          {/* Postpago */}
          <SeccionVentas
            titulo="Ventas Postpago (Líneas)"
            ventas={postpago}
            totalLabel="Total postpago"
            vacias="Sin ventas postpago en este reporte."
            renderFila={(v, i) => <FilaLinea key={v.id} venta={v} idx={i} />}
          />

          {/* Prepago */}
          <SeccionVentas
            titulo="Ventas Prepago (Chips)"
            ventas={prepago}
            totalLabel="Total prepago"
            vacias="Sin ventas prepago en este reporte."
            renderFila={(v, i) => <FilaLinea key={v.id} venta={v} idx={i} />}
          />

          {/* Equipos y accesorios */}
          <SeccionVentas
            titulo="Equipos y Accesorios"
            ventas={equipos}
            totalLabel="Total equipos/accesorios"
            vacias="Sin ventas de equipos en este reporte."
            renderFila={(v, i) => <FilaEquipo key={v.id} venta={v} idx={i} />}
          />

          {/* Otros flujo */}
          {otros.length > 0 && (
            <SeccionVentas
              titulo="Otros Flujo"
              ventas={otros}
              totalLabel="Total otros"
              renderFila={(v, i) => <FilaOtro key={v.id} venta={v} idx={i} />}
            />
          )}

        </div>

        {/* ── Columna de caja (1/3) ── */}
        <div className="space-y-4">
          <DineroDigitalCard reporte={reporte} />
          <SalidasCard reporte={reporte} />
          <CuadreFinalCard
            reporte={reporte}
            isAdmin={usuario?.rol === 'admin'}
            onCambiarDestino={(destino, obs) => marcarDestino.mutate({ destino, observacion: obs })}
            isPendingDestino={marcarDestino.isPending}
          />
        </div>
      </div>

      {/* ── Observaciones y notas ── */}
      {(reporte.observaciones || reporte.obs_dia || reporte.motivo_edicion) && (
        <div className="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
          {reporte.obs_dia && (
            <Card>
              <CardHeader className="py-2.5 px-4">
                <CardTitle className="text-xs font-bold uppercase tracking-widest text-kyro-muted">
                  Notas del día
                </CardTitle>
              </CardHeader>
              <CardContent className="px-4 pb-4 pt-1">
                <p className="text-sm text-kyro-body whitespace-pre-wrap leading-relaxed">
                  {reporte.obs_dia}
                </p>
              </CardContent>
            </Card>
          )}
          {reporte.observaciones && (
            <Card>
              <CardHeader className="py-2.5 px-4">
                <CardTitle className="text-xs font-bold uppercase tracking-widest text-kyro-muted">
                  Observaciones de cuadre
                </CardTitle>
              </CardHeader>
              <CardContent className="px-4 pb-4 pt-1">
                <p className="text-sm text-kyro-body whitespace-pre-wrap leading-relaxed">
                  {reporte.observaciones}
                </p>
              </CardContent>
            </Card>
          )}
          {reporte.motivo_edicion && (
            <Card>
              <CardHeader className="py-2.5 px-4">
                <CardTitle className="text-xs font-bold uppercase tracking-widest text-kyro-warning">
                  Motivo de edición
                </CardTitle>
              </CardHeader>
              <CardContent className="px-4 pb-4 pt-1">
                <p className="text-sm text-kyro-warning whitespace-pre-wrap leading-relaxed">
                  {reporte.motivo_edicion}
                </p>
              </CardContent>
            </Card>
          )}
        </div>
      )}

      {/* ── Aprobación ── */}
      {reporte.estado === 'aprobado' && reporte.fecha_aprobacion && (
        <div className="mt-4 flex items-center gap-2 text-xs text-kyro-success">
          <span className="font-semibold">✓ Aprobado</span>
          <span className="text-kyro-subtle">·</span>
          <span>{reporte.fecha_aprobacion.slice(0, 10)}</span>
        </div>
      )}

      {/* ── Historial de Auditoría ── */}
      <Card className="kyro-card mt-6">
        <CardHeader className="py-3 px-4 border-b border-kyro-border">
          <CardTitle className="text-xs font-bold uppercase tracking-widest text-kyro-muted">
            Historial de auditoría
          </CardTitle>
        </CardHeader>
        <CardContent className="px-4 py-4">
          <HistorialTimeline reporteId={reporte.id} />
        </CardContent>
      </Card>

    </div>
  )
}
