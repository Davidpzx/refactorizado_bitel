import { useState } from 'react'
import { Link } from 'react-router-dom'
import type { ColumnDef, PaginationState } from '@tanstack/react-table'
import { useAgentes, useEliminarAgente } from '../../hooks/useAgentes'
import { DataTable } from '../../components/DataTable'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { Dialog } from '../../components/ui/dialog'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { AgenteForm } from './AgenteForm'
import type { Agente } from '../../types/agente'
import { Download, Eye, Pencil, Plus, Search, Trash2, UserRound } from 'lucide-react'
import { useAuth } from '../../hooks/useAuth'
import { api } from '../../services/api'

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
      cell: ({ row }) => (
        <span className="font-mono text-xs font-semibold tabular-nums text-kyro-body">
          S/ {parseFloat(row.original.sueldo_base).toFixed(2)}
        </span>
      ),
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
    {
      accessorKey: 'fecha_ingreso',
      header: 'Ingreso',
      cell: ({ row }) => (
        <span className="text-xs tabular-nums text-kyro-muted">
          {new Date(`${row.original.fecha_ingreso}T00:00:00`).toLocaleDateString('es-PE')}
        </span>
      ),
    },
    {
      id: 'acciones',
      header: '',
      cell: ({ row }) => (
        <div className="flex items-center gap-1">
          <Link to={`/agentes/${row.original.id}`} className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-transparent text-kyro-muted transition-all hover:border-cyan-500/40 hover:bg-cyan-500/10 hover:text-cyan-600 dark:hover:text-cyan-400" title="Ver perfil">
            <Eye size={13} />
          </Link>
          <button onClick={() => onEditar(row.original)} className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-transparent text-kyro-muted transition-all hover:border-amber-500/40 hover:bg-amber-500/10 hover:text-amber-600 dark:hover:text-amber-400" title="Editar agente">
            <Pencil size={13} />
          </button>
          <button onClick={() => onEliminar(row.original)} disabled={eliminando} className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-transparent text-kyro-muted transition-all hover:border-red-500/40 hover:bg-red-500/10 hover:text-red-600 dark:hover:text-red-400 disabled:opacity-40 disabled:pointer-events-none" title="Eliminar agente">
            <Trash2 size={13} />
          </button>
        </div>
      ),
    },
  ]
}

async function descargarFicha() {
  const res = await api.get('/v1/agentes/exportar-ficha', { responseType: 'blob' })
  const url = URL.createObjectURL(res.data as Blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'ficha_tecnica_personal.xlsx'
  a.click()
  URL.revokeObjectURL(url)
}

export function AgentesPage() {
  const { usuario }                 = useAuth()
  const isAdmin                     = usuario?.rol === 'admin'
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
    <div className="space-y-6">
      <PageHeader
        title="Agentes"
        description="Gestión del personal de ventas registrado en el sistema."
        actions={
          <div className="flex gap-2">
            {isAdmin && (
              <Button variant="outline" onClick={descargarFicha}>
                <Download size={15} /> Ficha técnica
              </Button>
            )}
            <Button onClick={abrirCrear}><Plus size={15} /> Nuevo agente</Button>
          </div>
        }
      />

      <ListToolbar description="Busca por documento o nombre del agente.">
        <div className="relative w-full sm:max-w-xs">
          <Search size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-kyro-subtle" />
          <Input
            placeholder="Buscar por DNI o nombre..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') buscar() }}
            className="pl-9"
          />
        </div>
        <Button variant="outline" onClick={buscar}><Search size={14} /> Buscar</Button>
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
        title={editando ? 'Editar agente' : 'Nuevo agente'}
        maxWidth="xl"
      >
        <div className="kyro-card mb-5 flex items-center gap-3 px-4 py-3">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-kyro bg-kyro-indigo text-kyro-text">
            <UserRound size={17} />
          </span>
          <div>
            <p className="text-sm font-semibold text-kyro-text">
              {editando ? editando.nombres : 'Datos del nuevo agente'}
            </p>
            <p className="text-xs text-kyro-muted">
              Completa la información personal, laboral y de acceso.
            </p>
          </div>
        </div>
        <AgenteForm agente={editando} onSuccess={cerrar} onCancel={cerrar} />
      </Dialog>
    </div>
  )
}
