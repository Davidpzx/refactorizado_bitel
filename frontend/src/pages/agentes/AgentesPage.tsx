import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import type { ColumnDef, PaginationState } from '@tanstack/react-table'
import { useAgentes, useEliminarAgente } from '../../hooks/useAgentes'
import { DataTable } from '../../components/DataTable'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { Dialog } from '../../components/ui/dialog'
import { ActionIconButton, TableActions } from '../../components/ui/ActionIconButton'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { AgenteForm } from './AgenteForm'
import { AgenteSeguridadDialog } from './AgenteSeguridadDialog'
import type { Agente } from '../../types/agente'
import { Check, Copy, DownloadSimple as Download, Eye, FileText, Keyhole as KeyRound, PencilSimple as Pencil, Plus, MagnifyingGlass as Search, Trash as Trash2, UserCircle as UserRound, Users } from '@phosphor-icons/react'
import { Select } from '../../components/ui/select'
import { useTiendasSelect } from '../../hooks/useTiendasSelect'
import { useAuth } from '../../hooks/useAuth'
import { api } from '../../services/api'
import { useConfirmDialog } from '../../components/ui/confirm-dialog'
import { formatearFechaCorta } from '../../lib/fechas'

type BadgeVariant = 'success' | 'warning' | 'destructive'
const estadoVariant: Record<Agente['estado'], BadgeVariant> = {
  ACTIVO:   'success',
  INACTIVO: 'warning',
  BAJA:     'destructive',
}

function getColumns(
  onVer: (a: Agente) => void,
  onEditar: (a: Agente) => void,
  onEliminar: (a: Agente) => void,
  onExportarPdf: (a: Agente) => void,
  onSeguridad: (a: Agente) => void,
  eliminando: boolean,
): ColumnDef<Agente>[] {
  return [
    { accessorKey: 'dni',         header: 'DNI' },
    { accessorKey: 'nombres',     header: 'Nombres' },
    {
      accessorKey: 'tienda_base',
      header: 'Tienda',
      cell: ({ row }) => (
        <Badge variant="purple">{row.original.tienda_base}</Badge>
      ),
    },
    {
      id: 'jornada',
      header: 'Jornada y descanso',
      cell: ({ row }) => {
        const { hora_ingreso, hora_salida, dia_descanso } = row.original
        if (!hora_ingreso && !hora_salida && !dia_descanso) {
          return <span className="text-xs text-kyro-muted">—</span>
        }
        return (
          <div className="text-xs">
            <p className="font-mono tabular-nums text-kyro-body">
              {hora_ingreso?.slice(0, 5) ?? '--:--'} → {hora_salida?.slice(0, 5) ?? '--:--'}
            </p>
            <p className="text-kyro-muted">{dia_descanso ? `Libre ${dia_descanso}` : 'Sin día libre'}</p>
          </div>
        )
      },
    },
    {
      id: 'baja_retorno',
      header: 'Baja / retorno',
      cell: ({ row }) => {
        const { fecha_retorno, motivo_baja } = row.original
        if (!fecha_retorno && !motivo_baja) {
          return <span className="text-xs text-kyro-muted">—</span>
        }
        return (
          <div className="text-xs">
            {motivo_baja && <p className="font-medium text-kyro-danger">{motivo_baja}</p>}
            {fecha_retorno && <p className="text-kyro-muted">Retorno: {formatearFechaCorta(fecha_retorno)}</p>}
          </div>
        )
      },
    },
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
          {formatearFechaCorta(row.original.fecha_ingreso)}
        </span>
      ),
    },
    {
      id: 'acciones',
      header: '',
      cell: ({ row }) => (
        <TableActions>
          <ActionIconButton tone="view" label="Ver perfil" icon={<Eye size={15} />} onClick={() => onVer(row.original)} />
          <ActionIconButton tone="edit" label="Editar agente" icon={<Pencil size={15} />} onClick={() => onEditar(row.original)} />
          <ActionIconButton tone="excel" label="Exportar PDF (constancia)" icon={<FileText size={15} />} onClick={() => onExportarPdf(row.original)} />
          <ActionIconButton tone="edit" label="Token de seguridad" icon={<KeyRound size={15} />} onClick={() => onSeguridad(row.original)} />
          <ActionIconButton tone="delete" label="Eliminar agente" icon={<Trash2 size={15} />} onClick={() => onEliminar(row.original)} disabled={eliminando} />
        </TableActions>
      ),
    },
  ]
}

async function descargarBlob(path: string, filename: string) {
  const res = await api.get(path, { responseType: 'blob' })
  const url = URL.createObjectURL(res.data as Blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
}

async function descargarFicha() {
  await descargarBlob('/v1/agentes/exportar-ficha', 'ficha_tecnica_personal.xlsx')
}

async function descargarConstancia(agente: Agente) {
  const nombres = agente.nombres.replace(/\s+/g, '_')
  await descargarBlob(`/v1/constancias/agente/${agente.id}`, `certificado_${nombres}_${agente.id}.pdf`)
}

type UrlPublica = 'onboarding' | 'asistencia'

const urlsPublicas: Record<UrlPublica, string> = {
  onboarding: '/postular',
  asistencia: '/terminal',
}

export function AgentesPage() {
  const { usuario }                 = useAuth()
  const navigate                    = useNavigate()
  const isAdmin                     = usuario?.rol === 'admin'
  const [search, setSearch]         = useState('')
  const [query, setQuery]           = useState('')
  const [pagination, setPagination] = useState<PaginationState>({ pageIndex: 0, pageSize: 20 })
  const [tiendaFiltro, setTiendaFiltro] = useState('')
  const { tiendas } = useTiendasSelect()
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editando, setEditando]     = useState<Agente | undefined>()
  const [seguridadAgente, setSeguridadAgente] = useState<Agente | undefined>()
  const [urlCopiada, setUrlCopiada] = useState<UrlPublica | null>(null)

  const { data, isLoading } = useAgentes({
    q:        query || undefined,
    tienda:   tiendaFiltro || undefined,
    page:     pagination.pageIndex + 1,
    per_page: pagination.pageSize,
  })

  const eliminar = useEliminarAgente()
  const confirmDialog = useConfirmDialog()

  const abrirCrear  = () => { setEditando(undefined); setDialogOpen(true) }
  const abrirEditar = (a: Agente) => { setEditando(a); setDialogOpen(true) }
  const cerrar      = () => setDialogOpen(false)

  const handleEliminar = async (a: Agente) => {
    const ok = await confirmDialog({
      title: `¿Eliminar al agente ${a.nombres}?`,
      intent: 'danger',
      icon: Trash2,
      confirmLabel: 'Eliminar',
    })
    if (!ok) return
    eliminar.mutate(a.id)
  }

  const buscar = () => {
    setQuery(search)
    setPagination((p) => ({ ...p, pageIndex: 0 }))
  }

  const limpiar = () => {
    setSearch('')
    setQuery('')
    setTiendaFiltro('')
    setPagination((p) => ({ ...p, pageIndex: 0 }))
  }

  const copiarUrlPublica = async (tipo: UrlPublica) => {
    const url = `${window.location.origin}${urlsPublicas[tipo]}`
    await navigator.clipboard.writeText(url)
    setUrlCopiada(tipo)
    setTimeout(() => setUrlCopiada((actual) => (actual === tipo ? null : actual)), 2500)
  }

  const columns = getColumns(
    (a) => navigate(`/agentes/${a.id}`),
    abrirEditar,
    handleEliminar,
    descargarConstancia,
    setSeguridadAgente,
    eliminar.isPending,
  )

  return (
    <div className="space-y-6">
      <PageHeader
        title="Agentes"
        description="Gestión del personal de ventas registrado en el sistema."
        Icon={Users}
        actions={
          <div className="flex flex-wrap gap-2">
            <Button variant="glassWarning" onClick={() => copiarUrlPublica('onboarding')}>
              {urlCopiada === 'onboarding' ? <Check size={15} /> : <Copy size={15} />}
              {urlCopiada === 'onboarding' ? '¡Copiado!' : 'URL Registro de Datos'}
            </Button>
            <Button variant="glassInfo" onClick={() => copiarUrlPublica('asistencia')}>
              {urlCopiada === 'asistencia' ? <Check size={15} /> : <Copy size={15} />}
              {urlCopiada === 'asistencia' ? '¡Copiado!' : 'URL Asistencia'}
            </Button>
            {isAdmin && (
              <Button variant="glassSuccess" onClick={descargarFicha}>
                <Download size={15} /> Excel + Fichas
              </Button>
            )}
            <Button variant="gold" onClick={abrirCrear}><Plus size={15} /> Nuevo agente</Button>
          </div>
        }
      />

      <ListToolbar description="Busca por documento, nombre o filtra por tienda.">
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
        <Select value={tiendaFiltro} onChange={e => { setTiendaFiltro(e.target.value); setPagination(p => ({ ...p, pageIndex: 0 })) }} className="h-9 w-44">
          <option value="">Todas las tiendas</option>
          {tiendas.map(t => <option key={t.codigo} value={t.codigo}>{t.codigo} — {t.nombre}</option>)}
        </Select>
        <Button variant="gold" onClick={buscar}><Search size={14} /> Buscar</Button>
        {(query || tiendaFiltro) && <Button variant="ghost" onClick={limpiar}>Limpiar</Button>}
      </ListToolbar>

      <DataTable
        data={data?.data ?? []}
        columns={columns}
        pageCount={data?.last_page ?? 0}
        pagination={pagination}
        onPaginationChange={setPagination}
        isLoading={isLoading}
        total={data?.total}
        emptyIcon={<Users size={16} />}
        emptyLabel="Sin agentes registrados"
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

      <AgenteSeguridadDialog agente={seguridadAgente} onClose={() => setSeguridadAgente(undefined)} />
    </div>
  )
}
