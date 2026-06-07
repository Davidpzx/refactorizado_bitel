import { useState } from 'react'
import { createColumnHelper } from '@tanstack/react-table'
import type { PaginationState } from '@tanstack/react-table'
import { useClientes, useEliminarCliente } from '../../hooks/useClientes'
import { DataTable } from '../../components/DataTable'
import { PageHeader } from '../../components/PageHeader'
import { Dialog } from '../../components/ui/dialog'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { ClienteForm } from './ClienteForm'
import type { Cliente } from '../../types/cliente'

const col = createColumnHelper<Cliente>()

const tipoDocBadge = (tipo: Cliente['tipo_documento']) => {
  const map = { DNI: 'default', RUC: 'warning', CE: 'outline', PAS: 'outline' } as const
  return <Badge variant={map[tipo] ?? 'default'}>{tipo}</Badge>
}

const columns = [
  col.accessor('dni_ruc', { header: 'DNI / RUC' }),
  col.accessor('nombre',  { header: 'Nombre' }),
  col.accessor('tipo_documento', {
    header: 'Tipo',
    cell: (info) => tipoDocBadge(info.getValue()),
  }),
  col.accessor('telefono', {
    header: 'Teléfono',
    cell: (info) => info.getValue() ?? '—',
  }),
  col.accessor('creado_en', {
    header: 'Registrado',
    cell: (info) => info.getValue()?.slice(0, 10) ?? '—',
  }),
]

export function ClientesPage() {
  const [search, setSearch]     = useState('')
  const [query, setQuery]       = useState('')
  const [pagination, setPagination] = useState<PaginationState>({ pageIndex: 0, pageSize: 20 })
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editando, setEditando]  = useState<Cliente | undefined>()

  const { data, isLoading } = useClientes({
    q:        query || undefined,
    page:     pagination.pageIndex + 1,
    per_page: pagination.pageSize,
  })

  const eliminar = useEliminarCliente()

  const abrirCrear = () => { setEditando(undefined); setDialogOpen(true) }
  const abrirEditar = (cliente: Cliente) => { setEditando(cliente); setDialogOpen(true) }
  const cerrar = () => setDialogOpen(false)

  const handleEliminar = (cliente: Cliente) => {
    if (!confirm(`¿Eliminar al cliente ${cliente.nombre}?`)) return
    eliminar.mutate(cliente.id)
  }

  const columnasFinal = [
    ...columns,
    {
      id: 'acciones',
      header: 'Acciones',
      cell: ({ row }: { row: { original: Cliente } }) => (
        <div className="flex items-center gap-2">
          <Button size="sm" variant="outline" onClick={() => abrirEditar(row.original)}>
            Editar
          </Button>
          <Button
            size="sm"
            variant="destructive"
            onClick={() => handleEliminar(row.original)}
            disabled={eliminar.isPending}
          >
            Eliminar
          </Button>
        </div>
      ),
    },
  ]

  return (
    <div>
      <PageHeader
        title="Clientes"
        description="Base de clientes del sistema CRM."
        actions={
          <Button onClick={abrirCrear}>+ Nuevo cliente</Button>
        }
      />

      <div className="flex items-center gap-3 mb-4">
        <Input
          placeholder="Buscar por DNI/RUC o nombre..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              setQuery(search)
              setPagination((p) => ({ ...p, pageIndex: 0 }))
            }
          }}
          className="max-w-xs"
        />
        <Button
          variant="outline"
          onClick={() => { setQuery(search); setPagination((p) => ({ ...p, pageIndex: 0 })) }}
        >
          Buscar
        </Button>
        {query && (
          <Button
            variant="ghost"
            onClick={() => { setSearch(''); setQuery(''); setPagination((p) => ({ ...p, pageIndex: 0 })) }}
          >
            Limpiar
          </Button>
        )}
      </div>

      <DataTable
        data={data?.data ?? []}
        columns={columnasFinal}
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
        <ClienteForm
          cliente={editando}
          onSuccess={() => { cerrar() }}
          onCancel={cerrar}
        />
      </Dialog>
    </div>
  )
}
