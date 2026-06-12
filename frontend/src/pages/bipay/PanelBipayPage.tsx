import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../../services/api'
import { Button } from '../../components/ui/button'
import { GlassPanel } from '../../components/ui/GlassPanel'
import { MoneyTotal } from '../../components/ui/MoneyTotal'
import { ListToolbar } from '../../components/ListToolbar'
import { AlertTriangle, Wallet, ArrowRightLeft, RefreshCw, CreditCard, Layers, CheckCircle2, XCircle } from 'lucide-react'

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })

const BIPAY = '#60a5fa'
const ANYPAY = '#a78bfa'
const GOLD = '#ffc200'

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

export function PanelBipayPage() {
  const qc = useQueryClient()
  const [tab, setTab] = useState<'saldo' | 'transacciones' | 'recarga'>('saldo')

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

  const { data: txData, isLoading: loadingTx } = useQuery({
    queryKey: ['bipay-transacciones', txApplied],
    queryFn: () => api.get('/v1/bipay/transacciones', { params: txApplied }).then(r => r.data),
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
    onError: (e: any) => setRecargaErr(e?.response?.data?.error ?? 'Error al registrar recarga.'),
  })

  const warning = saldoData?.warning

  if (warning) {
    return (
      <div className="flex items-center gap-3 rounded-xl border border-yellow-200 bg-yellow-50 p-4 text-sm text-yellow-800
        dark:border-yellow-400/20 dark:bg-yellow-500/10 dark:text-yellow-300">
        <AlertTriangle size={18} /> {warning}
      </div>
    )
  }

  const TABS = [
    { id: 'saldo',         label: 'Saldos',        Icon: Wallet },
    { id: 'transacciones', label: 'Transacciones',  Icon: ArrowRightLeft },
    { id: 'recarga',       label: 'Nueva Recarga',  Icon: RefreshCw },
  ] as const

  const inputCls =
    'w-full rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm transition-colors ' +
    'focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 ' +
    'dark:border-[#3f3f46] dark:bg-[#0d0d0f] dark:text-zinc-100'

  return (
    <div className="space-y-6">
      {/* Header ─────────────────────────────────────────────────────────────── */}
      <div className="flex items-stretch gap-3">
        <span
          aria-hidden
          className="w-1 shrink-0 self-stretch rounded-full"
          style={{ background: `linear-gradient(180deg, ${BIPAY}, ${ANYPAY}33)`, boxShadow: `0 0 12px -2px ${BIPAY}88` }}
        />
        <div className="flex items-center gap-3">
          <span className="flex h-11 w-11 items-center justify-center rounded-xl" style={{ background: `${BIPAY}1f`, color: BIPAY }}>
            <Wallet size={22} />
          </span>
          <div>
            <h1
              className="text-[1.35rem] font-bold leading-tight tracking-tight text-gray-900 dark:text-zinc-50"
              style={{ fontFamily: "'Orbitron', sans-serif", letterSpacing: '0.02em' }}
            >
              Panel Bipay / Anypay
            </h1>
            <p className="text-sm text-gray-500 dark:text-zinc-400">Saldos consolidados, transacciones y recargas</p>
          </div>
        </div>
      </div>

      {/* KPIs ────────────────────────────────────────────────────────────────── */}
      {saldoData?.kpis && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          {[
            { label: 'Total Bipay',  value: saldoData.kpis.total_bipay,  color: BIPAY,  Icon: CreditCard },
            { label: 'Total Anypay', value: saldoData.kpis.total_anypay, color: ANYPAY, Icon: Layers },
            { label: 'Saldo Global', value: saldoData.kpis.total_saldo,  color: GOLD,   Icon: Wallet },
          ].map(k => (
            <GlassPanel key={k.label} accentTop={k.color} className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-[0.68rem] font-semibold uppercase tracking-[0.1em] text-gray-500 dark:text-zinc-400">{k.label}</p>
                <k.Icon size={16} style={{ color: k.color }} />
              </div>
              <div className="mt-2">
                <MoneyTotal value={k.value} color={k.color} size="1.55rem" />
              </div>
            </GlassPanel>
          ))}
        </div>
      )}

      {/* Tabs ─────────────────────────────────────────────────────────────────── */}
      <div className="flex w-fit gap-1 rounded-lg border border-gray-200 bg-gray-100 p-1 dark:border-[rgba(255,255,255,0.06)] dark:bg-[#18181b]">
        {TABS.map(t => (
          <button key={t.id} onClick={() => setTab(t.id)}
            className={`flex items-center gap-1.5 rounded-md px-4 py-1.5 text-sm font-medium transition-colors ${
              tab === t.id
                ? 'bg-white text-gray-900 shadow-sm dark:bg-[#27272a] dark:text-zinc-100'
                : 'text-gray-600 hover:text-gray-800 dark:text-zinc-400 dark:hover:text-zinc-200'
            }`}
            style={tab === t.id ? { boxShadow: `inset 0 -2px 0 ${BIPAY}` } : undefined}
          >
            <t.Icon size={13} /> {t.label}
          </button>
        ))}
      </div>

      {/* TAB: Saldo por cuenta ─────────────────────────────────────────────────── */}
      {tab === 'saldo' && (
        <GlassPanel accentTop={BIPAY} className="overflow-hidden">
          <div className="border-b border-gray-200 px-4 py-3 dark:border-[rgba(255,255,255,0.06)]">
            <h2 className="text-sm font-bold" style={{ color: BIPAY }}>Cuentas Bipay / Anypay</h2>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="bg-gray-50 dark:bg-[rgba(39,39,42,0.5)]">
                  {['Alias', 'N° Cuenta', 'Tipo', 'Saldo Bipay', 'Saldo Anypay', 'Saldo Total'].map(h => (
                    <th key={h} className="px-4 py-3 text-left text-[0.68rem] font-semibold uppercase tracking-[0.07em] text-gray-500 dark:text-zinc-400">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {loadingSaldo && <tr><td colSpan={6} className="px-4 py-10 text-center text-gray-400 dark:text-zinc-500">Cargando…</td></tr>}
                {!loadingSaldo && (saldoData?.cuentas ?? []).length === 0 && (
                  <tr><td colSpan={6} className="px-4 py-10 text-center text-gray-400 dark:text-zinc-500">Sin cuentas registradas</td></tr>
                )}
                {(saldoData?.cuentas ?? []).map(c => (
                  <tr key={c.id} className="border-t border-gray-100 transition-colors hover:bg-blue-50/40 dark:border-[rgba(255,255,255,0.045)] dark:hover:bg-[rgba(96,165,250,0.05)]">
                    <td className="px-4 py-3 font-medium text-gray-800 dark:text-zinc-100">{c.alias}</td>
                    <td className="px-4 py-3 font-mono text-xs text-gray-600 dark:text-zinc-400">{c.numero_cuenta}</td>
                    <td className="px-4 py-3 text-xs uppercase text-gray-500 dark:text-zinc-400">{c.tipo}</td>
                    <td className="px-4 py-3 font-mono tabular-nums" style={{ color: BIPAY }}>{pen.format(c.saldo_bipay)}</td>
                    <td className="px-4 py-3 font-mono tabular-nums" style={{ color: ANYPAY }}>{pen.format(c.saldo_anypay)}</td>
                    <td className={`px-4 py-3 font-mono font-bold tabular-nums ${c.saldo_actual < 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-zinc-50'}`}>{pen.format(c.saldo_actual)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </GlassPanel>
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
            <Button onClick={() => setTxApplied({ ...txFilters })}>Buscar</Button>
          </ListToolbar>

          <GlassPanel accentTop={ANYPAY} className="overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="bg-gray-50 dark:bg-[rgba(39,39,42,0.5)]">
                    {['Fecha', 'Tipo', 'Plataforma', 'Origen', 'Destino', 'Monto', 'Observación'].map(h => (
                      <th key={h} className="px-4 py-3 text-left text-[0.68rem] font-semibold uppercase tracking-[0.07em] text-gray-500 dark:text-zinc-400">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {loadingTx && <tr><td colSpan={7} className="px-4 py-10 text-center text-gray-400 dark:text-zinc-500">Cargando…</td></tr>}
                  {!loadingTx && (txData?.data ?? []).length === 0 && (
                    <tr><td colSpan={7} className="px-4 py-10 text-center text-gray-400 dark:text-zinc-500">Sin transacciones en el período</td></tr>
                  )}
                  {(txData?.data ?? []).map((tx: any) => (
                    <tr key={tx.id} className="border-t border-gray-100 transition-colors hover:bg-violet-50/40 dark:border-[rgba(255,255,255,0.045)] dark:hover:bg-[rgba(167,139,250,0.05)]">
                      <td className="px-4 py-3 text-xs tabular-nums text-gray-600 dark:text-zinc-400">{new Date(tx.created_at).toLocaleDateString('es-PE')}</td>
                      <td className="px-4 py-3">
                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${tx.tipo_operacion === 'RECARGA' ? 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-400' : 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400'}`}>
                          {tx.tipo_operacion}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-xs text-gray-600 dark:text-zinc-400">{tx.plataforma ?? '—'}</td>
                      <td className="px-4 py-3 text-xs text-gray-600 dark:text-zinc-400">{tx.origen_alias ?? '—'}</td>
                      <td className="px-4 py-3 text-xs text-gray-600 dark:text-zinc-400">{tx.destino_alias ?? '—'}</td>
                      <td className="px-4 py-3 font-mono font-bold tabular-nums text-gray-900 dark:text-zinc-50">{pen.format(tx.monto)}</td>
                      <td className="max-w-xs truncate px-4 py-3 text-xs text-gray-500 dark:text-zinc-400">{tx.observacion ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </GlassPanel>
        </div>
      )}

      {/* TAB: Nueva Recarga ────────────────────────────────────────────────────── */}
      {tab === 'recarga' && (
        <GlassPanel accentTop="#34d399" className="max-w-md p-6">
          <h2 className="mb-4 flex items-center gap-2 text-sm font-bold text-gray-800 dark:text-zinc-100">
            <RefreshCw size={15} style={{ color: '#34d399' }} /> Registrar Recarga de Saldo
          </h2>
          {recargaMsg && (
            <div className="mb-4 flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-700 dark:border-emerald-400/20 dark:bg-emerald-500/10 dark:text-emerald-300">
              <CheckCircle2 size={15} /> {recargaMsg}
            </div>
          )}
          {recargaErr && (
            <div className="mb-4 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-400/20 dark:bg-red-500/10 dark:text-red-400">
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
        </GlassPanel>
      )}
    </div>
  )
}
