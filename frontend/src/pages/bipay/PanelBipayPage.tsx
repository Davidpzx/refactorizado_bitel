import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../../services/api'
import { Button } from '../../components/ui/button'
import { AlertTriangle, Wallet, ArrowRightLeft, RefreshCw } from 'lucide-react'

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })

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
      <div className="flex items-center gap-3 p-4 bg-yellow-50 border border-yellow-200 rounded-xl text-yellow-800 text-sm">
        <AlertTriangle size={18} /> {warning}
      </div>
    )
  }

  const TABS = [
    { id: 'saldo',         label: 'Saldos',        Icon: Wallet },
    { id: 'transacciones', label: 'Transacciones',  Icon: ArrowRightLeft },
    { id: 'recarga',       label: 'Nueva Recarga',  Icon: RefreshCw },
  ] as const

  return (
    <div className="space-y-6">
      <h1 className="text-xl font-semibold text-gray-900 flex items-center gap-2">
        <Wallet size={20} className="text-blue-600" /> Panel Bipay / Anypay
      </h1>

      {/* KPIs */}
      {saldoData?.kpis && (
        <div className="grid grid-cols-3 gap-4">
          {[
            { label: 'Total Bipay',   value: pen.format(saldoData.kpis.total_bipay),  color: 'text-blue-700' },
            { label: 'Total Anypay',  value: pen.format(saldoData.kpis.total_anypay), color: 'text-purple-700' },
            { label: 'Saldo Global',  value: pen.format(saldoData.kpis.total_saldo),  color: 'text-gray-900' },
          ].map(k => (
            <div key={k.label} className="bg-white rounded-xl border border-gray-200 p-4">
              <p className="text-xs text-gray-500 mb-1">{k.label}</p>
              <p className={`text-xl font-bold ${k.color}`}>{k.value}</p>
            </div>
          ))}
        </div>
      )}

      {/* Tabs */}
      <div className="flex gap-1 bg-gray-100 rounded-lg p-1 w-fit">
        {TABS.map(t => (
          <button key={t.id} onClick={() => setTab(t.id)}
            className={`flex items-center gap-1.5 px-4 py-1.5 rounded-md text-sm font-medium transition-colors ${tab === t.id ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-800'}`}
          >
            <t.Icon size={13} /> {t.label}
          </button>
        ))}
      </div>

      {/* TAB: Saldo por cuenta */}
      {tab === 'saldo' && (
        <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div className="p-4 border-b border-gray-200">
            <h2 className="font-semibold text-gray-800 text-sm">Cuentas Bipay/Anypay</h2>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="bg-gray-50 border-b border-gray-200">
                  {['Alias', 'N° Cuenta', 'Tipo', 'Saldo Bipay', 'Saldo Anypay', 'Saldo Total'].map(h => (
                    <th key={h} className="px-4 py-3 text-xs font-semibold text-gray-500 text-left">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {loadingSaldo && <tr><td colSpan={6} className="px-4 py-10 text-center text-gray-400">Cargando...</td></tr>}
                {!loadingSaldo && (saldoData?.cuentas ?? []).length === 0 && (
                  <tr><td colSpan={6} className="px-4 py-10 text-center text-gray-400">Sin cuentas registradas</td></tr>
                )}
                {(saldoData?.cuentas ?? []).map(c => (
                  <tr key={c.id} className="border-b border-gray-100 hover:bg-gray-50/60">
                    <td className="px-4 py-3 font-medium text-gray-800">{c.alias}</td>
                    <td className="px-4 py-3 font-mono text-xs text-gray-600">{c.numero_cuenta}</td>
                    <td className="px-4 py-3 text-xs uppercase text-gray-500">{c.tipo}</td>
                    <td className="px-4 py-3 font-mono text-blue-700">{pen.format(c.saldo_bipay)}</td>
                    <td className="px-4 py-3 font-mono text-purple-700">{pen.format(c.saldo_anypay)}</td>
                    <td className={`px-4 py-3 font-bold font-mono ${c.saldo_actual < 0 ? 'text-red-600' : 'text-gray-900'}`}>{pen.format(c.saldo_actual)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* TAB: Transacciones */}
      {tab === 'transacciones' && (
        <div className="space-y-4">
          <div className="bg-white rounded-xl border border-gray-200 p-4">
            <div className="flex flex-wrap items-end gap-3">
              <div>
                <label className="block text-xs text-gray-500 mb-1">Desde</label>
                <input type="date" value={txFilters.fecha_desde}
                  onChange={e => setTxFilters(f => ({ ...f, fecha_desde: e.target.value }))}
                  className="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div>
                <label className="block text-xs text-gray-500 mb-1">Hasta</label>
                <input type="date" value={txFilters.fecha_hasta}
                  onChange={e => setTxFilters(f => ({ ...f, fecha_hasta: e.target.value }))}
                  className="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <Button onClick={() => setTxApplied({ ...txFilters })}>Buscar</Button>
            </div>
          </div>
          <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="bg-gray-50 border-b border-gray-200">
                    {['Fecha', 'Tipo', 'Plataforma', 'Origen', 'Destino', 'Monto', 'Observación'].map(h => (
                      <th key={h} className="px-4 py-3 text-xs font-semibold text-gray-500 text-left">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {loadingTx && <tr><td colSpan={7} className="px-4 py-10 text-center text-gray-400">Cargando...</td></tr>}
                  {!loadingTx && (txData?.data ?? []).length === 0 && (
                    <tr><td colSpan={7} className="px-4 py-10 text-center text-gray-400">Sin transacciones en el período</td></tr>
                  )}
                  {(txData?.data ?? []).map((tx: any) => (
                    <tr key={tx.id} className="border-b border-gray-100 hover:bg-gray-50/60">
                      <td className="px-4 py-3 text-xs text-gray-600">{new Date(tx.created_at).toLocaleDateString('es-PE')}</td>
                      <td className="px-4 py-3">
                        <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${tx.tipo_operacion === 'RECARGA' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700'}`}>
                          {tx.tipo_operacion}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-xs">{tx.plataforma ?? '—'}</td>
                      <td className="px-4 py-3 text-xs text-gray-600">{tx.origen_alias ?? '—'}</td>
                      <td className="px-4 py-3 text-xs text-gray-600">{tx.destino_alias ?? '—'}</td>
                      <td className="px-4 py-3 font-mono font-bold text-gray-900">{pen.format(tx.monto)}</td>
                      <td className="px-4 py-3 text-xs text-gray-500 max-w-xs truncate">{tx.observacion ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* TAB: Nueva Recarga */}
      {tab === 'recarga' && (
        <div className="bg-white rounded-xl border border-gray-200 p-6 max-w-md">
          <h2 className="font-semibold text-gray-800 mb-4 text-sm">Registrar Recarga de Saldo</h2>
          {recargaMsg && <div className="p-3 mb-4 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">{recargaMsg}</div>}
          {recargaErr && <div className="p-3 mb-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">{recargaErr}</div>}
          <div className="space-y-3">
            <div>
              <label className="block text-xs text-gray-500 mb-1">Cuenta</label>
              <select value={recargaForm.cuenta_id} onChange={e => setRecargaForm(f => ({ ...f, cuenta_id: e.target.value }))}
                className="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="">-- Seleccionar cuenta --</option>
                {(saldoData?.cuentas ?? []).map(c => (
                  <option key={c.id} value={c.id}>{c.alias} ({c.numero_cuenta})</option>
                ))}
              </select>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs text-gray-500 mb-1">Monto Bipay (S/)</label>
                <input type="number" min="0" step="0.01" value={recargaForm.monto_bipay}
                  onChange={e => setRecargaForm(f => ({ ...f, monto_bipay: e.target.value }))}
                  className="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div>
                <label className="block text-xs text-gray-500 mb-1">Monto Anypay (S/)</label>
                <input type="number" min="0" step="0.01" value={recargaForm.monto_anypay}
                  onChange={e => setRecargaForm(f => ({ ...f, monto_anypay: e.target.value }))}
                  className="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
            </div>
            <div>
              <label className="block text-xs text-gray-500 mb-1">Referencia / Observación</label>
              <input type="text" value={recargaForm.referencia}
                onChange={e => setRecargaForm(f => ({ ...f, referencia: e.target.value }))}
                placeholder="N° de operación, etc."
                className="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
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
              {recarga.isPending ? 'Registrando...' : 'Registrar Recarga'}
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
