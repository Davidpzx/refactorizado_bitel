import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { adminPaginasApi, type PrecioPendienteItem } from '../../services/adminPaginas.api'
import { PageHeader } from '../../components/PageHeader'
import { Card } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Select } from '../../components/ui/select'
import { useTiendasSelect } from '../../hooks/useTiendasSelect'

type Edits = Record<number, { precio_costo: string; precio_minimo: string; precio_normal: string }>
type Campo = 'precio_costo' | 'precio_minimo' | 'precio_normal'
type Tab = 'pendientes' | 'matriz'

const num = (v: number | string | null) => (v === null || v === '' ? '' : String(v))

const TIPOS = ['EQUIPO', 'ACCESORIO', 'CHIP'] as const
const TIPOS_MATRIZ = ['EQUIPO', 'ACCESORIO'] as const

export function RevisarStockPage() {
  const qc = useQueryClient()
  const { tiendas } = useTiendasSelect()
  const [tab, setTab] = useState<Tab>('pendientes')

  // ── Filtros «Pendientes» (server: tienda; cliente: tipo/cantMax/soloSinStock) ──
  const [tienda, setTienda]             = useState('')
  const [tipo, setTipo]                 = useState('')
  const [cantMax, setCantMax]           = useState('')
  const [soloSinStock, setSoloSinStock] = useState(false)

  // ── Filtros «Matriz» (todos server-side, como el "Filtrar" del legacy) ─────────
  const [mTienda, setMTienda] = useState('')
  const [mTipo, setMTipo]     = useState('')
  const [mQ, setMQ]           = useState('')

  // ── Estado de edición compartido por ambas vistas ──────────────────────────────
  const [edits, setEdits] = useState<Edits>({})
  const [okId, setOkId]   = useState<number | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['precios-pendientes', tienda],
    queryFn: () => adminPaginasApi.preciosPendientes(tienda || undefined),
    enabled: tab === 'pendientes',
  })

  const { data: matrizData, isLoading: matrizLoading } = useQuery({
    queryKey: ['precios-matriz', mTienda, mTipo, mQ],
    queryFn: () => adminPaginasApi.preciosMatriz({ tienda: mTienda, tipo: mTipo, q: mQ }),
    enabled: tab === 'matriz',
  })

  const guardar = useMutation({
    mutationFn: ({ id, precios }: { id: number; precios: { precio_costo: number; precio_minimo: number; precio_normal: number } }) =>
      adminPaginasApi.guardarPrecios(id, precios),
    onSuccess: (_r, vars) => {
      setOkId(vars.id)
      setTimeout(() => setOkId(null), 1500)
      qc.invalidateQueries({ queryKey: ['precios-pendientes'] })
      qc.invalidateQueries({ queryKey: ['precios-matriz'] })
      qc.invalidateQueries({ queryKey: ['control-center'] })
    },
  })

  function valor(item: PrecioPendienteItem, campo: Campo) {
    return edits[item.id]?.[campo] ?? num(item[campo])
  }

  function setCampo(id: number, campo: Campo, v: string, item: PrecioPendienteItem) {
    setEdits((prev) => ({
      ...prev,
      [id]: {
        precio_costo:  prev[id]?.precio_costo  ?? num(item.precio_costo),
        precio_minimo: prev[id]?.precio_minimo ?? num(item.precio_minimo),
        precio_normal: prev[id]?.precio_normal ?? num(item.precio_normal),
        [campo]: v,
      },
    }))
  }

  function onGuardar(item: PrecioPendienteItem) {
    guardar.mutate({
      id: item.id,
      precios: {
        precio_costo:  Number(valor(item, 'precio_costo'))  || 0,
        precio_minimo: Number(valor(item, 'precio_minimo')) || 0,
        precio_normal: Number(valor(item, 'precio_normal')) || 0,
      },
    })
  }

  // ── Fila de edición reutilizada por ambas vistas ───────────────────────────────
  function FilaPrecio({ item, showTienda }: { item: PrecioPendienteItem; showTienda: boolean }) {
    return (
      <tr className="border-b border-kyro-border text-kyro-body hover:bg-kyro-elevated">
        {showTienda && <td className="px-3 py-2 font-semibold text-kyro-text">{item.tienda_id}</td>}
        <td className="px-3 py-2 text-kyro-body">{item.producto_nombre}</td>
        <td className="px-3 py-2 text-[10px] text-kyro-muted">{item.tipo}</td>
        <td className="px-3 py-2 text-center font-mono text-xs">
          <span className={(item.cantidad ?? 0) === 0 ? 'text-kyro-danger font-bold' : 'text-kyro-text'}>
            {item.cantidad ?? 0}
          </span>
        </td>
        <td className="px-3 py-2 font-mono text-xs text-kyro-muted">{item.imei_serial ?? '—'}</td>
        {(['precio_costo', 'precio_minimo', 'precio_normal'] as const).map((campo) => (
          <td key={campo} className="px-2 py-1">
            <Input type="number" step="0.01" min="0" placeholder="0.00"
              value={valor(item, campo)}
              onChange={(e) => setCampo(item.id, campo, e.target.value, item)} />
          </td>
        ))}
        <td className="px-3 py-1">
          <Button type="button" variant="gold" size="sm" disabled={guardar.isPending} onClick={() => onGuardar(item)}>
            {okId === item.id ? '✓' : 'Guardar'}
          </Button>
        </td>
      </tr>
    )
  }

  function Cabecera({ showTienda }: { showTienda: boolean }) {
    return (
      <thead className="kyro-table-head">
        <tr className="text-left text-[11px] uppercase tracking-wider">
          {showTienda && <th className="px-3 py-2">Tienda</th>}
          <th className="px-3 py-2">Producto</th>
          <th className="px-3 py-2">Tipo</th>
          <th className="px-3 py-2 text-center">Cant.</th>
          <th className="px-3 py-2">IMEI/Serial</th>
          <th className="px-3 py-2 w-28">Costo</th>
          <th className="px-3 py-2 w-28">Mínimo</th>
          <th className="px-3 py-2 w-28">Venta</th>
          <th className="px-3 py-2 w-24"></th>
        </tr>
      </thead>
    )
  }

  // ── Vista «Pendientes» (filtros de cliente) ────────────────────────────────────
  let pendientes = data?.data ?? []
  if (tipo)           pendientes = pendientes.filter(i => i.tipo === tipo)
  if (soloSinStock)   pendientes = pendientes.filter(i => (i.cantidad ?? 0) === 0)
  if (cantMax !== '') pendientes = pendientes.filter(i => (i.cantidad ?? 0) <= Number(cantMax))

  // ── Vista «Matriz»: agrupada por tienda + tipo (control-break del legacy) ──────
  const matrizItems = matrizData?.data ?? []
  const grupos: { key: string; tienda: string; tipo: string; items: PrecioPendienteItem[] }[] = []
  for (const item of matrizItems) {
    const key = `${item.tienda_id}·${item.tipo}`
    const last = grupos[grupos.length - 1]
    if (last && last.key === key) last.items.push(item)
    else grupos.push({ key, tienda: item.tienda_id, tipo: item.tipo, items: [item] })
  }

  return (
    <div className="max-w-6xl mx-auto space-y-6">
      <PageHeader
        title="Revisar Stock · Precios"
        description="Configura el costo, precio mínimo y precio de venta del stock disponible."
      />

      {/* Pestañas */}
      <div className="flex gap-1 border-b border-kyro-border">
        <button
          type="button"
          onClick={() => setTab('pendientes')}
          className={`px-4 py-2 text-sm font-semibold border-b-2 -mb-px transition-colors ${
            tab === 'pendientes' ? 'border-kyro-gold text-kyro-text' : 'border-transparent text-kyro-muted hover:text-kyro-body'
          }`}
        >
          Pendientes
          {data?.total ? <span className="ml-2 text-xs text-kyro-muted">({data.total})</span> : null}
        </button>
        <button
          type="button"
          onClick={() => setTab('matriz')}
          className={`px-4 py-2 text-sm font-semibold border-b-2 -mb-px transition-colors ${
            tab === 'matriz' ? 'border-kyro-gold text-kyro-text' : 'border-transparent text-kyro-muted hover:text-kyro-body'
          }`}
        >
          Matriz completa
        </button>
      </div>

      {tab === 'pendientes' ? (
        <>
          {/* Filtros Pendientes */}
          <div className="flex flex-wrap items-end gap-3">
            <div>
              <label className="block text-xs text-kyro-muted mb-1">Tienda</label>
              <Select value={tienda} onChange={e => setTienda(e.target.value)} className="h-9 w-48">
                <option value="">Todas</option>
                {tiendas.map(t => (
                  <option key={t.codigo} value={t.codigo}>{t.codigo} — {t.nombre}</option>
                ))}
              </Select>
            </div>

            <div>
              <label className="block text-xs text-kyro-muted mb-1">Tipo</label>
              <Select value={tipo} onChange={e => setTipo(e.target.value)} className="h-9 w-36">
                <option value="">Todos</option>
                {TIPOS.map(t => <option key={t} value={t}>{t}</option>)}
              </Select>
            </div>

            <div>
              <label className="block text-xs text-kyro-muted mb-1">Cant. máx.</label>
              <Input
                type="number"
                min="0"
                placeholder="Sin límite"
                value={cantMax}
                onChange={e => setCantMax(e.target.value)}
                className="h-9 w-28"
              />
            </div>

            <label className="flex items-center gap-2 cursor-pointer h-9 text-sm text-kyro-body">
              <input
                type="checkbox"
                checked={soloSinStock}
                onChange={e => setSoloSinStock(e.target.checked)}
                className="h-4 w-4 rounded accent-kyro-gold"
              />
              Solo sin stock
            </label>

            {(tienda || tipo || cantMax || soloSinStock) && (
              <Button variant="ghost" size="sm" onClick={() => { setTienda(''); setTipo(''); setCantMax(''); setSoloSinStock(false) }}>
                Limpiar
              </Button>
            )}
          </div>

          <Card className="kyro-card overflow-x-auto p-0">
            {isLoading ? (
              <p className="p-6 text-sm text-kyro-muted">Cargando…</p>
            ) : pendientes.length === 0 ? (
              <p className="p-6 text-sm text-kyro-success">✓ Sin resultados para los filtros seleccionados.</p>
            ) : (
              <table className="w-full text-sm">
                <Cabecera showTienda />
                <tbody>
                  {pendientes.map((item) => <FilaPrecio key={item.id} item={item} showTienda />)}
                </tbody>
              </table>
            )}
          </Card>
        </>
      ) : (
        <>
          {/* Filtros Matriz (server-side) */}
          <div className="flex flex-wrap items-end gap-3">
            <div>
              <label className="block text-xs text-kyro-muted mb-1">Tienda</label>
              <Select value={mTienda} onChange={e => setMTienda(e.target.value)} className="h-9 w-48">
                <option value="">Todas</option>
                {tiendas.map(t => (
                  <option key={t.codigo} value={t.codigo}>{t.codigo} — {t.nombre}</option>
                ))}
              </Select>
            </div>

            <div>
              <label className="block text-xs text-kyro-muted mb-1">Categoría</label>
              <Select value={mTipo} onChange={e => setMTipo(e.target.value)} className="h-9 w-36">
                <option value="">Todas</option>
                {TIPOS_MATRIZ.map(t => <option key={t} value={t}>{t}</option>)}
              </Select>
            </div>

            <div>
              <label className="block text-xs text-kyro-muted mb-1">Buscar</label>
              <Input
                type="search"
                placeholder="Producto o IMEI/serial"
                value={mQ}
                onChange={e => setMQ(e.target.value)}
                className="h-9 w-64"
              />
            </div>

            {(mTienda || mTipo || mQ) && (
              <Button variant="ghost" size="sm" onClick={() => { setMTienda(''); setMTipo(''); setMQ('') }}>
                Limpiar
              </Button>
            )}

            {matrizData?.total ? (
              <span className="ml-auto text-xs text-kyro-muted">{matrizData.total} productos</span>
            ) : null}
          </div>

          {matrizLoading ? (
            <Card className="kyro-card p-6"><p className="text-sm text-kyro-muted">Cargando…</p></Card>
          ) : grupos.length === 0 ? (
            <Card className="kyro-card p-6"><p className="text-sm text-kyro-muted">Sin productos para los filtros aplicados.</p></Card>
          ) : (
            <div className="space-y-5">
              {grupos.map((g) => (
                <div key={g.key}>
                  <div className="flex items-center gap-2 mb-2">
                    <span className="rounded-full bg-kyro-elevated border border-kyro-border px-3 py-1 text-xs font-semibold text-kyro-text tracking-wide">
                      {g.tienda}
                    </span>
                    <span className="rounded-full bg-kyro-elevated border border-kyro-border px-3 py-1 text-xs font-semibold text-kyro-gold tracking-wide">
                      {g.tipo}
                    </span>
                    <span className="text-xs text-kyro-muted">{g.items.length} ítem(s)</span>
                  </div>
                  <Card className="kyro-card overflow-x-auto p-0">
                    <table className="w-full text-sm">
                      <Cabecera showTienda={false} />
                      <tbody>
                        {g.items.map((item) => <FilaPrecio key={item.id} item={item} showTienda={false} />)}
                      </tbody>
                    </table>
                  </Card>
                </div>
              ))}
            </div>
          )}
        </>
      )}
    </div>
  )
}
