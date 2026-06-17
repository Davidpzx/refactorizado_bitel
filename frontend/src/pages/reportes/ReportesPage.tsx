import { useState } from 'react'
import { Link } from 'react-router-dom'
import type { ColumnDef, PaginationState } from '@tanstack/react-table'
import { useReportes, useEliminarReporte } from '../../hooks/useReportes'
import { DataTable } from '../../components/DataTable'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { Button } from '../../components/ui/button'
import { Badge } from '../../components/ui/badge'
import { Select } from '../../components/ui/select'
import { Input } from '../../components/ui/input'
import { PageTabs } from '../../components/ui/PageTabs'
import { Eye, Trash2 } from 'lucide-react'
import type { Reporte } from '../../types/reporte'

const TIENDAS = [
  'PUNDA50', 'PUNDA11', 'PUNSC01', 'PUNDA23',
  'TACDA13', 'TACDA17', 'TACDA21', 'TACDA25', 'TACDA27', 'TACDA30',
]

type EstadoVariant = 'default' | 'warning' | 'success' | 'outline'
const estadoVariant: Record<Reporte['estado'], EstadoVariant> = {
  borrador: 'outline',
  enviado:  'default',
  editado:  'warning',
  aprobado: 'success',
}

function getColumns(
  onEliminar: (r: Reporte) => void,
  eliminando: boolean,
): ColumnDef<Reporte>[] {
  return [
    {
      accessorKey: 'fecha',
      header: 'Fecha',
      cell: ({ row }) => row.original.fecha?.slice(0, 10) ?? '—',
    },
    { accessorKey: 'tienda_id', header: 'Tienda' },
    {
      accessorKey: 'estado',
      header: 'Estado',
      cell: ({ row }) => (
        <Badge variant={estadoVariant[row.original.estado]}>{row.original.estado}</Badge>
      ),
    },
    {
      accessorKey: 'total_calculado',
      header: 'Total ventas',
      cell: ({ row }) => `S/ ${parseFloat(row.original.total_calculado).toFixed(2)}`,
    },
    {
      accessorKey: 'efectivo_entregado',
      header: 'Efectivo',
      cell: ({ row }) => `S/ ${parseFloat(row.original.efectivo_entregado).toFixed(2)}`,
    },
    {
      accessorKey: 'diferencia',
      header: 'Diferencia',
      cell: ({ row }) => {
        const diff = parseFloat(row.original.diferencia)
        return (
          <span className={diff !== 0 ? 'text-kyro-danger font-medium' : 'text-kyro-success'}>
            S/ {diff.toFixed(2)}
          </span>
        )
      },
    },
    {
      accessorKey: 'ventas_count',
      header: 'Ventas',
      cell: ({ row }) => row.original.ventas_count ?? 0,
    },
    {
      id: 'acciones',
      header: '',
      cell: ({ row }) => (
        <div className="flex items-center gap-1">
          <Link to={`/reportes/${row.original.id}`} className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-transparent text-kyro-muted transition-all hover:border-cyan-500/40 hover:bg-cyan-500/10 hover:text-cyan-600 dark:hover:text-cyan-400" title="Ver detalle">
            <Eye size={13} />
          </Link>
          {row.original.estado !== 'aprobado' && (
            <button onClick={() => onEliminar(row.original)} disabled={eliminando} className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-transparent text-kyro-muted transition-all hover:border-red-500/40 hover:bg-red-500/10 hover:text-red-600 dark:hover:text-red-400 disabled:opacity-40 disabled:pointer-events-none" title="Eliminar reporte">
              <Trash2 size={13} />
            </button>
          )}
        </div>
      ),
    },
  ]
}

export function ReportesPage() {
  const [tienda, setTienda]         = useState('')
  const [estado, setEstado]         = useState('')
  const [fechaDesde, setFechaDesde] = useState('')
  const [fechaHasta, setFechaHasta] = useState('')
  const [pagination, setPagination] = useState<PaginationState>({ pageIndex: 0, pageSize: 20 })

  const { data, isLoading } = useReportes({
    tienda:      tienda || undefined,
    estado:      estado || undefined,
    fecha_desde: fechaDesde || undefined,
    fecha_hasta: fechaHasta || undefined,
    page:        pagination.pageIndex + 1,
    per_page:    pagination.pageSize,
  })

  const eliminar = useEliminarReporte()

  const handleEliminar = (r: Reporte) => {
    if (!confirm(`¿Eliminar el reporte del ${r.fecha?.slice(0, 10)} - ${r.tienda_id}?`)) return
    eliminar.mutate(r.id)
  }

  const limpiar = () => {
    setTienda('')
    setEstado('')
    setFechaDesde('')
    setFechaHasta('')
    setPagination((p) => ({ ...p, pageIndex: 0 }))
  }

  const hayFiltros = tienda || estado || fechaDesde || fechaHasta
  const columns = getColumns(
    handleEliminar,
    eliminar.isPending,
  )

  return (
    <div className="space-y-6">
      <PageHeader
        title="Reportes Diarios"
        description="Historial de reportes de caja por tienda."
        actions={<Link to="/reportes/nuevo" className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-kyro-gold px-4 text-sm font-semibold text-[#1a1a1a] shadow-sm transition-all hover:opacity-90">+ Nuevo reporte</Link>}
      />

      <PageTabs
        tabs={[
          { id: '', label: 'Todos' },
          { id: 'borrador', label: 'Borrador' },
          { id: 'enviado', label: 'Enviado' },
          { id: 'editado', label: 'Editado' },
          { id: 'aprobado', label: 'Aprobado' },
        ]}
        active={estado}
        onChange={(id) => { setEstado(id); setPagination((p) => ({ ...p, pageIndex: 0 })) }}
        className="mb-4"
      />

      <ListToolbar description="Acota el historial por tienda o rango de fechas.">
        <Select
          value={tienda}
          onChange={(e) => { setTienda(e.target.value); setPagination((p) => ({ ...p, pageIndex: 0 })) }}
          className="w-40"
        >
          <option value="">Todas las tiendas</option>
          {TIENDAS.map((t) => <option key={t} value={t}>{t}</option>)}
        </Select>

        <div className="flex items-center gap-1">
          <Input
            type="date"
            value={fechaDesde}
            onChange={(e) => { setFechaDesde(e.target.value); setPagination((p) => ({ ...p, pageIndex: 0 })) }}
            className="w-36"
          />
          <span className="text-kyro-subtle text-sm">–</span>
          <Input
            type="date"
            value={fechaHasta}
            onChange={(e) => { setFechaHasta(e.target.value); setPagination((p) => ({ ...p, pageIndex: 0 })) }}
            className="w-36"
          />
        </div>

        {hayFiltros && (
          <Button variant="ghost" onClick={limpiar}>Limpiar filtros</Button>
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
    </div>
  )
}
