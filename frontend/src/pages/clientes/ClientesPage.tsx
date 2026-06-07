import { useState } from 'react'
import type { ColumnDef, PaginationState } from '@tanstack/react-table'
import { useClientes, useEliminarCliente } from '../../hooks/useClientes'
import { DataTable } from '../../components/DataTable'
import { PageHeader } from '../../components/PageHeader'
import { Dialog } from '../../components/ui/dialog'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { ClienteForm } from './ClienteForm'
import type { Cliente } from '../../types/cliente'

type BadgeVariant = 'default' | 'warning' | 'outline'
const tipoVariant: Record<Cliente['tipo_documento'], BadgeVariant> = {
  DNI: 'default',
  RUC: 'warning',
  CE:  'outline',
  PAS: 'outline',
}

function getColumns(
  onEditar: (c: Cliente) => void,
  onEliminar: (c: Cliente) => void,
  eliminando: boolean,
): ColumnDef<Cliente>[] {
  return [
    { accessorKey: 'dni_ruc', header: 'DNI / RUC' },
    { accessorKey: 'nombre',  header: 'Nombre' },
    {
      accessorKey: 'tipo_documento',
      header: 'Tipo',
      cell: ({ row }) => (
        <Badge variant={tipoVariant[row.original.tipo_documento]}>
          {row.original.tipo_documento}
        </Badge>
      ),
    },
    {
      accessorKey: 'telefono',
      header: 'Teléfono',
      cell: ({ row }) => row.original.telefono ?? '—',
    },
    {
      accessorKey: 'creado_en',
      header: 'Registrado',
      cell: ({ row }) => row.original.creado_en?.slice(0, 10) ?? '—',
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

export function ClientesPage() {
  const [search, setSearch]         = useState('')
  const [query, setQuery]           = useState('')
  const [pagination, setPagination] = useState<PaginationState>({ pageIndex: 0, pageSize: 20 })
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editando, setEditando]     = useState<Cliente | undefined>()

  const { data, isLoading } = useClientes({
    q:        query || undefined,
    page:     pagination.pageIndex + 1,
    per_page: pagination.pageSize,
  })

  const eliminar = useEliminarCliente()

  const abrirCrear  = () => { setEditando(undefined); setDialogOpen(true) }
  const abrirEditar = (c: Cliente) => { setEditando(c); setDialogOpen(true) }
  const cerrar      = () => setDialogOpen(false)

  const handleEliminar = (c: Cliente) => {
    if (!confirm(`¿Eliminar al cliente ${c.nombre}?`)) return
    eliminar.mutate(c.id)
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
        title="Clientes"
        description="Base de clientes del sistema CRM."
        actions={<Button onClick={abrirCrear}>+ Nuevo cliente</Button>}
      />

      <div className="flex items-center gap-3 mb-4">
        <Input
          placeholder="Buscar por DNI/RUC o nombre..."
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
        title={editando ? 'Editar cliente' : 'Nuevo cliente'}
        maxWidth="md"
      >
        <ClienteForm cliente={editando} onSuccess={cerrar} onCancel={cerrar} />
      </Dialog>
    </div>
  )
}
