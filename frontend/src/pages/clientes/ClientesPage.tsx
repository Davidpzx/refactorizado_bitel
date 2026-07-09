import { useState } from 'react'
import type { ColumnDef, PaginationState } from '@tanstack/react-table'
import { useClientes, useEliminarCliente } from '../../hooks/useClientes'
import { DataTable } from '../../components/DataTable'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { Dialog } from '../../components/ui/dialog'
import { ActionIconButton, TableActions } from '../../components/ui/ActionIconButton'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { ClienteForm } from './ClienteForm'
import type { Cliente } from '../../types/cliente'
import { Pencil, Plus, Search, Trash2, UsersRound } from 'lucide-react'
import { useConfirmDialog } from '../../components/ui/confirm-dialog'

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
    {
      accessorKey: 'dni_ruc',
      header: 'DNI / RUC',
      cell: ({ row }) => <span className="font-mono text-xs font-semibold">{row.original.dni_ruc}</span>,
    },
    {
      accessorKey: 'nombre',
      header: 'Nombre',
      cell: ({ row }) => <span className="font-medium text-kyro-body">{row.original.nombre}</span>,
    },
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
      cell: ({ row }) => row.original.creado_en
        ? new Date(`${row.original.creado_en.slice(0, 10)}T00:00:00`).toLocaleDateString('es-PE')
        : '—',
    },
    {
      id: 'acciones',
      header: '',
      cell: ({ row }) => (
        <TableActions>
          <ActionIconButton tone="edit" label="Editar cliente" icon={<Pencil size={15} />} onClick={() => onEditar(row.original)} />
          <ActionIconButton tone="delete" label="Eliminar cliente" icon={<Trash2 size={15} />} onClick={() => onEliminar(row.original)} disabled={eliminando} />
        </TableActions>
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
  const confirmDialog = useConfirmDialog()

  const abrirCrear  = () => { setEditando(undefined); setDialogOpen(true) }
  const abrirEditar = (c: Cliente) => { setEditando(c); setDialogOpen(true) }
  const cerrar      = () => setDialogOpen(false)

  const handleEliminar = async (c: Cliente) => {
    const ok = await confirmDialog({
      title: `¿Eliminar al cliente ${c.nombre}?`,
      intent: 'danger',
      icon: Trash2,
      confirmLabel: 'Eliminar',
    })
    if (!ok) return
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
    <div className="space-y-6">
      <PageHeader
        title="Clientes"
        description="Base de clientes del sistema CRM."
        actions={<Button variant="gold" onClick={abrirCrear}><Plus size={15} /> Nuevo cliente</Button>}
      />

      <ListToolbar description="Busca por documento o nombre del cliente.">
        <div className="relative w-full sm:max-w-xs">
          <Search size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-kyro-subtle" />
          <Input
            placeholder="Buscar por DNI/RUC o nombre..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') buscar() }}
            className="pl-9"
          />
        </div>
        <Button variant="gold" onClick={buscar}><Search size={14} /> Buscar</Button>
        {query && <Button variant="ghost" onClick={limpiar}>Limpiar</Button>}
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
        title={editando ? 'Editar cliente' : 'Nuevo cliente'}
        maxWidth="md"
      >
        <div className="kyro-card mb-5 flex items-center gap-3 px-4 py-3">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-kyro bg-kyro-indigo text-kyro-text">
            <UsersRound size={17} />
          </span>
          <div>
            <p className="text-sm font-semibold text-kyro-text">
              {editando ? editando.nombre : 'Datos del nuevo cliente'}
            </p>
            <p className="text-xs text-kyro-muted">
              Registra la identidad y los canales de contacto.
            </p>
          </div>
        </div>
        <ClienteForm cliente={editando} onSuccess={cerrar} onCancel={cerrar} />
      </Dialog>
    </div>
  )
}
