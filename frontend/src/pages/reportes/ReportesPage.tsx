import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import type { ColumnDef, PaginationState } from '@tanstack/react-table'
import { useReportes, useEliminarReporte } from '../../hooks/useReportes'
import { DataTable } from '../../components/DataTable'
import { PageHeader } from '../../components/PageHeader'
import { Button } from '../../components/ui/button'
import { Badge } from '../../components/ui/badge'
import { Select } from '../../components/ui/select'
import { Input } from '../../components/ui/input'
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
  onVer: (r: Reporte) => void,
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
          <span className={diff !== 0 ? 'text-red-500 font-medium' : 'text-green-600'}>
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
      header: 'Acciones',
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          <Button size="sm" variant="outline" onClick={() => onVer(row.original)}>
            Ver
          </Button>
          {row.original.estado !== 'aprobado' && (
            <Button
              size="sm"
              variant="destructive"
              onClick={() => onEliminar(row.original)}
              disabled={eliminando}
            >
              Eliminar
            </Button>
          )}
        </div>
      ),
    },
  ]
}

export function ReportesPage() {
  const navigate = useNavigate()
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
    (r) => navigate(`/reportes/${r.id}`),
    handleEliminar,
    eliminar.isPending,
  )

  return (
    <div>
      <PageHeader
        title="Reportes Diarios"
        description="Historial de reportes de caja por tienda."
        actions={
          <Button onClick={() => navigate('/reportes/nuevo')}>+ Nuevo reporte</Button>
        }
      />

      <div className="flex flex-wrap items-end gap-3 mb-4">
        <Select
          value={tienda}
          onChange={(e) => { setTienda(e.target.value); setPagination((p) => ({ ...p, pageIndex: 0 })) }}
          className="w-40"
        >
          <option value="">Todas las tiendas</option>
          {TIENDAS.map((t) => <option key={t} value={t}>{t}</option>)}
        </Select>

        <Select
          value={estado}
          onChange={(e) => { setEstado(e.target.value); setPagination((p) => ({ ...p, pageIndex: 0 })) }}
          className="w-36"
        >
          <option value="">Todos los estados</option>
          <option value="borrador">Borrador</option>
          <option value="enviado">Enviado</option>
          <option value="editado">Editado</option>
          <option value="aprobado">Aprobado</option>
        </Select>

        <div className="flex items-center gap-1">
          <Input
            type="date"
            value={fechaDesde}
            onChange={(e) => { setFechaDesde(e.target.value); setPagination((p) => ({ ...p, pageIndex: 0 })) }}
            className="w-36"
          />
          <span className="text-gray-400 text-sm">–</span>
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
    </div>
  )
}
