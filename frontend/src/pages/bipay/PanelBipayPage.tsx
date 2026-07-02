import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../../services/api'
import { Button } from '../../components/ui/button'
import { ActionIconButton, TableActions } from '../../components/ui/ActionIconButton'
import { MoneyTotal } from '../../components/ui/MoneyTotal'
import { ListToolbar } from '../../components/ListToolbar'
import { PageHeader } from '../../components/PageHeader'
import { apiErrorData } from '../../lib/httpError'
import { AlertTriangle, Wallet, ArrowRightLeft, RefreshCw, CreditCard, Layers, CheckCircle2, XCircle, Send, SlidersHorizontal, Pencil, Trash2, Scale } from 'lucide-react'
import { CuadreBitelPanel } from './CuadreBitelPage'

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })

const BIPAY = 'var(--color-kpi-bipay)'
const ANYPAY = 'var(--color-kpi-yape)'
const GOLD = 'var(--color-kyro-gold)'

interface Cuenta {
  id: number
  alias: string
  numero_cuenta: string
  tipo: string
  saldo_actual: number
  saldo_bipay: number
  saldo_anypay: number
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

export function PanelBipayPage() {
  const qc = useQueryClient()
  const [tab, setTab] = useState<'saldo' | 'transacciones' | 'recarga' | 'transferir' | 'ajustar' | 'cuentas' | 'cuadre'>('saldo')

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
  })
  const [txApplied, setTxApplied] = useState({ ...txFilters })

  const { data: txData, isLoading: loadingTx } = useQuery<TransaccionesData>({
    queryKey: ['bipay-transacciones', txApplied],
    queryFn: () => api.get<TransaccionesData>('/v1/bipay/transacciones', { params: txApplied }).then(r => r.data),
    enabled: tab === 'transacciones',
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

  const warning = saldoData?.warning

  if (warning) {
    return (
      <div className="flex items-center gap-3 rounded-kyro-lg border border-kyro-warning/30 bg-kyro-warning/10 p-4 text-sm text-kyro-warning shadow-kyro-card">
        <AlertTriangle size={18} /> {warning}
      </div>
    )
  }

  const TABS = [
    { id: 'saldo',         label: 'Saldos',        Icon: Wallet },
    { id: 'transacciones', label: 'Transacciones',  Icon: ArrowRightLeft },
    { id: 'recarga',       label: 'Nueva Recarga',  Icon: RefreshCw },
    { id: 'transferir',    label: 'Transferir',     Icon: Send },
    { id: 'ajustar',       label: 'Ajustar Saldo',  Icon: SlidersHorizontal },
    { id: 'cuentas',       label: 'Cuentas',        Icon: CreditCard },
    { id: 'cuadre',        label: 'Cuadre Bitel',   Icon: Scale },
  ] as const

  const inputCls = 'kyro-input'

  return (
    <div className="space-y-6">
      {/* Header ─────────────────────────────────────────────────────────────── */}
      <PageHeader title="Panel Bipay / Anypay" subtitle="Saldos consolidados, transacciones y recargas">
        <span className="flex h-10 w-10 items-center justify-center rounded-kyro bg-kpi-bipay/15 text-kpi-bipay">
          <Wallet size={20} />
        </span>
      </PageHeader>

      {/* KPIs ────────────────────────────────────────────────────────────────── */}
      {saldoData?.kpis && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          {[
            { label: 'Total Bipay',  value: saldoData.kpis.total_bipay,  color: BIPAY,  border: 'border-l-kpi-bipay', Icon: CreditCard },
            { label: 'Total Anypay', value: saldoData.kpis.total_anypay, color: ANYPAY, border: 'border-l-kpi-yape', Icon: Layers },
            { label: 'Saldo Global', value: saldoData.kpis.total_saldo,  color: GOLD,   border: 'border-l-kpi-total', Icon: Wallet },
          ].map(k => (
            <div key={k.label} className={`kyro-card border-l-4 p-4 ${k.border}`}>
              <div className="flex items-center justify-between">
                <p className="text-[0.68rem] font-semibold uppercase tracking-[0.1em] text-kyro-muted">{k.label}</p>
                <k.Icon size={16} style={{ color: k.color }} />
              </div>
              <div className="mt-2">
                <MoneyTotal value={k.value} color={k.color} size="1.55rem" />
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Tabs ─────────────────────────────────────────────────────────────────── */}
      <div className="kyro-card flex w-fit gap-1 p-1">
        {TABS.map(t => (
          <Button key={t.id} variant="ghost" size="sm" onClick={() => setTab(t.id)}
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
      {tab === 'cuadre' && <CuadreBitelPanel />}

      {/* TAB: Saldo por cuenta ─────────────────────────────────────────────────── */}
      {tab === 'saldo' && (
        <div className="kyro-card overflow-hidden">
          <div className="border-b border-kyro-border px-4 py-3">
            <h2 className="text-sm font-bold text-kpi-bipay">Cuentas Bipay / Anypay</h2>
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
                  <tr><td colSpan={6} className="px-4 py-10 text-center text-gray-400 dark:text-zinc-500">Sin cuentas registradas</td></tr>
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
            <Button variant="gold" onClick={() => setTxApplied({ ...txFilters })}>Buscar</Button>
          </ListToolbar>

          <div className="kyro-card overflow-hidden">
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
                    <tr><td colSpan={7} className="px-4 py-10 text-center text-gray-400 dark:text-zinc-500">Sin transacciones en el período</td></tr>
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
        <div className="kyro-card max-w-md border-t-2 border-t-kpi-transfer p-6">
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
        <div className="kyro-card max-w-md border-t-2 border-t-kpi-bipay p-6">
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
        <div className="kyro-card max-w-md border-t-2 border-t-kyro-gold p-6">
          <h2 className="mb-4 flex items-center gap-2 text-sm font-bold text-kyro-text">
            <SlidersHorizontal size={15} style={{ color: GOLD }} /> Ajuste Manual de Saldo
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
            <Button variant="gold" disabled={ajustar.isPending || !ajusteForm.cuenta_id || ajusteForm.motivo.trim().length < 5}
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
          <div className="kyro-card overflow-hidden lg:col-span-2">
            <div className="border-b border-kyro-border px-4 py-3">
              <h2 className="text-sm font-bold text-kpi-bipay">Cuentas registradas</h2>
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
                    <tr><td colSpan={5} className="px-4 py-10 text-center text-gray-400 dark:text-zinc-500">Sin cuentas registradas</td></tr>
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
                            onClick={() => { if (confirm(`¿Eliminar la cuenta "${c.alias}"?`)) eliminarCuenta.mutate(c.id) }}
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
          <div className="kyro-card border-t-2 border-t-kpi-bipay p-6">
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
                <Button variant="gold" className="flex-1"
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
        </div>
      )}
    </div>
  )
}
