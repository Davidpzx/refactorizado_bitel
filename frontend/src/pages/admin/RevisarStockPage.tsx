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

const num = (v: number | string | null) => (v === null || v === '' ? '' : String(v))

const TIPOS = ['EQUIPO', 'ACCESORIO', 'CHIP'] as const

export function RevisarStockPage() {
  const qc = useQueryClient()
  const { tiendas } = useTiendasSelect()
  const [tienda, setTienda]             = useState('')
  const [tipo, setTipo]                 = useState('')
  const [cantMax, setCantMax]           = useState('')
  const [soloSinStock, setSoloSinStock] = useState(false)
  const [edits, setEdits]               = useState<Edits>({})
  const [okId, setOkId]                 = useState<number | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['precios-pendientes', tienda],
    queryFn: () => adminPaginasApi.preciosPendientes(tienda || undefined),
  })

  const guardar = useMutation({
    mutationFn: ({ id, precios }: { id: number; precios: { precio_costo: number; precio_minimo: number; precio_normal: number } }) =>
      adminPaginasApi.guardarPrecios(id, precios),
    onSuccess: (_r, vars) => {
      setOkId(vars.id)
      setTimeout(() => setOkId(null), 1500)
      qc.invalidateQueries({ queryKey: ['precios-pendientes'] })
      qc.invalidateQueries({ queryKey: ['control-center'] })
    },
  })

  function valor(item: PrecioPendienteItem, campo: 'precio_costo' | 'precio_minimo' | 'precio_normal') {
    return edits[item.id]?.[campo] ?? num(item[campo])
  }

  function setCampo(id: number, campo: 'precio_costo' | 'precio_minimo' | 'precio_normal', v: string, item: PrecioPendienteItem) {
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

  // Client-side filters
  let items = data?.data ?? []
  if (tipo)         items = items.filter(i => i.tipo === tipo)
  if (soloSinStock) items = items.filter(i => (i.cantidad ?? 0) === 0)
  if (cantMax !== '') items = items.filter(i => (i.cantidad ?? 0) <= Number(cantMax))

  return (
    <div className="max-w-6xl mx-auto space-y-6">
      <PageHeader
        title="Revisar Stock · Precios pendientes"
        description="Configura el costo, precio mínimo y precio de venta del stock disponible sin precio."
      />

      {/* Filtros */}
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
        ) : items.length === 0 ? (
          <p className="p-6 text-sm text-kyro-success">✓ Sin resultados para los filtros seleccionados.</p>
        ) : (
          <table className="w-full text-sm">
            <thead className="kyro-table-head">
              <tr className="text-left text-[11px] uppercase tracking-wider">
                <th className="px-3 py-2">Tienda</th>
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
            <tbody>
              {items.map((item) => (
                <tr key={item.id} className="border-b border-kyro-border text-kyro-body hover:bg-kyro-elevated">
                  <td className="px-3 py-2 font-semibold text-kyro-text">{item.tienda_id}</td>
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
              ))}
            </tbody>
          </table>
        )}
      </Card>
    </div>
  )
}
