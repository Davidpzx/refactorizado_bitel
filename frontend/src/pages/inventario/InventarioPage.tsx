import { useState } from 'react'
import type { AxiosError } from 'axios'
import type { ColumnDef, PaginationState } from '@tanstack/react-table'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Pencil, Trash2, SlidersHorizontal, Tag, History } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useInventario, useEliminarInventario } from '../../hooks/useInventario'
import { api } from '../../services/api'
import { DataTable } from '../../components/DataTable'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { Dialog } from '../../components/ui/dialog'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { Select } from '../../components/ui/select'
import { PageTabs } from '../../components/ui/PageTabs'
import { Label } from '../../components/ui/label'
import { InventarioForm } from './InventarioForm'
import { useAuth } from '../../hooks/useAuth'
import type { InventarioItem } from '../../types/inventario'

const TIENDAS = [
  { codigo: 'PUNDA50', nombre: 'Puno — PUNDA50' },
  { codigo: 'PUNDA11', nombre: 'Puno — PUNDA11' },
  { codigo: 'PUNSC01', nombre: 'Puno — PUNSC01' },
  { codigo: 'PUNDA23', nombre: 'Puno — PUNDA23' },
  { codigo: 'TACDA13', nombre: 'Tacna — TACDA13' },
  { codigo: 'TACDA17', nombre: 'Tacna — TACDA17' },
  { codigo: 'TACDA21', nombre: 'Tacna — TACDA21' },
  { codigo: 'TACDA25', nombre: 'Tacna — TACDA25' },
  { codigo: 'TACDA27', nombre: 'Tacna — TACDA27' },
  { codigo: 'TACDA30', nombre: 'Tacna — TACDA30' },
]

type EstadoVariant = 'success' | 'destructive' | 'warning'
const estadoVariant: Record<InventarioItem['estado'], EstadoVariant> = {
  DISPONIBLE: 'success',
  VENDIDO:    'destructive',
  TRASLADO:   'warning',
}

type TipoVariant = 'default' | 'outline' | 'warning'
const tipoVariant: Record<InventarioItem['tipo'], TipoVariant> = {
  EQUIPO:    'default',
  ACCESORIO: 'outline',
  CHIP:      'warning',
}

function getColumns(
  onEditar: (i: InventarioItem) => void,
  onEliminar: (i: InventarioItem) => void,
  eliminando: boolean,
  onFijarPrecio?: (i: InventarioItem) => void,
  onAjustar?: (i: InventarioItem) => void,
): ColumnDef<InventarioItem>[] {
  return [
    { accessorKey: 'producto_nombre', header: 'Producto' },
    {
      accessorKey: 'tipo',
      header: 'Tipo',
      cell: ({ row }) => (
        <Badge variant={tipoVariant[row.original.tipo]}>{row.original.tipo}</Badge>
      ),
    },
    {
      accessorKey: 'imei_serial',
      header: 'IMEI / Serie',
      cell: ({ row }) => row.original.imei_serial ?? '—',
    },
    { accessorKey: 'tienda_id', header: 'Tienda' },
    { accessorKey: 'cantidad',  header: 'Cant.' },
    {
      accessorKey: 'precio_normal',
      header: 'Precio',
      cell: ({ row }) => `S/ ${parseFloat(row.original.precio_normal).toFixed(2)}`,
    },
    {
      accessorKey: 'estado',
      header: 'Estado',
      cell: ({ row }) => (
        <Badge variant={estadoVariant[row.original.estado]}>{row.original.estado}</Badge>
      ),
    },
    {
      accessorKey: 'fecha_registro',
      header: 'Fecha',
      cell: ({ row }) => row.original.fecha_registro?.slice(0, 10) ?? '—',
    },
    {
      id: 'acciones',
      header: '',
      cell: ({ row }) => (
        <div className="flex items-center gap-1">
          {onFijarPrecio ? (
            <button
              onClick={() => onFijarPrecio(row.original)}
              title="Fijar precio de venta"
              className="inline-flex h-7 items-center gap-1.5 rounded-lg border border-transparent px-2 text-xs font-medium text-kyro-muted transition-all hover:border-amber-500/40 hover:bg-amber-500/10 hover:text-amber-600 dark:hover:text-amber-400"
            >
              <Tag size={13} /> Fijar precio
            </button>
          ) : (
            <>
              <button
                onClick={() => onEditar(row.original)}
                title="Editar item"
                className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-transparent text-kyro-muted transition-all hover:border-amber-500/40 hover:bg-amber-500/10 hover:text-amber-600 dark:hover:text-amber-400"
              >
                <Pencil size={13} />
              </button>
              {onAjustar && (
                <button
                  onClick={() => onAjustar(row.original)}
                  title="Ajustar stock físico"
                  className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-transparent text-kyro-muted transition-all hover:border-cyan-500/40 hover:bg-cyan-500/10 hover:text-cyan-600 dark:hover:text-cyan-400"
                >
                  <SlidersHorizontal size={13} />
                </button>
              )}
              <button
                onClick={() => onEliminar(row.original)}
                disabled={eliminando}
                title="Eliminar item"
                className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-transparent text-kyro-muted transition-all hover:border-red-500/40 hover:bg-red-500/10 hover:text-red-600 dark:hover:text-red-400 disabled:opacity-40 disabled:pointer-events-none"
              >
                <Trash2 size={13} />
              </button>
            </>
          )}
        </div>
      ),
    },
  ]
}

// ── Campana Costos ────────────────────────────────────────────────────────────

interface CampanaItem {
  rc_id: number
  producto: string
  imei: string | null
  precio_venta: number | null
  tienda: string
  fecha: string
}

interface CampanaResponse {
  count: number
  items: CampanaItem[]
}

function CampanaCostosWidget() {
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [costos, setCostos] = useState<Record<number, string>>({})
  const [guardando, setGuardando] = useState<number | null>(null)

  const { data, refetch } = useQuery<CampanaResponse>({
    queryKey: ['inventario-campana-costos'],
    queryFn: () => api.get('/v1/inventario/campana-costos').then(r => r.data),
    staleTime: 60_000,
  })

  const fijarCosto = useMutation({
    mutationFn: ({ rc_id, precio_costo, precio_venta }: { rc_id: number; precio_costo: number; precio_venta: number | null }) =>
      api.post(`/v1/reporte-categorias/${rc_id}/fijar-costo`, { precio_costo, precio_venta }).then(r => r.data),
    onSuccess: () => {
      refetch()
      qc.invalidateQueries({ queryKey: ['inventario-campana-costos'] })
    },
  })

  const count = data?.count ?? 0
  if (count === 0) return null

  const handleGuardar = async (item: CampanaItem) => {
    const val = parseFloat(costos[item.rc_id] ?? '')
    if (isNaN(val) || val <= 0) return
    setGuardando(item.rc_id)
    await fijarCosto.mutateAsync({ rc_id: item.rc_id, precio_costo: val, precio_venta: item.precio_venta })
    setGuardando(null)
  }

  return (
    <>
      <Button
        variant="outline"
        onClick={() => setOpen(true)}
        className="mb-4 h-auto w-full justify-start gap-2 border-kyro-warning/40 bg-kyro-warning/10 px-4 py-2.5 text-kyro-warning hover:bg-kyro-warning/20"
      >
        <AlertTriangle size={15} className="shrink-0" />
        <span>{count} {count === 1 ? 'venta sin' : 'ventas sin'} precio de costo — Haz clic para corregir</span>
      </Button>

      <Dialog open={open} onClose={() => setOpen(false)} title="Ventas sin precio de costo" maxWidth="lg">
        <div className="space-y-3 max-h-[60vh] overflow-y-auto pr-1">
          {(data?.items ?? []).map(item => (
            <div key={item.rc_id} className="flex items-end gap-3 border-b border-kyro-border pb-3">
              <div className="flex-1 min-w-0">
                <p className="truncate text-sm font-medium text-kyro-text">{item.producto}</p>
                <p className="text-xs text-kyro-muted">
                  {item.tienda} · {item.fecha?.slice(0, 10)}
                  {item.imei ? ` · ${item.imei}` : ''}
                  {item.precio_venta != null ? ` · Venta: S/ ${parseFloat(String(item.precio_venta)).toFixed(2)}` : ''}
                </p>
              </div>
              <div className="flex items-end gap-2 shrink-0">
                <div>
                  <Label className="text-xs">P. Costo</Label>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="0.00"
                    className="w-24 text-right"
                    value={costos[item.rc_id] ?? ''}
                    onChange={e => setCostos(prev => ({ ...prev, [item.rc_id]: e.target.value }))}
                  />
                </div>
                <Button
                  size="sm"
                  onClick={() => handleGuardar(item)}
                  disabled={guardando === item.rc_id || !costos[item.rc_id]}
                >
                  {guardando === item.rc_id ? '...' : 'Guardar'}
                </Button>
              </div>
            </div>
          ))}
        </div>
        <div className="flex justify-end pt-3">
          <Button variant="outline" onClick={() => setOpen(false)}>Cerrar</Button>
        </div>
      </Dialog>
    </>
  )
}

// ── Page ──────────────────────────────────────────────────────────────────────

export function InventarioPage() {
  const { usuario } = useAuth()
  const esTienda    = usuario?.rol !== 'admin'
  const qc          = useQueryClient()
  const [search, setSearch]         = useState('')
  const [query, setQuery]           = useState('')
  const [tienda, setTienda]         = useState('')
  const [tipo, setTipo]             = useState('')
  const [estado, setEstado]         = useState('')
  const [pagination, setPagination] = useState<PaginationState>({ pageIndex: 0, pageSize: 20 })
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editando, setEditando]     = useState<InventarioItem | undefined>()
  const [ajusteItem, setAjusteItem] = useState<InventarioItem | undefined>()
  const [cantidadReal, setCantidadReal] = useState('')
  const [observacionAjuste, setObservacionAjuste] = useState('')
  const [ajusteError, setAjusteError] = useState('')

  // T2.3 — Fijar precio por el agente (solo precio_normal, con DNI).
  const [precioItem, setPrecioItem] = useState<InventarioItem | undefined>()
  const [precioVal, setPrecioVal]   = useState('')
  const [precioDni, setPrecioDni]   = useState('')
  const [precioErr, setPrecioErr]   = useState('')
  const fijarPrecio = useMutation({
    mutationFn: (p: { id: number; precio_normal: number; dni_autoriza: string }) =>
      api.post(`/v1/inventario/${p.id}/precio-agente`, { precio_normal: p.precio_normal, dni_autoriza: p.dni_autoriza }).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventario'] })
      setPrecioItem(undefined); setPrecioVal(''); setPrecioDni(''); setPrecioErr('')
    },
    onError: (e: AxiosError<{ msg?: string }>) => setPrecioErr(e.response?.data?.msg ?? 'No se pudo fijar el precio.'),
  })
  const abrirPrecio = (i: InventarioItem) => { setPrecioItem(i); setPrecioVal(''); setPrecioDni(''); setPrecioErr('') }
  const ajustarStock = useMutation({
    mutationFn: (p: { id: number; cantidad_real: number; observacion: string }) =>
      api.post(`/v1/inventario/${p.id}/ajustar-stock-real`, p).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventario'] })
      setAjusteItem(undefined); setCantidadReal(''); setObservacionAjuste(''); setAjusteError('')
    },
    onError: (e: AxiosError<{ message?: string }>) => setAjusteError(e.response?.data?.message ?? 'No se pudo ajustar el stock.'),
  })
  const abrirAjuste = (i: InventarioItem) => {
    setAjusteItem(i)
    setCantidadReal(String(i.cantidad))
    setObservacionAjuste('')
    setAjusteError('')
  }

  const { data, isLoading } = useInventario({
    q:        query  || undefined,
    tienda:   tienda || undefined,
    tipo:     tipo   || undefined,
    estado:   estado || undefined,
    page:     pagination.pageIndex + 1,
    per_page: pagination.pageSize,
  })

  const eliminar = useEliminarInventario()

  const abrirCrear  = () => { setEditando(undefined); setDialogOpen(true) }
  const abrirEditar = (i: InventarioItem) => { setEditando(i); setDialogOpen(true) }
  const cerrar      = () => setDialogOpen(false)

  const handleEliminar = (i: InventarioItem) => {
    if (!confirm(`¿Eliminar "${i.producto_nombre}"?`)) return
    eliminar.mutate(i.id)
  }

  const buscar = () => {
    setQuery(search)
    setPagination((p) => ({ ...p, pageIndex: 0 }))
  }

  const limpiarFiltros = () => {
    setSearch('')
    setQuery('')
    setTienda('')
    setTipo('')
    setEstado('')
    setPagination((p) => ({ ...p, pageIndex: 0 }))
  }

  const hayFiltros = query || tienda || tipo || estado

  const columns = getColumns(
    abrirEditar,
    handleEliminar,
    eliminar.isPending,
    esTienda ? abrirPrecio : undefined,
    esTienda ? undefined : abrirAjuste,
  )

  return (
    <div className="space-y-6">
      <PageHeader
        title="Inventario de Tiendas"
        description="Stock de equipos, accesorios y chips por tienda."
        actions={
          <div className="flex items-center gap-2">
            {!esTienda && (
              <Link
                to="/bitacora-stock"
                className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-gray-200/80 bg-white/80 px-3 text-xs font-semibold text-gray-600 shadow-sm backdrop-blur-xl transition-all hover:border-amber-300 hover:text-amber-600 dark:border-white/10 dark:bg-zinc-900/65 dark:text-zinc-300 dark:hover:border-amber-400/40 dark:hover:text-amber-400"
                title="Historial de movimientos de equipos y accesorios"
              >
                <History size={14} /> Bitácora Stock
              </Link>
            )}
            <Button onClick={abrirCrear}>+ Nuevo item</Button>
          </div>
        }
      />

      <CampanaCostosWidget />

      <PageTabs
        tabs={[
          { id: '', label: 'Todos' },
          { id: 'EQUIPO', label: 'Equipos' },
          { id: 'ACCESORIO', label: 'Accesorios' },
          { id: 'CHIP', label: 'Chips' },
        ]}
        active={tipo}
        onChange={(id) => { setTipo(id); setPagination((p) => ({ ...p, pageIndex: 0 })) }}
        className="mb-4"
      />

      <ListToolbar description="Busca y segmenta el stock por tienda y estado.">
        <Input
          placeholder="Buscar por producto o IMEI..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') buscar() }}
          className="max-w-xs"
        />
        <Button variant="outline" onClick={buscar}>Buscar</Button>

        <Select
          value={tienda}
          onChange={(e) => {
            setTienda(e.target.value)
            setPagination((p) => ({ ...p, pageIndex: 0 }))
          }}
          className="w-44"
        >
          <option value="">Todas las tiendas</option>
          {TIENDAS.map((t) => (
            <option key={t.codigo} value={t.codigo}>{t.nombre}</option>
          ))}
        </Select>

        <Select
          value={estado}
          onChange={(e) => {
            setEstado(e.target.value)
            setPagination((p) => ({ ...p, pageIndex: 0 }))
          }}
          className="w-36"
        >
          <option value="">Todos los estados</option>
          <option value="DISPONIBLE">Disponible</option>
          <option value="VENDIDO">Vendido</option>
          <option value="TRASLADO">Traslado</option>
        </Select>

        {hayFiltros && (
          <Button variant="ghost" onClick={limpiarFiltros}>Limpiar filtros</Button>
        )}
      </ListToolbar>

      <DataTable
        data={data?.data ?? []}
        columns={columns}
        pageCount={data?.last_page ?? 0}
        pagination={pagination}
        onPaginationChange={setPagination}
        isLoading={isLoading}
        total={data?.total}
      />

      <Dialog
        open={dialogOpen}
        onClose={cerrar}
        title={editando ? 'Editar item de inventario' : 'Nuevo item de inventario'}
        maxWidth="lg"
      >
        <InventarioForm item={editando} onSuccess={cerrar} onCancel={cerrar} />
      </Dialog>

      <Dialog
        open={!!ajusteItem}
        onClose={() => setAjusteItem(undefined)}
        title="Sincronizar stock fisico"
        maxWidth="sm"
      >
        {ajusteItem && (
          <div className="space-y-4">
            <div>
              <p className="text-sm font-medium text-kyro-text">{ajusteItem.producto_nombre}</p>
              <p className="text-xs text-kyro-muted">Stock actual en sistema: {ajusteItem.cantidad}</p>
            </div>
            <div>
              <Label>Cantidad fisica real</Label>
              <Input type="number" min="0" value={cantidadReal} onChange={e => setCantidadReal(e.target.value)} className="mt-1" />
            </div>
            <div>
              <Label>Observacion del ajuste</Label>
              <textarea
                rows={3}
                value={observacionAjuste}
                onChange={e => setObservacionAjuste(e.target.value)}
                className="kyro-input mt-1 w-full"
                placeholder="Motivo y referencia del conteo fisico"
              />
            </div>
            {ajusteError && <p className="text-xs text-kyro-danger">{ajusteError}</p>}
            <div className="flex gap-3">
              <Button
                className="flex-1"
                disabled={ajustarStock.isPending || cantidadReal === '' || observacionAjuste.trim().length < 10}
                onClick={() => ajustarStock.mutate({
                  id: ajusteItem.id,
                  cantidad_real: Number(cantidadReal),
                  observacion: observacionAjuste.trim(),
                })}
              >
                {ajustarStock.isPending ? 'Ajustando...' : 'Aplicar ajuste'}
              </Button>
              <Button variant="outline" onClick={() => setAjusteItem(undefined)}>Cancelar</Button>
            </div>
          </div>
        )}
      </Dialog>

      {/* T2.3 — Diálogo fijar precio (agente) */}
      <Dialog
        open={!!precioItem}
        onClose={() => setPrecioItem(undefined)}
        title="Fijar precio de venta"
        maxWidth="sm"
      >
        {precioItem && (
          <div className="space-y-4">
            <div>
              <p className="text-sm font-medium text-kyro-text">{precioItem.producto_nombre}</p>
              <p className="text-xs text-kyro-muted">
                Precio actual: S/ {parseFloat(precioItem.precio_normal).toFixed(2)}
                {precioItem.precio_minimo ? ` · Mínimo: S/ ${parseFloat(String(precioItem.precio_minimo)).toFixed(2)}` : ''}
              </p>
            </div>
            <div>
              <Label className="text-xs">Nuevo precio de venta (S/)</Label>
              <Input type="number" step="0.01" min="0" value={precioVal}
                onChange={e => setPrecioVal(e.target.value)} placeholder="0.00" className="mt-1" />
            </div>
            <div>
              <Label className="text-xs">DNI que autoriza (agente activo)</Label>
              <Input type="text" inputMode="numeric" maxLength={8} value={precioDni}
                onChange={e => setPrecioDni(e.target.value.replace(/\D/g, ''))} placeholder="8 dígitos" className="mt-1" />
            </div>
            {precioErr && <p className="text-xs text-kyro-danger">{precioErr}</p>}
            <div className="flex gap-3 pt-1">
              <Button className="flex-1"
                disabled={fijarPrecio.isPending || !precioVal || Number(precioVal) <= 0 || precioDni.length !== 8}
                onClick={() => fijarPrecio.mutate({ id: precioItem.id, precio_normal: Number(precioVal), dni_autoriza: precioDni })}>
                {fijarPrecio.isPending ? 'Guardando...' : 'Fijar precio'}
              </Button>
              <Button variant="outline" onClick={() => setPrecioItem(undefined)}>Cancelar</Button>
            </div>
          </div>
        )}
      </Dialog>
    </div>
  )
}
