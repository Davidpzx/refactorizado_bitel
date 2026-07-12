import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../../services/api'
import { useAuth } from '../../hooks/useAuth'
import { Button } from '../../components/ui/button'
import { ActionIconButton, TableActions } from '../../components/ui/ActionIconButton'
import { KpiCard } from '../../components/ui/KpiCard'
import { ListToolbar } from '../../components/ListToolbar'
import { PageHeader } from '../../components/PageHeader'
import { apiErrorData } from '../../lib/httpError'
import { Warning as AlertTriangle, Wallet, ArrowsLeftRight as ArrowRightLeft, ArrowsClockwise as RefreshCw, CreditCard, Stack as Layers, SealCheck as CheckCircle2, XCircle, PaperPlaneTilt as Send, SlidersHorizontal, PencilSimple as Pencil, Trash as Trash2, Scales as Scale, Lock, Link as Link2, FileXls as FileSpreadsheet, BellRinging as BellRing } from '@phosphor-icons/react'
import { CuadreBitelPanel } from './CuadreBitelPage'
import { useConfirmDialog } from '../../components/ui/confirm-dialog'

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })

const BIPAY = '#38bdf8'
const ANYPAY = '#a78bfa'
const INDIGO = '#6366f1'

interface Cuenta {
  id: number
  alias: string
  numero_cuenta: string
  tipo: string
  saldo_actual: number
  saldo_bipay: number
  saldo_anypay: number
  cuenta_madre_id?: number | null
}

interface Kpis {
  total_bipay: number
  total_anypay: number
  total_saldo: number
}

interface SaldoData {
  warning?: string
  cuentas: Cuenta[]
  kpis: Kpis
}

interface BipayTransaction {
  id: number
  created_at: string
  tipo_operacion: string
  plataforma?: string | null
  origen_alias?: string | null
  destino_alias?: string | null
  monto: number
  observacion?: string | null
}

interface TransaccionesData {
  data: BipayTransaction[]
}

interface LockActivo {
  cuenta_bipay_id: number
  tienda_codigo: string
  tienda_nombre: string
  cuenta_alias: string
  expira_en: string
  cooldown_segs: number
}

const TIPOS_OPERACION = ['RECARGA', 'TRANSFERENCIA', 'AJUSTE', 'DECLARACION_DIA', 'CIERRE_DIA'] as const

export function PanelBipayPage() {
  const qc = useQueryClient()
  const { usuario } = useAuth()
  const esAdmin = usuario?.rol === 'admin'
  const [tab, setTab] = useState<'saldo' | 'transacciones' | 'recarga' | 'transferir' | 'ajustar' | 'cuentas' | 'locks' | 'cuadre'>('saldo')
  // "Alertas Webhook" (legacy: botón rojo en cabecera) — salta al panel de Cuadre Bitel, sub-tab Auditoría, con el form de webhook abierto.
  const [webhookSignal, setWebhookSignal] = useState(0)

  // Saldo query
  const { data: saldoData, isLoading: loadingSaldo } = useQuery({
    queryKey: ['bipay-saldo'],
    queryFn: () => api.get<SaldoData>('/v1/bipay/saldo').then(r => r.data),
  })

  // Transacciones
  const [txFilters, setTxFilters] = useState({
    fecha_desde: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10),
    fecha_hasta: new Date().toISOString().slice(0, 10),
    cuenta_id: '',
    tipo_operacion: '',
  })
  const [txApplied, setTxApplied] = useState({ ...txFilters })
  const [exportando, setExportando] = useState(false)

  const { data: txData, isLoading: loadingTx } = useQuery<TransaccionesData>({
    queryKey: ['bipay-transacciones', txApplied],
    queryFn: () => api.get<TransaccionesData>('/v1/bipay/transacciones', { params: txApplied }).then(r => r.data),
    enabled: tab === 'transacciones',
  })

  async function exportarTransaccionesExcel() {
    setExportando(true)
    try {
      const params = new URLSearchParams()
      Object.entries(txApplied).forEach(([k, v]) => { if (v) params.set(k, v) })
      const token = localStorage.getItem('auth_token')
      const base = (api.defaults.baseURL ?? '').replace(/\/$/, '')
      const url = `${base}/v1/bipay/transacciones/exportar?${params.toString()}`
      const res = await fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      const blob = await res.blob()
      const burl = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = burl
      link.download = `bipay_historial_${new Date().toISOString().slice(0, 10)}.xlsx`
      link.click()
      URL.revokeObjectURL(burl)
    } finally {
      setExportando(false)
    }
  }

  // Locks activos (admin)
  const { data: locksData, isLoading: loadingLocks } = useQuery<{ locks: LockActivo[] }>({
    queryKey: ['bipay-locks-activos'],
    queryFn: () => api.get<{ locks: LockActivo[] }>('/v1/bipay/locks-activos').then(r => r.data),
    enabled: tab === 'locks' && esAdmin,
  })

  // Vincular cuenta huérfana → convertir a MADRE
  const [vincularId, setVincularId] = useState<number | null>(null)
  const [vincularForm, setVincularForm] = useState({ razon_social: '', alias: '' })
  const [vincularMsg, setVincularMsg] = useState(''); const [vincularErr, setVincularErr] = useState('')
  const vincularHuerfana = useMutation({
    mutationFn: (p: { razon_social: string; alias?: string }) =>
      api.post(`/v1/bipay/cuentas/${vincularId}/vincular-huerfana`, p).then(r => r.data),
    onSuccess: (res: { message?: string }) => {
      setVincularMsg(res.message ?? 'Cuenta vinculada.'); setVincularErr('')
      setVincularId(null); setVincularForm({ razon_social: '', alias: '' })
      qc.invalidateQueries({ queryKey: ['bipay-saldo'] })
    },
    onError: (error: unknown) => setVincularErr(apiErrorData(error).message ?? 'Error al vincular la cuenta.'),
  })

  // Recarga form
  const [recargaForm, setRecargaForm] = useState({
    cuenta_id: '',
    monto_bipay: '',
    monto_anypay: '',
    referencia: '',
  })
  const [recargaMsg, setRecargaMsg] = useState('')
  const [recargaErr, setRecargaErr] = useState('')

  const recarga = useMutation({
    mutationFn: (payload: Record<string, unknown>) => api.post('/v1/bipay/recarga', payload).then(r => r.data),
    onSuccess: (res) => {
      setRecargaMsg(res.message ?? 'Recarga registrada.')
      setRecargaErr('')
      setRecargaForm(f => ({ ...f, monto_bipay: '', monto_anypay: '', referencia: '' }))
      qc.invalidateQueries({ queryKey: ['bipay-saldo'] })
    },
    onError: (error: unknown) => setRecargaErr(apiErrorData(error).error ?? 'Error al registrar recarga.'),
  })

  // D3 — Transferencia entre cuentas (admin)
  const [transForm, setTransForm] = useState({ cuenta_origen_id: '', cuenta_destino_id: '', monto: '', observacion: '' })
  const [transMsg, setTransMsg] = useState(''); const [transErr, setTransErr] = useState('')
  const transferir = useMutation({
    mutationFn: (p: Record<string, unknown>) => api.post('/v1/bipay/transferir', p).then(r => r.data),
    onSuccess: (res) => { setTransMsg(res.message ?? 'Transferencia realizada.'); setTransErr(''); setTransForm(f => ({ ...f, monto: '', observacion: '' })); qc.invalidateQueries({ queryKey: ['bipay-saldo'] }) },
    onError: (error: unknown) => {
      const data = apiErrorData(error)
      setTransErr(data.message ?? data.error ?? 'Error al transferir.')
    },
  })

  // D3 — Ajuste manual de saldo (admin)
  const [ajusteForm, setAjusteForm] = useState({ cuenta_id: '', saldo_bipay: '', saldo_anypay: '', motivo: '' })
  const [ajusteMsg, setAjusteMsg] = useState(''); const [ajusteErr, setAjusteErr] = useState('')
  const ajustar = useMutation({
    mutationFn: (p: Record<string, unknown>) => api.post('/v1/bipay/ajustar', p).then(r => r.data),
    onSuccess: (res) => { setAjusteMsg(res.message ?? 'Saldo ajustado.'); setAjusteErr(''); setAjusteForm(f => ({ ...f, motivo: '' })); qc.invalidateQueries({ queryKey: ['bipay-saldo'] }) },
    onError: (error: unknown) => {
      const data = apiErrorData(error)
      setAjusteErr(data.message ?? data.error ?? 'Error al ajustar.')
    },
  })

  // ── Gestión de cuentas (CRUD) — paridad legacy panel_bipay nueva/editar/eliminar ──
  const cuentaVacia = { id: 0, alias: '', tipo: 'MADRE', numero_cuenta: '', nombre_titular: '', cuenta_madre_id: '', saldo_bipay: '', saldo_anypay: '', umbral_alerta: '' }
  const [cuentaForm, setCuentaForm] = useState({ ...cuentaVacia })
  const [cuentaMsg, setCuentaMsg] = useState(''); const [cuentaErr, setCuentaErr] = useState('')
  const editandoCuenta = cuentaForm.id > 0

  const resetCuenta = () => { setCuentaForm({ ...cuentaVacia }); setCuentaMsg(''); setCuentaErr('') }

  const guardarCuenta = useMutation({
    mutationFn: (p: Record<string, unknown>) =>
      (editandoCuenta
        ? api.put(`/v1/bipay/cuentas/${cuentaForm.id}`, p)
        : api.post('/v1/bipay/cuentas', p)).then(r => r.data),
    onSuccess: (res: { message?: string }) => {
      setCuentaMsg(res.message ?? 'Cuenta guardada.'); setCuentaErr(''); resetCuenta()
      qc.invalidateQueries({ queryKey: ['bipay-saldo'] })
    },
    onError: (error: unknown) => setCuentaErr(apiErrorData(error).message ?? 'Error al guardar la cuenta.'),
  })

  const eliminarCuenta = useMutation({
    mutationFn: (id: number) => api.delete(`/v1/bipay/cuentas/${id}`).then(r => r.data),
    onSuccess: (res: { message?: string }) => { setCuentaMsg(res.message ?? 'Cuenta eliminada.'); qc.invalidateQueries({ queryKey: ['bipay-saldo'] }) },
    onError: (error: unknown) => setCuentaErr(apiErrorData(error).message ?? 'Error al eliminar la cuenta.'),
  })

  const confirmDialog = useConfirmDialog()

  const warning = saldoData?.warning
  const huerfanas = (saldoData?.cuentas ?? []).filter(c => c.tipo === 'HIJO' && !c.cuenta_madre_id)

  type TabDef = { id: typeof tab; label: string; Icon: typeof Wallet }
  const TABS: TabDef[] = [
    { id: 'saldo',         label: 'Saldos',        Icon: Wallet },
    { id: 'transacciones', label: 'Transacciones',  Icon: ArrowRightLeft },
    { id: 'recarga',       label: 'Nueva Recarga',  Icon: RefreshCw },
    { id: 'transferir',    label: 'Transferir',     Icon: Send },
    { id: 'ajustar',       label: 'Ajustar Saldo',  Icon: SlidersHorizontal },
    { id: 'cuentas',       label: 'Cuentas',        Icon: CreditCard },
    ...(esAdmin ? [{ id: 'locks', label: 'Locks Activos', Icon: Lock }] as TabDef[] : []),
    { id: 'cuadre',        label: 'Cuadre Bitel',   Icon: Scale },
  ]

  const inputCls = 'kyro-input'

  return (
    <div className="space-y-6">
      {/* Header ─────────────────────────────────────────────────────────────── */}
      <PageHeader
        title="Panel Bipay / Anypay"
        subtitle="Saldos consolidados, transacciones y recargas"
        Icon={Wallet}
        actions={
          <>
            <Button variant="glassDanger" size="sm" className="gap-1.5" onClick={() => { setTab('cuadre'); setWebhookSignal(s => s + 1) }}>
              <BellRing size={14} /> Alertas Webhook
            </Button>
            <Button variant="glassIndigo" size="sm" className="gap-1.5" onClick={() => setTab('cuentas')}>
              <CreditCard size={14} /> Nueva Cuenta
            </Button>
            <Button variant="glassSuccess" size="sm" className="gap-1.5" onClick={() => setTab('recarga')}>
              <RefreshCw size={14} /> Recargar Cuenta
            </Button>
          </>
        }
      />

      {/* Aviso ──────────────────────────────────────────────────────────────── */}
      {warning && (
        <div className="flex items-center gap-3 rounded-kyro-lg border border-kyro-warning/30 bg-kyro-warning/10 p-4 text-sm text-kyro-warning shadow-kyro-card">
          <AlertTriangle size={18} /> {warning}
        </div>
      )}

      {/* KPIs ────────────────────────────────────────────────────────────────── */}
      {saldoData?.kpis && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <KpiCard
            title="Saldo Global"
            value={saldoData.kpis.total_saldo}
            monetary
            tone="gold"
            icon={<Wallet size={20} />}
            subtitle="Saldo consolidado"
          />
          <KpiCard
            title="Total Bipay"
            value={saldoData.kpis.total_bipay}
            monetary
            tone="info"
            accent={BIPAY}
            icon={<CreditCard size={20} />}
          />
          <KpiCard
            title="Total Anypay"
            value={saldoData.kpis.total_anypay}
            monetary
            accent={ANYPAY}
            icon={<Layers size={20} />}
          />
        </div>
      )}

      {/* Tabs ─────────────────────────────────────────────────────────────────── */}
      <div className="kyro-card rounded-[18px] flex w-fit gap-1 p-1">
        {TABS.map(t => (
          <Button key={t.id} variant="ghost" size="sm" onClick={() => { setWebhookSignal(0); setTab(t.id) }}
            className={`flex items-center gap-1.5 rounded-md px-4 py-1.5 text-sm font-medium transition-colors ${
              tab === t.id
                ? 'bg-kyro-elevated text-kyro-text shadow-sm'
                : 'text-kyro-muted hover:bg-kyro-elevated hover:text-kyro-text'
            }`}
          >
            <t.Icon size={13} /> {t.label}
          </Button>
        ))}
      </div>

      {/* TAB: Cuadre Bitel ERP (conciliación declarado vs scrapeado) ───────────── */}
      {tab === 'cuadre' && (
        <CuadreBitelPanel
          key={webhookSignal}
          initialTab={webhookSignal > 0 ? 'auditoria' : undefined}
          openWebhook={webhookSignal > 0}
        />
      )}

      {/* TAB: Saldo por cuenta ─────────────────────────────────────────────────── */}
      {tab === 'saldo' && (
        <div className="kyro-card rounded-[18px] overflow-hidden">
          <div
            className="flex items-center gap-2 border-b px-4 py-3"
            style={{ borderColor: `color-mix(in srgb, ${BIPAY} 30%, transparent)`, background: `color-mix(in srgb, ${BIPAY} 9%, transparent)` }}
          >
            <CreditCard size={15} style={{ color: BIPAY }} />
            <h2 className="text-sm font-bold" style={{ color: BIPAY }}>Cuentas Bipay / Anypay</h2>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="kyro-table-head">
                  {['Alias', 'N° Cuenta', 'Tipo', 'Saldo Bipay', 'Saldo Anypay', 'Saldo Total'].map(h => (
                    <th key={h} className="px-4 py-3 text-left">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {loadingSaldo && <tr><td colSpan={6} className="px-4 py-10 text-center text-gray-400 dark:text-zinc-500">Cargando…</td></tr>}
                {!loadingSaldo && (saldoData?.cuentas ?? []).length === 0 && (
                  <tr><td colSpan={6} className="px-4 py-12 text-center text-gray-400 dark:text-zinc-500">
                    <CreditCard size={26} className="mx-auto mb-2 opacity-40" />
                    Sin cuentas registradas
                  </td></tr>
                )}
                {(saldoData?.cuentas ?? []).map(c => (
                  <tr key={c.id} className="border-t border-kyro-border transition-colors hover:bg-kpi-bipay/5">
                    <td className="px-4 py-3 font-medium text-kyro-text">{c.alias}</td>
                    <td className="px-4 py-3 font-mono text-xs text-kyro-muted">{c.numero_cuenta}</td>
                    <td className="px-4 py-3 text-xs uppercase text-kyro-muted">{c.tipo}</td>
                    <td className="px-4 py-3 font-mono tabular-nums" style={{ color: BIPAY }}>{pen.format(c.saldo_bipay)}</td>
                    <td className="px-4 py-3 font-mono tabular-nums" style={{ color: ANYPAY }}>{pen.format(c.saldo_anypay)}</td>
                    <td className={`px-4 py-3 font-mono font-bold tabular-nums ${c.saldo_actual < 0 ? 'text-kyro-danger' : 'text-kyro-text'}`}>{pen.format(c.saldo_actual)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* TAB: Transacciones ────────────────────────────────────────────────────── */}
      {tab === 'transacciones' && (
        <div className="space-y-4">
          <ListToolbar title="Filtros" description="Acota el historial de transacciones por período.">
            <div>
              <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Desde</label>
              <input type="date" value={txFilters.fecha_desde}
                onChange={e => setTxFilters(f => ({ ...f, fecha_desde: e.target.value }))}
                className={inputCls}
              />
            </div>
            <div>
              <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Hasta</label>
              <input type="date" value={txFilters.fecha_hasta}
                onChange={e => setTxFilters(f => ({ ...f, fecha_hasta: e.target.value }))}
                className={inputCls}
              />
            </div>
            <div>
              <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Cuenta</label>
              <select value={txFilters.cuenta_id} onChange={e => setTxFilters(f => ({ ...f, cuenta_id: e.target.value }))} className={inputCls}>
                <option value="">Todas</option>
                {(saldoData?.cuentas ?? []).map(c => <option key={c.id} value={c.id}>[{c.tipo}] {c.alias}</option>)}
              </select>
            </div>
            <div>
              <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Tipo</label>
              <select value={txFilters.tipo_operacion} onChange={e => setTxFilters(f => ({ ...f, tipo_operacion: e.target.value }))} className={inputCls}>
                <option value="">Todos</option>
                {TIPOS_OPERACION.map(t => <option key={t} value={t}>{t}</option>)}
              </select>
            </div>
            <Button onClick={() => setTxApplied({ ...txFilters })}>Buscar</Button>
            {esAdmin && (
              <Button variant="outline" onClick={exportarTransaccionesExcel} disabled={exportando} className="gap-2">
                <FileSpreadsheet size={14} /> {exportando ? 'Exportando…' : 'Exportar Excel'}
              </Button>
            )}
          </ListToolbar>

          <div className="kyro-card rounded-[18px] overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="kyro-table-head">
                    {['Fecha', 'Tipo', 'Plataforma', 'Origen', 'Destino', 'Monto', 'Observación'].map(h => (
                      <th key={h} className="px-4 py-3 text-left">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {loadingTx && <tr><td colSpan={7} className="px-4 py-10 text-center text-gray-400 dark:text-zinc-500">Cargando…</td></tr>}
                  {!loadingTx && (txData?.data ?? []).length === 0 && (
                    <tr><td colSpan={7} className="px-4 py-12 text-center text-gray-400 dark:text-zinc-500">
                      <ArrowRightLeft size={26} className="mx-auto mb-2 opacity-40" />
                      Sin transacciones en el período
                    </td></tr>
                  )}
                  {(txData?.data ?? []).map(tx => (
                    <tr key={tx.id} className="border-t border-kyro-border transition-colors hover:bg-kpi-yape/5">
                      <td className="px-4 py-3 text-xs tabular-nums text-gray-600 dark:text-zinc-400">{new Date(tx.created_at).toLocaleDateString('es-PE')}</td>
                      <td className="px-4 py-3">
                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${tx.tipo_operacion === 'RECARGA' ? 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-400' : 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400'}`}>
                          {tx.tipo_operacion}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-xs text-gray-600 dark:text-zinc-400">{tx.plataforma ?? '—'}</td>
                      <td className="px-4 py-3 text-xs text-gray-600 dark:text-zinc-400">{tx.origen_alias ?? '—'}</td>
                      <td className="px-4 py-3 text-xs text-gray-600 dark:text-zinc-400">{tx.destino_alias ?? '—'}</td>
                      <td className="px-4 py-3 font-mono font-bold tabular-nums text-kyro-text">{pen.format(tx.monto)}</td>
                      <td className="max-w-xs truncate px-4 py-3 text-xs text-gray-500 dark:text-zinc-400">{tx.observacion ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* TAB: Nueva Recarga ────────────────────────────────────────────────────── */}
      {tab === 'recarga' && (
        <div className="kyro-card max-w-md rounded-[18px] border-t-2 border-t-kpi-transfer p-5">
          <h2 className="mb-4 flex items-center gap-2 text-sm font-bold text-kyro-text">
            <RefreshCw size={15} className="text-kpi-transfer" /> Registrar Recarga de Saldo
          </h2>
          {recargaMsg && (
            <div className="mb-4 flex items-center gap-2 rounded-kyro border border-kyro-success/30 bg-kyro-success/10 p-3 text-sm text-kyro-success">
              <CheckCircle2 size={15} /> {recargaMsg}
            </div>
          )}
          {recargaErr && (
            <div className="mb-4 flex items-center gap-2 rounded-kyro border border-kyro-danger/30 bg-kyro-danger/10 p-3 text-sm text-kyro-danger">
              <XCircle size={15} /> {recargaErr}
            </div>
          )}
          <div className="space-y-3">
            <div>
              <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Cuenta</label>
              <select value={recargaForm.cuenta_id} onChange={e => setRecargaForm(f => ({ ...f, cuenta_id: e.target.value }))}
                className={inputCls}>
                <option value="">-- Seleccionar cuenta --</option>
                {(saldoData?.cuentas ?? []).map(c => (
                  <option key={c.id} value={c.id}>{c.alias} ({c.numero_cuenta})</option>
                ))}
              </select>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Monto Bipay (S/)</label>
                <input type="number" min="0" step="0.01" value={recargaForm.monto_bipay}
                  onChange={e => setRecargaForm(f => ({ ...f, monto_bipay: e.target.value }))}
                  className={`${inputCls} tabular-nums`}
                />
              </div>
              <div>
                <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Monto Anypay (S/)</label>
                <input type="number" min="0" step="0.01" value={recargaForm.monto_anypay}
                  onChange={e => setRecargaForm(f => ({ ...f, monto_anypay: e.target.value }))}
                  className={`${inputCls} tabular-nums`}
                />
              </div>
            </div>
            <div>
              <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Referencia / Observación</label>
              <input type="text" value={recargaForm.referencia}
                onChange={e => setRecargaForm(f => ({ ...f, referencia: e.target.value }))}
                placeholder="N° de operación, etc."
                className={inputCls}
              />
            </div>
            <Button
              variant="gold"
              disabled={recarga.isPending || !recargaForm.cuenta_id}
              onClick={() => recarga.mutate({
                cuenta_id:    Number(recargaForm.cuenta_id),
                monto_bipay:  Number(recargaForm.monto_bipay || 0),
                monto_anypay: Number(recargaForm.monto_anypay || 0),
                referencia:   recargaForm.referencia || undefined,
              })}
            >
              {recarga.isPending ? 'Registrando…' : 'Registrar Recarga'}
            </Button>
          </div>
        </div>
      )}

      {/* TAB: Transferir ───────────────────────────────────────────────────────── */}
      {tab === 'transferir' && (
        <div className="kyro-card max-w-md rounded-[18px] border-t-2 border-t-kpi-bipay p-5">
          <h2 className="mb-4 flex items-center gap-2 text-sm font-bold text-kyro-text">
            <Send size={15} style={{ color: BIPAY }} /> Transferir Saldo entre Cuentas
          </h2>
          {transMsg && (<div className="mb-4 flex items-center gap-2 rounded-kyro border border-kyro-success/30 bg-kyro-success/10 p-3 text-sm text-kyro-success"><CheckCircle2 size={15} /> {transMsg}</div>)}
          {transErr && (<div className="mb-4 flex items-center gap-2 rounded-kyro border border-kyro-danger/30 bg-kyro-danger/10 p-3 text-sm text-kyro-danger"><XCircle size={15} /> {transErr}</div>)}
          <div className="space-y-3">
            <div>
              <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Cuenta origen</label>
              <select value={transForm.cuenta_origen_id} onChange={e => setTransForm(f => ({ ...f, cuenta_origen_id: e.target.value }))} className={inputCls}>
                <option value="">-- Origen --</option>
                {(saldoData?.cuentas ?? []).map(c => <option key={c.id} value={c.id}>{c.alias} ({pen.format(c.saldo_actual)})</option>)}
              </select>
            </div>
            <div>
              <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Cuenta destino</label>
              <select value={transForm.cuenta_destino_id} onChange={e => setTransForm(f => ({ ...f, cuenta_destino_id: e.target.value }))} className={inputCls}>
                <option value="">-- Destino --</option>
                {(saldoData?.cuentas ?? []).filter(c => String(c.id) !== transForm.cuenta_origen_id).map(c => <option key={c.id} value={c.id}>{c.alias}</option>)}
              </select>
            </div>
            <div>
              <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Monto (S/)</label>
              <input type="number" min="0" step="0.01" value={transForm.monto} onChange={e => setTransForm(f => ({ ...f, monto: e.target.value }))} className={`${inputCls} tabular-nums`} />
            </div>
            <div>
              <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Observación</label>
              <input type="text" value={transForm.observacion} onChange={e => setTransForm(f => ({ ...f, observacion: e.target.value }))} className={inputCls} />
            </div>
            <Button variant="gold" disabled={transferir.isPending || !transForm.cuenta_origen_id || !transForm.cuenta_destino_id || !transForm.monto}
              onClick={() => transferir.mutate({ cuenta_origen_id: Number(transForm.cuenta_origen_id), cuenta_destino_id: Number(transForm.cuenta_destino_id), monto: Number(transForm.monto), observacion: transForm.observacion || undefined })}>
              {transferir.isPending ? 'Transfiriendo…' : 'Transferir'}
            </Button>
          </div>
        </div>
      )}

      {/* TAB: Ajustar Saldo ────────────────────────────────────────────────────── */}
      {tab === 'ajustar' && (
        <div className="kyro-card max-w-md rounded-[18px] border-t-2 border-t-kyro-indigo p-5">
          <h2 className="mb-4 flex items-center gap-2 text-sm font-bold text-kyro-text">
            <SlidersHorizontal size={15} style={{ color: INDIGO }} /> Ajuste Manual de Saldo
          </h2>
          {ajusteMsg && (<div className="mb-4 flex items-center gap-2 rounded-kyro border border-kyro-success/30 bg-kyro-success/10 p-3 text-sm text-kyro-success"><CheckCircle2 size={15} /> {ajusteMsg}</div>)}
          {ajusteErr && (<div className="mb-4 flex items-center gap-2 rounded-kyro border border-kyro-danger/30 bg-kyro-danger/10 p-3 text-sm text-kyro-danger"><XCircle size={15} /> {ajusteErr}</div>)}
          <div className="space-y-3">
            <div>
              <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Cuenta</label>
              <select value={ajusteForm.cuenta_id} onChange={e => setAjusteForm(f => ({ ...f, cuenta_id: e.target.value }))} className={inputCls}>
                <option value="">-- Seleccionar --</option>
                {(saldoData?.cuentas ?? []).map(c => <option key={c.id} value={c.id}>{c.alias} ({pen.format(c.saldo_actual)})</option>)}
              </select>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Nuevo Bipay (S/)</label>
                <input type="number" min="0" step="0.01" value={ajusteForm.saldo_bipay} onChange={e => setAjusteForm(f => ({ ...f, saldo_bipay: e.target.value }))} className={`${inputCls} tabular-nums`} />
              </div>
              <div>
                <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Nuevo Anypay (S/)</label>
                <input type="number" min="0" step="0.01" value={ajusteForm.saldo_anypay} onChange={e => setAjusteForm(f => ({ ...f, saldo_anypay: e.target.value }))} className={`${inputCls} tabular-nums`} />
              </div>
            </div>
            <div>
              <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Motivo del ajuste (obligatorio)</label>
              <input type="text" value={ajusteForm.motivo} onChange={e => setAjusteForm(f => ({ ...f, motivo: e.target.value }))} placeholder="Conteo físico, corrección, etc." className={inputCls} />
            </div>
            <Button disabled={ajustar.isPending || !ajusteForm.cuenta_id || ajusteForm.motivo.trim().length < 5}
              onClick={() => ajustar.mutate({ cuenta_id: Number(ajusteForm.cuenta_id), saldo_bipay: Number(ajusteForm.saldo_bipay || 0), saldo_anypay: Number(ajusteForm.saldo_anypay || 0), motivo: ajusteForm.motivo })}>
              {ajustar.isPending ? 'Ajustando…' : 'Aplicar Ajuste'}
            </Button>
          </div>
        </div>
      )}

      {/* TAB: Cuentas (CRUD) ───────────────────────────────────────────────────── */}
      {tab === 'cuentas' && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
          {/* Lista */}
          <div className="kyro-card rounded-[18px] overflow-hidden lg:col-span-2">
            <div
              className="flex items-center gap-2 border-b px-4 py-3"
              style={{ borderColor: `color-mix(in srgb, ${BIPAY} 30%, transparent)`, background: `color-mix(in srgb, ${BIPAY} 9%, transparent)` }}
            >
              <CreditCard size={15} style={{ color: BIPAY }} />
              <h2 className="text-sm font-bold" style={{ color: BIPAY }}>Cuentas registradas</h2>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="kyro-table-head">
                    {['Alias', 'N° Cuenta', 'Tipo', 'Saldo', 'Acciones'].map(h => <th key={h} className="px-4 py-3 text-left">{h}</th>)}
                  </tr>
                </thead>
                <tbody>
                  {(saldoData?.cuentas ?? []).length === 0 && (
                    <tr><td colSpan={5} className="px-4 py-12 text-center text-gray-400 dark:text-zinc-500">
                      <CreditCard size={26} className="mx-auto mb-2 opacity-40" />
                      Sin cuentas registradas
                    </td></tr>
                  )}
                  {(saldoData?.cuentas ?? []).map(c => (
                    <tr key={c.id} className="border-t border-kyro-border transition-colors hover:bg-kpi-bipay/5">
                      <td className="px-4 py-3 font-medium text-kyro-text">{c.alias}</td>
                      <td className="px-4 py-3 font-mono text-xs text-kyro-muted">{c.numero_cuenta}</td>
                      <td className="px-4 py-3 text-xs uppercase text-kyro-muted">{c.tipo}</td>
                      <td className="px-4 py-3 font-mono tabular-nums text-kyro-text">{pen.format(c.saldo_actual)}</td>
                      <td className="px-4 py-3">
                        <TableActions>
                          <ActionIconButton
                            tone="edit"
                            label="Editar cuenta"
                            icon={<Pencil size={15} />}
                            onClick={() => { setCuentaErr(''); setCuentaMsg(''); setCuentaForm({ id: c.id, alias: c.alias, tipo: c.tipo, numero_cuenta: c.numero_cuenta, nombre_titular: '', cuenta_madre_id: '', saldo_bipay: '', saldo_anypay: '', umbral_alerta: '' }) }}
                          />
                          <ActionIconButton
                            tone="delete"
                            label="Eliminar cuenta"
                            icon={<Trash2 size={15} />}
                            disabled={eliminarCuenta.isPending}
                            onClick={async () => { if (await confirmDialog({ title: `¿Eliminar la cuenta "${c.alias}"?`, intent: 'danger', icon: Trash2, confirmLabel: 'Eliminar' })) eliminarCuenta.mutate(c.id) }}
                          />
                        </TableActions>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* Formulario */}
          <div className="kyro-card rounded-[18px] border-t-2 border-t-kpi-bipay p-5">
            <h2 className="mb-4 flex items-center gap-2 text-sm font-bold text-kyro-text">
              <CreditCard size={15} style={{ color: BIPAY }} /> {editandoCuenta ? 'Editar cuenta' : 'Nueva cuenta'}
            </h2>
            {cuentaMsg && (<div className="mb-3 flex items-center gap-2 rounded-kyro border border-kyro-success/30 bg-kyro-success/10 p-3 text-sm text-kyro-success"><CheckCircle2 size={15} /> {cuentaMsg}</div>)}
            {cuentaErr && (<div className="mb-3 flex items-center gap-2 rounded-kyro border border-kyro-danger/30 bg-kyro-danger/10 p-3 text-sm text-kyro-danger"><XCircle size={15} /> {cuentaErr}</div>)}
            <div className="space-y-3">
              <div>
                <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Alias *</label>
                <input type="text" value={cuentaForm.alias} onChange={e => setCuentaForm(f => ({ ...f, alias: e.target.value }))} className={inputCls} />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Tipo *</label>
                  <select value={cuentaForm.tipo} onChange={e => setCuentaForm(f => ({ ...f, tipo: e.target.value }))} className={inputCls}>
                    <option value="MADRE">MADRE</option>
                    <option value="HIJO">HIJO</option>
                  </select>
                </div>
                <div>
                  <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">N° Cuenta *</label>
                  <input type="text" value={cuentaForm.numero_cuenta} onChange={e => setCuentaForm(f => ({ ...f, numero_cuenta: e.target.value }))} className={inputCls} />
                </div>
              </div>
              {cuentaForm.tipo === 'HIJO' && (
                <div>
                  <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Cuenta madre *</label>
                  <select value={cuentaForm.cuenta_madre_id} onChange={e => setCuentaForm(f => ({ ...f, cuenta_madre_id: e.target.value }))} className={inputCls}>
                    <option value="">-- Seleccionar --</option>
                    {(saldoData?.cuentas ?? []).filter(c => c.tipo === 'MADRE').map(c => <option key={c.id} value={c.id}>{c.alias}</option>)}
                  </select>
                </div>
              )}
              <div>
                <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Titular</label>
                <input type="text" value={cuentaForm.nombre_titular} onChange={e => setCuentaForm(f => ({ ...f, nombre_titular: e.target.value }))} className={inputCls} />
              </div>
              {!editandoCuenta && (
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Saldo Bipay inicial</label>
                    <input type="number" min="0" step="0.01" value={cuentaForm.saldo_bipay} onChange={e => setCuentaForm(f => ({ ...f, saldo_bipay: e.target.value }))} className={`${inputCls} tabular-nums`} />
                  </div>
                  <div>
                    <label className="mb-1 block text-xs text-gray-500 dark:text-zinc-400">Saldo Anypay inicial</label>
                    <input type="number" min="0" step="0.01" value={cuentaForm.saldo_anypay} onChange={e => setCuentaForm(f => ({ ...f, saldo_anypay: e.target.value }))} className={`${inputCls} tabular-nums`} />
                  </div>
                </div>
              )}
              <div className="flex gap-2 pt-1">
                <Button className="flex-1"
                  disabled={guardarCuenta.isPending || !cuentaForm.alias || !cuentaForm.numero_cuenta || (cuentaForm.tipo === 'HIJO' && !cuentaForm.cuenta_madre_id)}
                  onClick={() => { setCuentaMsg(''); setCuentaErr(''); guardarCuenta.mutate({
                    alias: cuentaForm.alias, tipo: cuentaForm.tipo, numero_cuenta: cuentaForm.numero_cuenta,
                    nombre_titular: cuentaForm.nombre_titular || undefined,
                    cuenta_madre_id: cuentaForm.tipo === 'HIJO' ? Number(cuentaForm.cuenta_madre_id) : undefined,
                    saldo_bipay: Number(cuentaForm.saldo_bipay || 0), saldo_anypay: Number(cuentaForm.saldo_anypay || 0),
                  }) }}>
                  {guardarCuenta.isPending ? 'Guardando…' : editandoCuenta ? 'Actualizar' : 'Crear cuenta'}
                </Button>
                {editandoCuenta && <Button variant="outline" onClick={resetCuenta}>Cancelar</Button>}
              </div>
            </div>
          </div>

          {/* Cuentas Huérfanas (HIJO sin cuenta_madre_id) — pendientes de vincular a MADRE */}
          {huerfanas.length > 0 && (
            <div className="kyro-card rounded-[18px] border-t-2 border-t-kyro-muted p-5 lg:col-span-3">
              <div className="mb-3 flex items-center gap-2">
                <AlertTriangle size={16} className="text-kyro-muted" />
                <div>
                  <h2 className="text-sm font-bold text-kyro-text">Cuentas Huérfanas (Pendientes de Vincular)</h2>
                  <p className="text-xs text-kyro-muted">Cuentas auto-registradas sin una cuenta MADRE asignada.</p>
                </div>
              </div>
              {vincularMsg && (<div className="mb-3 flex items-center gap-2 rounded-kyro border border-kyro-success/30 bg-kyro-success/10 p-3 text-sm text-kyro-success"><CheckCircle2 size={15} /> {vincularMsg}</div>)}
              {vincularErr && (<div className="mb-3 flex items-center gap-2 rounded-kyro border border-kyro-danger/30 bg-kyro-danger/10 p-3 text-sm text-kyro-danger"><XCircle size={15} /> {vincularErr}</div>)}
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {huerfanas.map(h => (
                  <div key={h.id} className="rounded-kyro border border-dashed border-kyro-border p-3">
                    <div className="flex items-center gap-2">
                      <span className="rounded-full bg-kyro-muted/10 px-2 py-0.5 text-[0.65rem] font-semibold uppercase text-kyro-muted">Huérfana</span>
                      <span className="text-sm font-medium text-kyro-text">{h.alias}</span>
                    </div>
                    <div className="mt-1 text-xs text-kyro-muted">{h.numero_cuenta}</div>
                    <div className="mt-1 font-mono text-sm text-kyro-text">{pen.format(h.saldo_actual)}</div>
                    {vincularId === h.id ? (
                      <div className="mt-2 space-y-2">
                        <input type="text" placeholder="Razón social *" value={vincularForm.razon_social}
                          onChange={e => setVincularForm(f => ({ ...f, razon_social: e.target.value }))} className={inputCls} />
                        <input type="text" placeholder="Alias (opcional)" value={vincularForm.alias}
                          onChange={e => setVincularForm(f => ({ ...f, alias: e.target.value }))} className={inputCls} />
                        <div className="flex gap-2">
                          <Button size="sm" disabled={vincularHuerfana.isPending || !vincularForm.razon_social.trim()}
                            onClick={() => vincularHuerfana.mutate({ razon_social: vincularForm.razon_social, alias: vincularForm.alias || undefined })}>
                            {vincularHuerfana.isPending ? 'Vinculando…' : 'Confirmar'}
                          </Button>
                          <Button variant="outline" size="sm" onClick={() => { setVincularId(null); setVincularForm({ razon_social: '', alias: '' }) }}>Cancelar</Button>
                        </div>
                      </div>
                    ) : (
                      <Button variant="outline" size="sm" className="mt-2 gap-1"
                        onClick={() => { setVincularErr(''); setVincularMsg(''); setVincularId(h.id); setVincularForm({ razon_social: '', alias: '' }) }}>
                        <Link2 size={13} /> Vincular
                      </Button>
                    )}
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}

      {/* TAB: Locks Activos (admin) ────────────────────────────────────────────── */}
      {tab === 'locks' && (
        <div className="kyro-card rounded-[18px] overflow-hidden">
          <div className="border-b border-kyro-border px-4 py-3">
            <h2 className="flex items-center gap-2 text-sm font-bold text-kyro-danger"><Lock size={15} /> Locks Activos (tiendas operando)</h2>
          </div>
          <div className="p-4">
            {loadingLocks && <p className="py-6 text-center text-sm text-gray-400 dark:text-zinc-500">Cargando…</p>}
            {!loadingLocks && (locksData?.locks ?? []).length === 0 && (
              <p className="py-6 text-center text-sm text-gray-400 dark:text-zinc-500">Sin locks activos — todas las tiendas pueden declarar.</p>
            )}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {(locksData?.locks ?? []).map(lk => (
                <div key={`${lk.cuenta_bipay_id}-${lk.tienda_codigo}`} className="rounded-kyro border border-kyro-danger/25 bg-kyro-danger/5 p-3">
                  <div className="text-sm font-bold text-kyro-danger">{lk.tienda_nombre}</div>
                  <div className="text-xs text-kyro-muted">{lk.cuenta_alias}</div>
                  <div className="mt-1 text-xs text-kyro-danger">
                    Expira en {Math.floor(lk.cooldown_segs / 60)}:{String(lk.cooldown_segs % 60).padStart(2, '0')} min
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
