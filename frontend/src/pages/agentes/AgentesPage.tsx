import { useState } from 'react'
import { createColumnHelper } from '@tanstack/react-table'
import type { PaginationState } from '@tanstack/react-table'
import { useAgentes, useEliminarAgente } from '../../hooks/useAgentes'
import { DataTable } from '../../components/DataTable'
import { PageHeader } from '../../components/PageHeader'
import { Dialog } from '../../components/ui/dialog'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { AgenteForm } from './AgenteForm'
import type { Agente } from '../../types/agente'

const col = createColumnHelper<Agente>()

const estadoBadge = (estado: Agente['estado']) => {
  const map = { ACTIVO: 'success', INACTIVO: 'warning', BAJA: 'destructive' } as const
  return <Badge variant={map[estado]}>{estado}</Badge>
}

const columns = [
  col.accessor('dni',        { header: 'DNI' }),
  col.accessor('nombres',    { header: 'Nombres' }),
  col.accessor('tienda_base',{ header: 'Tienda' }),
  col.accessor('sueldo_base',{
    header: 'Sueldo',
    cell: (info) => `S/ ${parseFloat(info.getValue()).toFixed(2)}`,
  }),
  col.accessor('estado', {
    header: 'Estado',
    cell: (info) => estadoBadge(info.getValue()),
  }),
  col.accessor('fecha_ingreso', { header: 'Ingreso' }),
  col.display({
    id: 'acciones',
    header: 'Acciones',
    cell: () => null, // se reemplaza en AgentesPage con acceso al row
  }),
]

export function AgentesPage() {
  const [search, setSearch]     = useState('')
  const [query, setQuery]       = useState('')
  const [pagination, setPagination] = useState<PaginationState>({ pageIndex: 0, pageSize: 20 })
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editando, setEditando]  = useState<Agente | undefined>()

  const { data, isLoading } = useAgentes({
    q:        query || undefined,
    page:     pagination.pageIndex + 1,
    per_page: pagination.pageSize,
  })

  const eliminar = useEliminarAgente()

  const abrirCrear = () => { setEditando(undefined); setDialogOpen(true) }
  const abrirEditar = (agente: Agente) => { setEditando(agente); setDialogOpen(true) }
  const cerrar = () => setDialogOpen(false)

  const handleEliminar = (agente: Agente) => {
    if (!confirm(`¿Eliminar al agente ${agente.nombres}?`)) return
    eliminar.mutate(agente.id)
  }

  const columnasFinal = [
    ...columns.slice(0, 6),
    {
      id: 'acciones',
      header: 'Acciones',
      cell: ({ row }: { row: { original: Agente } }) => (
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
        title="Agentes"
        description="Gestión del personal de ventas registrado en el sistema."
        actions={
          <Button onClick={abrirCrear}>+ Nuevo agente</Button>
        }
      />

      <div className="flex items-center gap-3 mb-4">
        <Input
          placeholder="Buscar por DNI o nombre..."
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
        title={editando ? 'Editar agente' : 'Nuevo agente'}
        maxWidth="lg"
      >
        <AgenteForm
          agente={editando}
          onSuccess={() => { cerrar() }}
          onCancel={cerrar}
        />
      </Dialog>
    </div>
  )
}
