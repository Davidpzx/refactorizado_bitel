import { useState, useCallback } from 'react'
import { format, startOfMonth } from 'date-fns'
import { ChevronDown, ChevronUp, FileText, RotateCcw } from 'lucide-react'
import { usePlanilla, useGuardarAjustePlanilla, useResetarComisionesPlanilla } from '../../hooks/usePlanilla'
import { PageHeader } from '../../components/PageHeader'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { Button } from '../../components/ui/button'
import { ActionIconButton, TableActions } from '../../components/ui/ActionIconButton'
import { Dialog } from '../../components/ui/dialog'
import { Label } from '../../components/ui/label'
import { api } from '../../services/api'
import type { FilaPlanilla } from '../../types/planilla'

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmt = (n: number) =>
  new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n)

const fmtSol = (n: number) => `S/ ${fmt(n)}`

const mesActual = format(startOfMonth(new Date()), 'yyyy-MM')

// ── KPI Card ─────────────────────────────────────────────────────────────────

function KpiCard({ label, valor, color, border }: { label: string; valor: string; color: string; border: string }) {
  return (
    <div className={`kyro-card min-w-[120px] border-l-4 px-4 py-3.5 text-center transition-all duration-200 hover:-translate-y-0.5 ${border}`}>
      <p className="text-[0.65rem] font-semibold uppercase leading-tight tracking-[0.08em] text-kyro-muted">{label}</p>
      <p className={`mt-1.5 text-sm font-bold tracking-tight ${color}`}>{valor}</p>
    </div>
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
    estado === 'ok' ? 'border-kyro-success' :
    estado === 'error' ? 'border-kyro-danger' :
    esComision && fila.override_comisiones ? 'border-kyro-success/40 text-kyro-success' :
    esComision ? 'border-kyro-info/30 text-kyro-info' : 'border-kyro-danger/40 text-kyro-danger'

  return (
    <input
      type="number"
      step="0.01"
      min="0"
      className={`w-16 rounded-kyro border bg-kyro-base px-1.5 py-1 text-right font-mono text-xs shadow-sm transition-all focus:outline-none focus:ring-2 focus:ring-kyro-indigo/30 ${borderColor}`}
      value={val}
      onChange={handleChange}
      onBlur={handleBlur}
    />
  )
}

// ── Boleta Dialog ─────────────────────────────────────────────────────────────

interface BoletaForm {
  fecha_inicio: string
  fecha_fin: string
  sueldo_base: string
  bonos: string
  dscto_tardanza: string
  dscto_adelantos: string
}

function BoletaDialog({
  fila,
  mes,
  open,
  onClose,
}: {
  fila: FilaPlanilla
  mes: string
  open: boolean
  onClose: () => void
}) {
  const mesDate = mes.length === 7 ? mes : mes.slice(0, 7)
  const [form, setForm] = useState<BoletaForm>({
    fecha_inicio:   `${mesDate}-01`,
    fecha_fin:      `${mesDate}-${new Date(parseInt(mesDate.slice(0,4)), parseInt(mesDate.slice(5,7)), 0).getDate()}`,
    sueldo_base:    fila.sueldo_base.toFixed(2),
    bonos:          (fila.comision_equipo + fila.comision_planes + fila.comision_online + fila.comision_jefe).toFixed(2),
    dscto_tardanza: fila.tardanzas.toFixed(2),
    dscto_adelantos: fila.adelanto_incluido.toFixed(2),
  })
  const [enviando, setEnviando] = useState(false)

  const setField = (field: keyof BoletaForm, val: string) =>
    setForm(prev => ({ ...prev, [field]: val }))

  const totalNeto =
    (parseFloat(form.sueldo_base) || 0) +
    (parseFloat(form.bonos) || 0) -
    (parseFloat(form.dscto_tardanza) || 0) -
    (parseFloat(form.dscto_adelantos) || 0)

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setEnviando(true)
    try {
      const resp = await api.post(
        '/v1/constancias/boleta',
        {
          agente_id:      fila.agente_id,
          fecha_inicio:   form.fecha_inicio,
          fecha_fin:      form.fecha_fin,
          sueldo_base:    parseFloat(form.sueldo_base) || 0,
          bonos:          parseFloat(form.bonos) || 0,
          dscto_tardanza: parseFloat(form.dscto_tardanza) || 0,
          dscto_adelantos: parseFloat(form.dscto_adelantos) || 0,
          total_neto:     totalNeto,
        },
        { responseType: 'blob' },
      )
      const url = window.URL.createObjectURL(new Blob([resp.data], { type: 'application/pdf' }))
      const a = document.createElement('a')
      a.href = url
      a.download = `boleta-${fila.nombres.replace(/\s+/g, '-')}-${mes}.pdf`
      document.body.appendChild(a)
      a.click()
      a.remove()
      window.URL.revokeObjectURL(url)
      onClose()
    } catch {
      // silently fail
    } finally {
      setEnviando(false)
    }
  }

  return (
    <Dialog open={open} onClose={onClose} title={`Boleta PDF — ${fila.nombres}`} maxWidth="md">
      <form onSubmit={handleSubmit} className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>Fecha inicio</Label>
            <Input
              type="date"
              value={form.fecha_inicio}
              onChange={e => setField('fecha_inicio', e.target.value)}
              required
            />
          </div>
          <div>
            <Label>Fecha fin</Label>
            <Input
              type="date"
              value={form.fecha_fin}
              onChange={e => setField('fecha_fin', e.target.value)}
              required
            />
          </div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>Sueldo base</Label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={form.sueldo_base}
              onChange={e => setField('sueldo_base', e.target.value)}
              required
            />
          </div>
          <div>
            <Label>Bonos (comisiones)</Label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={form.bonos}
              onChange={e => setField('bonos', e.target.value)}
              required
            />
          </div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>Dsct. tardanza</Label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={form.dscto_tardanza}
              onChange={e => setField('dscto_tardanza', e.target.value)}
              required
            />
          </div>
          <div>
            <Label>Dsct. adelantos</Label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={form.dscto_adelantos}
              onChange={e => setField('dscto_adelantos', e.target.value)}
              required
            />
          </div>
        </div>
        <div className="flex items-center justify-between border-t border-kyro-border pt-3">
          <span className="text-sm font-semibold text-kyro-body">
            Total neto: <span className="text-emerald-600 dark:text-emerald-400">S/ {totalNeto.toFixed(2)}</span>
          </span>
          <div className="flex gap-2">
            <Button type="button" variant="outline" onClick={onClose}>
              Cancelar
            </Button>
            <Button type="submit" variant="gold" disabled={enviando}>
              {enviando ? 'Generando...' : 'Descargar PDF'}
            </Button>
          </div>
        </div>
      </form>
    </Dialog>
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
  const [boletaOpen, setBoletaOpen] = useState(false)

  const estadoBadge =
    fila.estado === 'ACTIVO' ? <Badge variant="success">ACTIVO</Badge> :
    fila.estado === 'PERMISO_LARGO' ? <Badge variant="warning">PERMISO</Badge> :
    <Badge variant="destructive">INACTIVO</Badge>

  return (
    <>
      <tr className="border-b border-kyro-border text-xs transition-colors hover:bg-kyro-indigo/5">
        <td className="px-3 py-2 font-medium text-kyro-body">{fila.nombres}</td>
        <td className="px-1 py-2 text-center font-mono text-gray-400 dark:text-zinc-500">{fila.tienda_base}</td>
        <td className="py-1 px-1 text-center">{estadoBadge}</td>
        <td className="px-1 py-2 text-right font-mono text-sky-600 dark:text-sky-400">{fmt(fila.sueldo_base)}</td>
        <td className="py-1 px-1 text-center">
          <CeldaEditable valor={fila.dias_trabajados} campo="dias_trabajados" fila={fila} mes={mes} onSave={onSave} />
        </td>
        <td className="px-1 py-2 text-right font-mono text-blue-600 dark:text-blue-400">{fmt(fila.sueldo_dias_lab)}</td>
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
        <td className="px-1 py-2 text-right font-mono font-bold text-amber-600 dark:text-amber-400">{fmt(fila.total_remuneracion)}</td>
        <td className="py-1 px-1 text-right font-mono">
          <CeldaEditable valor={fila.retencion_uniforme} campo="retencion_uniforme" fila={fila} mes={mes} onSave={onSave} />
        </td>
        <td className="px-1 py-2 text-right font-mono text-red-600 dark:text-red-400">{fmt(fila.faltas_permisos)}</td>
        <td className="px-1 py-2 text-right font-mono text-red-600 dark:text-red-400">{fmt(fila.tardanzas)}</td>
        <td className="py-1 px-1 text-right font-mono">
          <CeldaEditable valor={fila.faltante_efectivo} campo="faltante_efectivo" fila={fila} mes={mes} onSave={onSave} />
        </td>
        <td className="px-1 py-2 text-right font-mono font-bold text-red-600 dark:text-red-400">{fmt(fila.total_descuentos)}</td>
        <td className="px-1 py-2 text-right font-mono text-sm font-bold text-cyan-600 dark:text-cyan-400">{fmt(fila.total_pagar)}</td>
        <td className="py-1 px-1 text-center">
          <TableActions className="justify-center">
            <ActionIconButton
              tone="view"
              label={expandida ? 'Ocultar detalle' : 'Ver detalle'}
              icon={expandida ? <ChevronUp size={15} /> : <ChevronDown size={15} />}
              onClick={() => setExpandida(v => !v)}
            />
            {fila.override_comisiones && (
              <Button
                type="button"
                variant="glassWarning"
                size="iconSm"
                aria-label="Restaurar comisiones automaticas"
                onClick={() => onReset(fila.agente_id, mes)}
                title="Restaurar comisiones automáticas"
              >
                <RotateCcw size={15} />
              </Button>
            )}
            <ActionIconButton
              tone="excel"
              label="Generar boleta PDF"
              icon={<FileText size={15} />}
              onClick={() => setBoletaOpen(true)}
            />
          </TableActions>
        </td>
      </tr>
      {boletaOpen && (
        <BoletaDialog
          fila={fila}
          mes={mes}
          open={boletaOpen}
          onClose={() => setBoletaOpen(false)}
        />
      )}
      {expandida && (
        <tr className="border-b border-indigo-100/70 bg-indigo-50/40 dark:border-white/[0.05] dark:bg-indigo-400/[0.025]">
          <td colSpan={18} className="px-5 py-3">
            <div className="flex gap-6 text-xs text-gray-500 dark:text-zinc-400">
              <div>
                <span className="mr-1 text-cyan-600 dark:text-cyan-400/80">Auto Equipos:</span>
                <span className="font-mono">{fmtSol(fila.auto_equipo)}</span>
              </div>
              <div>
                <span className="mr-1 text-cyan-600 dark:text-cyan-400/80">Auto Planes:</span>
                <span className="font-mono">{fmtSol(fila.auto_planes)}</span>
              </div>
              <div>
                <span className="mr-1 text-cyan-600 dark:text-cyan-400/80">Auto Online:</span>
                <span className="font-mono">{fmtSol(fila.auto_online)}</span>
              </div>
              <div>
                <span className="mr-1 text-amber-600 dark:text-amber-400/80">Adelantos:</span>
                <span className="font-mono">{fmtSol(fila.adelanto_incluido)}</span>
              </div>
              {fila.override_comisiones && (
                <Badge variant="warning" className="self-center">Comisiones manuales</Badge>
              )}
              {fila.notas && (
                <div className="ml-4 italic text-gray-400 dark:text-zinc-500">{fila.notas}</div>
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

  const [exportando, setExportando] = useState(false)
  async function exportarExcel() {
    setExportando(true)
    try {
      const resp = await api.get(`/v1/planilla/${mes}/exportar`, { responseType: 'blob' })
      const url = window.URL.createObjectURL(
        new Blob([resp.data], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }),
      )
      const a = document.createElement('a')
      a.href = url
      a.download = `planilla_${mes}.xlsx`
      a.click()
      window.URL.revokeObjectURL(url)
    } finally {
      setExportando(false)
    }
  }

  const t = data?.totales

  return (
    <div className="space-y-6">
      <PageHeader
        title="Planilla CD08"
        subtitle={`Cálculo mensual de remuneraciones y comisiones`}
      >
        <div className="flex items-center gap-2">
          <Input
            type="month"
            value={mes}
            onChange={e => setMes(e.target.value)}
            className="kyro-input w-36"
          />
          <Button type="button" variant="glassSuccess" onClick={exportarExcel} disabled={exportando}>
            <FileText size={14} className="mr-1" /> {exportando ? 'Generando…' : 'Exportar Excel'}
          </Button>
        </div>
      </PageHeader>

      {/* KPIs */}
      {t && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-8">
          <KpiCard label="Agentes" valor={String(data.agentes.length)} color="text-kyro-text" border="border-l-kpi-neutral" />
          <KpiCard label="Total Remun." valor={fmtSol(t.total_remuneracion)} color="text-kyro-gold" border="border-l-kpi-total" />
          <KpiCard label="Com. Planes" valor={fmtSol(t.com_planes)} color="text-kyro-info" border="border-l-kpi-ganancia" />
          <KpiCard label="Com. Equipos" valor={fmtSol(t.com_equipo)} color="text-kyro-info" border="border-l-kpi-ganancia" />
          <KpiCard label="Com. Online" valor={fmtSol(t.com_online)} color="text-kyro-info" border="border-l-kpi-ganancia" />
          <KpiCard label="Descuentos" valor={fmtSol(t.total_descuentos)} color="text-kyro-danger" border="border-l-kyro-danger" />
          <KpiCard label="Adelantos" valor={fmtSol(t.adelantos)} color="text-kyro-warning" border="border-l-kyro-warning" />
          <KpiCard label="TOTAL A PAGAR" valor={fmtSol(t.total_pagar)} color="text-base text-kyro-success" border="border-l-kpi-ganancia" />
        </div>
      )}

      {isLoading && (
        <div className="kyro-card py-16 text-center text-sm text-kyro-muted">Calculando planilla...</div>
      )}
      {isError && (
        <div className="rounded-kyro-lg border border-kyro-danger/30 bg-kyro-danger/10 py-16 text-center text-sm text-kyro-danger shadow-kyro-card">Error al cargar la planilla.</div>
      )}

      {data && (
        <div className="kyro-card overflow-hidden p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-xs" style={{ minWidth: '1600px' }}>
              <thead>
                <tr className="kyro-table-head">
                  <th className="py-2 px-2 text-left">Agente</th>
                  <th className="py-2 px-1 text-center">Tienda</th>
                  <th className="py-2 px-1 text-center">Estado</th>
                  <th className="px-1 py-2 text-right text-sky-600 dark:text-sky-400">S. Base</th>
                  <th className="py-2 px-1 text-center">Días</th>
                  <th className="px-1 py-2 text-right text-blue-600 dark:text-blue-400">S × Días</th>
                  <th className="px-1 py-2 text-right text-emerald-600 dark:text-emerald-400">Com. Jefe</th>
                  <th className="px-1 py-2 text-right text-cyan-600 dark:text-cyan-400">Com. Equipo</th>
                  <th className="px-1 py-2 text-right text-cyan-600 dark:text-cyan-400">Com. Planes</th>
                  <th className="px-1 py-2 text-right text-cyan-600 dark:text-cyan-400">Com. Online</th>
                  <th className="px-1 py-2 text-right text-amber-600 dark:text-amber-400">Total Remun.</th>
                  <th className="px-1 py-2 text-right text-red-600 dark:text-red-400">Ret. Uni.</th>
                  <th className="px-1 py-2 text-right text-red-600 dark:text-red-400">Faltas</th>
                  <th className="px-1 py-2 text-right text-red-600 dark:text-red-400">Tardanzas</th>
                  <th className="px-1 py-2 text-right text-red-600 dark:text-red-400">Faltante</th>
                  <th className="px-1 py-2 text-right text-red-600 dark:text-red-500">Total Desc.</th>
                  <th className="px-1 py-2 text-right text-cyan-600 dark:text-cyan-300">A Pagar</th>
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
                  <tr className="border-t-2 border-indigo-200/80 bg-indigo-50/60 text-xs font-bold dark:border-indigo-400/20 dark:bg-indigo-400/[0.05]">
                    <td colSpan={5} className="px-3 py-3 text-gray-600 dark:text-zinc-300">TOTALES</td>
                    <td className="px-1 py-3 text-right font-mono text-blue-600 dark:text-blue-400">{fmt(t.sueldo_dias_lab)}</td>
                    <td colSpan={4}></td>
                    <td className="px-1 py-3 text-right font-mono text-amber-600 dark:text-amber-400">{fmt(t.total_remuneracion)}</td>
                    <td colSpan={4}></td>
                    <td className="px-1 py-3 text-right font-mono text-red-600 dark:text-red-400">{fmt(t.total_descuentos)}</td>
                    <td className="px-1 py-3 text-right font-mono text-sm text-cyan-600 dark:text-cyan-400">{fmt(t.total_pagar)}</td>
                    <td></td>
                  </tr>
                </tfoot>
              )}
            </table>
          </div>
        </div>
      )}

      {data && data.agentes.length === 0 && (
        <div className="rounded-kyro-lg border border-dashed border-kyro-border bg-kyro-panel py-16 text-center text-kyro-muted shadow-kyro-card">
          No hay agentes activos para el mes seleccionado.
        </div>
      )}
    </div>
  )
}
