import { useState } from 'react'
import { Link } from 'react-router-dom'
import type { ColumnDef, PaginationState } from '@tanstack/react-table'
import { useAgentes, useEliminarAgente } from '../../hooks/useAgentes'
import { DataTable } from '../../components/DataTable'
import { PageHeader } from '../../components/PageHeader'
import { Dialog } from '../../components/ui/dialog'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { AgenteForm } from './AgenteForm'
import type { Agente } from '../../types/agente'

type BadgeVariant = 'success' | 'warning' | 'destructive'
const estadoVariant: Record<Agente['estado'], BadgeVariant> = {
  ACTIVO:   'success',
  INACTIVO: 'warning',
  BAJA:     'destructive',
}

function getColumns(
  onEditar: (a: Agente) => void,
  onEliminar: (a: Agente) => void,
  eliminando: boolean,
): ColumnDef<Agente>[] {
  return [
    { accessorKey: 'dni',         header: 'DNI' },
    { accessorKey: 'nombres',     header: 'Nombres' },
    { accessorKey: 'tienda_base', header: 'Tienda' },
    {
      accessorKey: 'sueldo_base',
      header: 'Sueldo',
      cell: ({ row }) => `S/ ${parseFloat(row.original.sueldo_base).toFixed(2)}`,
    },
    {
      accessorKey: 'estado',
      header: 'Estado',
      cell: ({ row }) => (
        <Badge variant={estadoVariant[row.original.estado]}>
          {row.original.estado}
        </Badge>
      ),
    },
    { accessorKey: 'fecha_ingreso', header: 'Ingreso' },
    {
      id: 'acciones',
      header: 'Acciones',
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          <Link
            to={`/agentes/${row.original.id}`}
            className="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 h-8 px-3 text-xs font-medium transition-colors"
          >
            Ver
          </Link>
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

export function AgentesPage() {
  const [search, setSearch]         = useState('')
  const [query, setQuery]           = useState('')
  const [pagination, setPagination] = useState<PaginationState>({ pageIndex: 0, pageSize: 20 })
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editando, setEditando]     = useState<Agente | undefined>()

  const { data, isLoading } = useAgentes({
    q:        query || undefined,
    page:     pagination.pageIndex + 1,
    per_page: pagination.pageSize,
  })

  const eliminar = useEliminarAgente()

  const abrirCrear  = () => { setEditando(undefined); setDialogOpen(true) }
  const abrirEditar = (a: Agente) => { setEditando(a); setDialogOpen(true) }
  const cerrar      = () => setDialogOpen(false)

  const handleEliminar = (a: Agente) => {
    if (!confirm(`¿Eliminar al agente ${a.nombres}?`)) return
    eliminar.mutate(a.id)
  }

  const buscar = () => {
    setQuery(search)
    setPagination((p) => ({ ...p, pageIndex: 0 }))
  }

  const limpiar = () => {
    setSearch('')
    setQuery('')
    setPagination((p) => ({ ...p, pageIndex: 0 }))
  }

  const columns = getColumns(abrirEditar, handleEliminar, eliminar.isPending)

  return (
    <div>
      <PageHeader
        title="Agentes"
        description="Gestión del personal de ventas registrado en el sistema."
        actions={<Button onClick={abrirCrear}>+ Nuevo agente</Button>}
      />

      <div className="flex items-center gap-3 mb-4">
        <Input
          placeholder="Buscar por DNI o nombre..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') buscar() }}
          className="max-w-xs"
        />
        <Button variant="outline" onClick={buscar}>Buscar</Button>
        {query && <Button variant="ghost" onClick={limpiar}>Limpiar</Button>}
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
        title={editando ? 'Editar agente' : 'Nuevo agente'}
        maxWidth="lg"
      >
        <AgenteForm agente={editando} onSuccess={cerrar} onCancel={cerrar} />
      </Dialog>
    </div>
  )
}
