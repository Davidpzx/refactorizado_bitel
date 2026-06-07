import { useState } from 'react'
import type { ColumnDef, PaginationState } from '@tanstack/react-table'
import { useInventario, useEliminarInventario } from '../../hooks/useInventario'
import { DataTable } from '../../components/DataTable'
import { PageHeader } from '../../components/PageHeader'
import { Dialog } from '../../components/ui/dialog'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { Select } from '../../components/ui/select'
import { InventarioForm } from './InventarioForm'
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
      header: 'Acciones',
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          <Button size="sm" variant="outline" onClick={() => onEditar(row.original)}>
            Editar
          </Button>
          <Button
            size="sm"
            variant="destructive"
            onClick={() => onEliminar(row.original)}
            disabled={eliminando}
          >
            Eliminar
          </Button>
        </div>
      ),
    },
  ]
}

export function InventarioPage() {
  const [search, setSearch]         = useState('')
  const [query, setQuery]           = useState('')
  const [tienda, setTienda]         = useState('')
  const [tipo, setTipo]             = useState('')
  const [estado, setEstado]         = useState('')
  const [pagination, setPagination] = useState<PaginationState>({ pageIndex: 0, pageSize: 20 })
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editando, setEditando]     = useState<InventarioItem | undefined>()

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

  const columns = getColumns(abrirEditar, handleEliminar, eliminar.isPending)

  return (
    <div>
      <PageHeader
        title="Inventario de Tiendas"
        description="Stock de equipos, accesorios y chips por tienda."
        actions={<Button onClick={abrirCrear}>+ Nuevo item</Button>}
      />

      <div className="flex flex-wrap items-center gap-3 mb-4">
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
          value={tipo}
          onChange={(e) => {
            setTipo(e.target.value)
            setPagination((p) => ({ ...p, pageIndex: 0 }))
          }}
          className="w-36"
        >
          <option value="">Todos los tipos</option>
          <option value="EQUIPO">Equipo</option>
          <option value="ACCESORIO">Accesorio</option>
          <option value="CHIP">Chip</option>
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
      </div>

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
    </div>
  )
}
