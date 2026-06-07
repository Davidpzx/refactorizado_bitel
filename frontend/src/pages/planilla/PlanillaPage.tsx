import { useState, useCallback } from 'react'
import { format, startOfMonth } from 'date-fns'
import { usePlanilla, useGuardarAjustePlanilla, useResetarComisionesPlanilla } from '../../hooks/usePlanilla'
import { PageHeader } from '../../components/PageHeader'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { Card } from '../../components/ui/card'
import type { FilaPlanilla } from '../../types/planilla'

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmt = (n: number) =>
  new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n)

const fmtSol = (n: number) => `S/ ${fmt(n)}`

const mesActual = format(startOfMonth(new Date()), 'yyyy-MM')

// ── KPI Card ─────────────────────────────────────────────────────────────────

function KpiCard({ label, valor, color }: { label: string; valor: string; color: string }) {
  return (
    <Card className="px-4 py-3 text-center min-w-[120px]">
      <p className="text-xs text-muted-foreground leading-tight">{label}</p>
      <p className={`font-bold text-sm mt-1 ${color}`}>{valor}</p>
    </Card>
  )
}

// ── Celda editable con debounce AJAX ─────────────────────────────────────────

function CeldaEditable({
  valor,
  campo,
  fila,
  mes,
  esComision = false,
  onSave,
}: {
  valor: number
  campo: string
  fila: FilaPlanilla
  mes: string
  esComision?: boolean
  onSave: (agente_id: number, mes: string, campo: string, valor: number, setOverride: boolean) => void
}) {
  const [val, setVal] = useState(valor.toFixed(2))
  const [estado, setEstado] = useState<'idle' | 'guardando' | 'ok' | 'error'>('idle')

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setVal(e.target.value)
    setEstado('idle')
  }

  const handleBlur = useCallback(() => {
    const num = parseFloat(val) || 0
    if (num === valor) return
    setEstado('guardando')
    onSave(fila.agente_id, mes, campo, num, esComision)
    setTimeout(() => setEstado('ok'), 600)
  }, [val, valor, campo, fila.agente_id, mes, esComision, onSave])

  const borderColor =
    estado === 'ok' ? 'border-green-500' :
    estado === 'error' ? 'border-red-500' :
    esComision && fila.override_comisiones ? 'border-green-700/40' : 'border-cyan-700/30'

  return (
    <input
      type="number"
      step="0.01"
      min="0"
      className={`w-16 text-right text-xs rounded border px-1 py-0.5 bg-transparent focus:outline-none focus:ring-1 focus:ring-indigo-400 ${borderColor}`}
      style={{ color: esComision ? (fila.override_comisiones ? '#4ade80' : '#22d3ee') : '#f87171' }}
      value={val}
      onChange={handleChange}
      onBlur={handleBlur}
    />
  )
}

// ── Fila de la tabla ──────────────────────────────────────────────────────────

function FilaTabla({
  fila,
  mes,
  onSave,
  onReset,
}: {
  fila: FilaPlanilla
  mes: string
  onSave: (agente_id: number, mes: string, campo: string, valor: number, setOverride: boolean) => void
  onReset: (agente_id: number, mes: string) => void
}) {
  const [expandida, setExpandida] = useState(false)

  const estadoBadge =
    fila.estado === 'ACTIVO' ? <Badge variant="success">ACTIVO</Badge> :
    fila.estado === 'PERMISO_LARGO' ? <Badge variant="warning">PERMISO</Badge> :
    <Badge variant="destructive">INACTIVO</Badge>

  return (
    <>
      <tr className="border-b border-white/5 hover:bg-white/[0.02] text-xs">
        <td className="py-1 px-2 text-muted-foreground">{fila.nombres}</td>
        <td className="py-1 px-1 text-center text-muted-foreground/60">{fila.tienda_base}</td>
        <td className="py-1 px-1 text-center">{estadoBadge}</td>
        <td className="py-1 px-1 text-right font-mono text-sky-400">{fmt(fila.sueldo_base)}</td>
        <td className="py-1 px-1 text-center">
          <CeldaEditable valor={fila.dias_trabajados} campo="dias_trabajados" fila={fila} mes={mes} onSave={onSave} />
        </td>
        <td className="py-1 px-1 text-right font-mono text-blue-400">{fmt(fila.sueldo_dias_lab)}</td>
        <td className="py-1 px-1 text-right font-mono">
          <CeldaEditable valor={fila.comision_jefe} campo="comision_jefe" fila={fila} mes={mes} onSave={onSave} />
        </td>
        <td className="py-1 px-1 text-right font-mono">
          <CeldaEditable valor={fila.comision_equipo} campo="comision_equipo" fila={fila} mes={mes} esComision onSave={onSave} />
        </td>
        <td className="py-1 px-1 text-right font-mono">
          <CeldaEditable valor={fila.comision_planes} campo="comision_planes" fila={fila} mes={mes} esComision onSave={onSave} />
        </td>
        <td className="py-1 px-1 text-right font-mono">
          <CeldaEditable valor={fila.comision_online} campo="comision_online" fila={fila} mes={mes} esComision onSave={onSave} />
        </td>
        <td className="py-1 px-1 text-right font-bold text-amber-400 font-mono">{fmt(fila.total_remuneracion)}</td>
        <td className="py-1 px-1 text-right font-mono">
          <CeldaEditable valor={fila.retencion_uniforme} campo="retencion_uniforme" fila={fila} mes={mes} onSave={onSave} />
        </td>
        <td className="py-1 px-1 text-right font-mono text-red-400">{fmt(fila.faltas_permisos)}</td>
        <td className="py-1 px-1 text-right font-mono text-red-400">{fmt(fila.tardanzas)}</td>
        <td className="py-1 px-1 text-right font-mono">
          <CeldaEditable valor={fila.faltante_efectivo} campo="faltante_efectivo" fila={fila} mes={mes} onSave={onSave} />
        </td>
        <td className="py-1 px-1 text-right font-bold text-red-400 font-mono">{fmt(fila.total_descuentos)}</td>
        <td className="py-1 px-1 text-right font-bold text-cyan-400 font-mono text-sm">{fmt(fila.total_pagar)}</td>
        <td className="py-1 px-1 text-center">
          <div className="flex gap-1 justify-center">
            <button
              onClick={() => setExpandida(v => !v)}
              className="text-muted-foreground hover:text-white px-1"
              title="Ver detalle"
            >
              {expandida ? '▲' : '▼'}
            </button>
            {fila.override_comisiones && (
              <button
                onClick={() => onReset(fila.agente_id, mes)}
                className="text-amber-400 hover:text-amber-300 px-1 text-xs"
                title="Restaurar comisiones automáticas"
              >
                ↺
              </button>
            )}
          </div>
        </td>
      </tr>
      {expandida && (
        <tr className="bg-white/[0.015] border-b border-white/5">
          <td colSpan={18} className="px-4 py-2">
            <div className="flex gap-6 text-xs text-muted-foreground">
              <div>
                <span className="text-cyan-400/70 mr-1">Auto Equipos:</span>
                <span className="font-mono">{fmtSol(fila.auto_equipo)}</span>
              </div>
              <div>
                <span className="text-cyan-400/70 mr-1">Auto Planes:</span>
                <span className="font-mono">{fmtSol(fila.auto_planes)}</span>
              </div>
              <div>
                <span className="text-cyan-400/70 mr-1">Auto Online:</span>
                <span className="font-mono">{fmtSol(fila.auto_online)}</span>
              </div>
              <div>
                <span className="text-amber-400/70 mr-1">Adelantos:</span>
                <span className="font-mono">{fmtSol(fila.adelanto_incluido)}</span>
              </div>
              {fila.override_comisiones && (
                <Badge variant="warning" className="self-center">Comisiones manuales</Badge>
              )}
              {fila.notas && (
                <div className="ml-4 italic text-muted-foreground/60">{fila.notas}</div>
              )}
            </div>
          </td>
        </tr>
      )}
    </>
  )
}

// ── Página principal ──────────────────────────────────────────────────────────

export function PlanillaPage() {
  const [mes, setMes] = useState(mesActual)

  const { data, isLoading, isError } = usePlanilla(mes)
  const guardar  = useGuardarAjustePlanilla()
  const resetar  = useResetarComisionesPlanilla()

  const handleSave = useCallback(
    (agente_id: number, mesParam: string, campo: string, valor: number, setOverride: boolean) => {
      guardar.mutate({ agente_id, mes: mesParam, campo, valor, set_override: setOverride })
    },
    [guardar],
  )

  const handleReset = useCallback(
    (agente_id: number, mesParam: string) => {
      resetar.mutate({ agente_id, mes: mesParam })
    },
    [resetar],
  )

  const t = data?.totales

  return (
    <div className="space-y-4">
      <PageHeader
        title="Planilla CD08"
        subtitle={`Cálculo mensual de remuneraciones y comisiones`}
      >
        <Input
          type="month"
          value={mes}
          onChange={e => setMes(e.target.value)}
          className="w-36"
        />
      </PageHeader>

      {/* KPIs */}
      {t && (
        <div className="flex flex-wrap gap-2">
          <KpiCard label="Agentes" valor={String(data.agentes.length)} color="text-white" />
          <KpiCard label="Total Remun." valor={fmtSol(t.total_remuneracion)} color="text-amber-400" />
          <KpiCard label="Com. Planes" valor={fmtSol(t.com_planes)} color="text-cyan-400" />
          <KpiCard label="Com. Equipos" valor={fmtSol(t.com_equipo)} color="text-cyan-400" />
          <KpiCard label="Com. Online" valor={fmtSol(t.com_online)} color="text-cyan-400" />
          <KpiCard label="Descuentos" valor={fmtSol(t.total_descuentos)} color="text-red-400" />
          <KpiCard label="Adelantos" valor={fmtSol(t.adelantos)} color="text-amber-300" />
          <KpiCard label="TOTAL A PAGAR" valor={fmtSol(t.total_pagar)} color="text-green-400 text-base" />
        </div>
      )}

      {isLoading && (
        <div className="text-center py-16 text-muted-foreground">Calculando planilla...</div>
      )}
      {isError && (
        <div className="text-center py-16 text-red-400">Error al cargar la planilla.</div>
      )}

      {data && (
        <Card className="p-0 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-xs" style={{ minWidth: '1600px' }}>
              <thead>
                <tr className="border-b border-white/10 text-muted-foreground/70 text-[0.65rem] uppercase tracking-wide">
                  <th className="py-2 px-2 text-left">Agente</th>
                  <th className="py-2 px-1 text-center">Tienda</th>
                  <th className="py-2 px-1 text-center">Estado</th>
                  <th className="py-2 px-1 text-right text-sky-400">S. Base</th>
                  <th className="py-2 px-1 text-center">Días</th>
                  <th className="py-2 px-1 text-right text-blue-400">S × Días</th>
                  <th className="py-2 px-1 text-right text-green-400">Com. Jefe</th>
                  <th className="py-2 px-1 text-right text-cyan-400">Com. Equipo</th>
                  <th className="py-2 px-1 text-right text-cyan-400">Com. Planes</th>
                  <th className="py-2 px-1 text-right text-cyan-400">Com. Online</th>
                  <th className="py-2 px-1 text-right text-amber-400">Total Remun.</th>
                  <th className="py-2 px-1 text-right text-red-400">Ret. Uni.</th>
                  <th className="py-2 px-1 text-right text-red-400">Faltas</th>
                  <th className="py-2 px-1 text-right text-red-400">Tardanzas</th>
                  <th className="py-2 px-1 text-right text-red-400">Faltante</th>
                  <th className="py-2 px-1 text-right text-red-500">Total Desc.</th>
                  <th className="py-2 px-1 text-right text-cyan-300">A Pagar</th>
                  <th className="py-2 px-1 w-10"></th>
                </tr>
              </thead>
              <tbody>
                {data.agentes.map(fila => (
                  <FilaTabla
                    key={fila.agente_id}
                    fila={fila}
                    mes={mes}
                    onSave={handleSave}
                    onReset={handleReset}
                  />
                ))}
              </tbody>
              {t && (
                <tfoot>
                  <tr className="border-t-2 border-white/10 bg-white/[0.02] font-bold text-xs">
                    <td colSpan={5} className="py-2 px-2 text-muted-foreground">TOTALES</td>
                    <td className="py-2 px-1 text-right font-mono text-blue-400">{fmt(t.sueldo_dias_lab)}</td>
                    <td colSpan={4}></td>
                    <td className="py-2 px-1 text-right font-mono text-amber-400">{fmt(t.total_remuneracion)}</td>
                    <td colSpan={4}></td>
                    <td className="py-2 px-1 text-right font-mono text-red-400">{fmt(t.total_descuentos)}</td>
                    <td className="py-2 px-1 text-right font-mono text-cyan-400 text-sm">{fmt(t.total_pagar)}</td>
                    <td></td>
                  </tr>
                </tfoot>
              )}
            </table>
          </div>
        </Card>
      )}

      {data && data.agentes.length === 0 && (
        <div className="text-center py-16 text-muted-foreground">
          No hay agentes activos para el mes seleccionado.
        </div>
      )}
    </div>
  )
}
