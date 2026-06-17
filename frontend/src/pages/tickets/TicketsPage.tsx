import { useState, useMemo } from 'react'
import type { ColumnDef, PaginationState } from '@tanstack/react-table'
import { Pencil, Printer, Trash2 } from 'lucide-react'
import { useTickets, useCrearTicket, useActualizarTicket } from '../../hooks/useTickets'
import { useAuth } from '../../hooks/useAuth'
import { DataTable } from '../../components/DataTable'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { Dialog } from '../../components/ui/dialog'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Select } from '../../components/ui/select'
import type { Ticket, TicketPayload, TicketUpdatePayload } from '../../types/ticket'

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

const FORMA_PAGO_OPCIONES = ['EFECTIVO', 'YAPE', 'BIPAY', 'PLIN', 'TRANSFERENCIA', 'MIXTO']

const FORMA_PAGO_COLORS: Record<string, string> = {
  EFECTIVO:      'bg-kyro-success/15 text-kyro-success',
  YAPE:          'bg-kyro-indigo/20 text-kyro-text',
  BIPAY:         'bg-kyro-info/15 text-kyro-info',
  PLIN:          'bg-kyro-warning/15 text-kyro-warning',
  TRANSFERENCIA: 'bg-kyro-info/15 text-kyro-info',
  MIXTO:         'bg-kyro-elevated text-kyro-body',
}

function padTicket(id: number) {
  return String(id).padStart(6, '0')
}

function detectFormaPago(t: Ticket): string {
  const activos = [t.efectivo, t.yape, t.bipay, t.plin].filter(v => v && parseFloat(v) > 0)
  if (activos.length > 1) return 'MIXTO'
  if (t.efectivo && parseFloat(t.efectivo) > 0) return 'EFECTIVO'
  if (t.yape     && parseFloat(t.yape)     > 0) return 'YAPE'
  if (t.bipay    && parseFloat(t.bipay)    > 0) return 'BIPAY'
  if (t.plin     && parseFloat(t.plin)     > 0) return 'PLIN'
  return '—'
}

function formaPagoDetalle(t: Ticket) {
  const partes: string[] = []
  if (t.efectivo && parseFloat(t.efectivo) > 0) partes.push(`Efect. S/${parseFloat(t.efectivo).toFixed(2)}`)
  if (t.yape     && parseFloat(t.yape)     > 0) partes.push(`Yape S/${parseFloat(t.yape).toFixed(2)}`)
  if (t.bipay    && parseFloat(t.bipay)    > 0) partes.push(`Bipay S/${parseFloat(t.bipay).toFixed(2)}`)
  if (t.plin     && parseFloat(t.plin)     > 0) partes.push(`Plin S/${parseFloat(t.plin).toFixed(2)}`)
  return partes.length ? partes.join(' + ') : '—'
}

function FormaPagoBadge({ ticket }: { ticket: Ticket }) {
  const tipo = detectFormaPago(ticket)
  const color = FORMA_PAGO_COLORS[tipo] ?? 'bg-kyro-elevated text-kyro-body'
  const detalle = formaPagoDetalle(ticket)
  return (
    <div className="flex flex-col gap-0.5">
      {tipo !== '—' && (
        <span className={`inline-block px-1.5 py-0.5 rounded text-xs font-semibold ${color}`}>{tipo}</span>
      )}
      <span className="text-xs text-kyro-muted">{detalle}</span>
    </div>
  )
}

function getColumns(
  onEditar: (t: Ticket) => void,
  onReimprimir: (t: Ticket) => void,
  onAnular: (t: Ticket) => void,
  isAdmin: boolean,
  isAnulando: boolean,
): ColumnDef<Ticket>[] {
  return [
    {
      accessorKey: 'id',
      header: 'Ticket #',
      cell: ({ row }) => <span className="font-mono text-xs">{padTicket(row.original.id)}</span>,
    },
    { accessorKey: 'tienda_id', header: 'Tienda' },
    { accessorKey: 'vendedor',  header: 'Vendedor' },
    {
      accessorKey: 'nombre_cliente',
      header: 'Cliente',
      cell: ({ row }) => (
        <div>
          <div>{row.original.nombre_cliente ?? '—'}</div>
          {row.original.dni_cliente && (
            <div className="text-xs text-kyro-subtle">DNI: {row.original.dni_cliente}</div>
          )}
        </div>
      ),
    },
    {
      accessorKey: 'descripcion',
      header: 'Descripción',
      cell: ({ row }) => (
        <span className="max-w-[180px] truncate block text-xs" title={row.original.descripcion}>
          {row.original.descripcion}
        </span>
      ),
    },
    {
      accessorKey: 'monto',
      header: 'Monto',
      cell: ({ row }) => `S/ ${parseFloat(row.original.monto).toFixed(2)}`,
    },
    {
      id: 'forma_pago',
      header: 'Forma de pago',
      cell: ({ row }) => <FormaPagoBadge ticket={row.original} />,
    },
    {
      accessorKey: 'created_at',
      header: 'Fecha',
      cell: ({ row }) => row.original.created_at?.slice(0, 10) ?? '—',
    },
    {
      id: 'acciones',
      header: '',
      cell: ({ row }) => (
        <div className="flex items-center gap-1">
          <button onClick={() => onEditar(row.original)} className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-transparent text-kyro-muted transition-all hover:border-amber-500/40 hover:bg-amber-500/10 hover:text-amber-600 dark:hover:text-amber-400" title="Editar ticket">
            <Pencil size={13} />
          </button>
          <button onClick={() => onReimprimir(row.original)} className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-transparent text-kyro-muted transition-all hover:border-cyan-500/40 hover:bg-cyan-500/10 hover:text-cyan-600 dark:hover:text-cyan-400" title="Reimprimir ticket">
            <Printer size={13} />
          </button>
          {isAdmin && (
            <button onClick={() => onAnular(row.original)} disabled={isAnulando} className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-transparent text-kyro-muted transition-all hover:border-red-500/40 hover:bg-red-500/10 hover:text-red-600 dark:hover:text-red-400 disabled:opacity-40 disabled:pointer-events-none" title="Anular ticket">
              <Trash2 size={13} />
            </button>
          )}
        </div>
      ),
    },
  ]
}

function NuevoTicketForm({ onSuccess, onCancel }: { onSuccess: () => void; onCancel: () => void }) {
  const crear = useCrearTicket()

  const [tienda_id, setTiendaId]           = useState('')
  const [vendedor, setVendedor]            = useState('')
  const [descripcion, setDescripcion]      = useState('')
  const [monto, setMonto]                  = useState('')
  const [cantidad, setCantidad]            = useState('1')
  const [nombre_cliente, setNombreCliente] = useState('')
  const [dni_cliente, setDniCliente]       = useState('')
  const [telefono, setTelefono]            = useState('')
  const [efectivo, setEfectivo]            = useState('')
  const [yape, setYape]                    = useState('')
  const [bipay, setBipay]                  = useState('')
  const [plin, setPlin]                    = useState('')

  const totalPago = useMemo(() => {
    return (parseFloat(efectivo) || 0) + (parseFloat(yape) || 0) + (parseFloat(bipay) || 0) + (parseFloat(plin) || 0)
  }, [efectivo, yape, bipay, plin])

  const montoNum = parseFloat(monto) || 0
  const vuelto   = totalPago - montoNum

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    const payload: TicketPayload = {
      tienda_id,
      vendedor,
      descripcion,
      monto:    parseFloat(monto),
      cantidad: parseInt(cantidad, 10),
      nombre_cliente: nombre_cliente || undefined,
      dni_cliente:    dni_cliente    || undefined,
      telefono:       telefono       || undefined,
      efectivo: parseFloat(efectivo) || undefined,
      yape:     parseFloat(yape)     || undefined,
      bipay:    parseFloat(bipay)    || undefined,
      plin:     parseFloat(plin)     || undefined,
    }
    crear.mutate(payload, { onSuccess })
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4 max-h-[70vh] overflow-y-auto pr-1">
      <div className="grid grid-cols-2 gap-4">
        <div>
          <label className="block text-xs text-kyro-muted mb-1">Tienda *</label>
          <Select value={tienda_id} onChange={(e) => setTiendaId(e.target.value)} required>
            <option value="">Seleccionar tienda</option>
            {TIENDAS.map((t) => (
              <option key={t.codigo} value={t.codigo}>{t.nombre}</option>
            ))}
          </Select>
        </div>
        <div>
          <label className="block text-xs text-kyro-muted mb-1">Vendedor *</label>
          <Input value={vendedor} onChange={(e) => setVendedor(e.target.value)} required placeholder="Nombre del vendedor" />
        </div>
      </div>

      <div>
        <label className="block text-xs text-kyro-muted mb-1">Descripción *</label>
        <Input value={descripcion} onChange={(e) => setDescripcion(e.target.value)} required placeholder="Descripción del ticket" />
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div>
          <label className="block text-xs text-kyro-muted mb-1">Monto (S/) *</label>
          <Input type="number" step="0.01" min="0" value={monto} onChange={(e) => setMonto(e.target.value)} required placeholder="0.00" />
        </div>
        <div>
          <label className="block text-xs text-kyro-muted mb-1">Cantidad *</label>
          <Input type="number" min="1" value={cantidad} onChange={(e) => setCantidad(e.target.value)} required />
        </div>
      </div>

      <div className="grid grid-cols-3 gap-4">
        <div>
          <label className="block text-xs text-kyro-muted mb-1">Nombre cliente</label>
          <Input value={nombre_cliente} onChange={(e) => setNombreCliente(e.target.value)} placeholder="Opcional" />
        </div>
        <div>
          <label className="block text-xs text-kyro-muted mb-1">DNI cliente</label>
          <Input value={dni_cliente} onChange={(e) => setDniCliente(e.target.value)} placeholder="Opcional" maxLength={8} />
        </div>
        <div>
          <label className="block text-xs text-kyro-muted mb-1">Teléfono</label>
          <Input value={telefono} onChange={(e) => setTelefono(e.target.value)} placeholder="Opcional" />
        </div>
      </div>

      <div>
        <h4 className="text-xs font-semibold uppercase text-kyro-subtle tracking-wider mb-2">Forma de pago</h4>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-xs text-kyro-muted mb-1">Efectivo (S/)</label>
            <Input type="number" step="0.01" min="0" value={efectivo} onChange={(e) => setEfectivo(e.target.value)} placeholder="0.00" />
          </div>
          <div>
            <label className="block text-xs text-kyro-muted mb-1">Yape (S/)</label>
            <Input type="number" step="0.01" min="0" value={yape} onChange={(e) => setYape(e.target.value)} placeholder="0.00" />
          </div>
          <div>
            <label className="block text-xs text-kyro-muted mb-1">Bipay (S/)</label>
            <Input type="number" step="0.01" min="0" value={bipay} onChange={(e) => setBipay(e.target.value)} placeholder="0.00" />
          </div>
          <div>
            <label className="block text-xs text-kyro-muted mb-1">Plin (S/)</label>
            <Input type="number" step="0.01" min="0" value={plin} onChange={(e) => setPlin(e.target.value)} placeholder="0.00" />
          </div>
        </div>
        {totalPago > 0 && (
          <div className="mt-3 flex items-center gap-6 text-sm">
            <span className="text-kyro-muted">Total recibido: <strong className="text-kyro-text">S/ {totalPago.toFixed(2)}</strong></span>
            <span className={vuelto >= 0 ? 'text-kyro-success' : 'text-kyro-danger'}>
              Vuelto: <strong>S/ {vuelto.toFixed(2)}</strong>
            </span>
          </div>
        )}
      </div>

      <div className="flex justify-end gap-2 pt-2 border-t border-kyro-border">
        <Button type="button" variant="outline" onClick={onCancel}>Cancelar</Button>
        <Button type="submit" disabled={crear.isPending}>
          {crear.isPending ? 'Guardando...' : 'Crear ticket'}
        </Button>
      </div>
    </form>
  )
}

function EditarTicketForm({ ticket, onSuccess, onCancel }: { ticket: Ticket; onSuccess: () => void; onCancel: () => void }) {
  const actualizar = useActualizarTicket()

  const [nombre_cliente, setNombreCliente] = useState(ticket.nombre_cliente ?? '')
  const [telefono, setTelefono]            = useState(ticket.telefono ?? '')
  const [efectivo, setEfectivo]            = useState(ticket.efectivo ?? '')
  const [yape, setYape]                    = useState(ticket.yape ?? '')
  const [bipay, setBipay]                  = useState(ticket.bipay ?? '')
  const [plin, setPlin]                    = useState(ticket.plin ?? '')

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    const data: TicketUpdatePayload = {
      nombre_cliente: nombre_cliente || undefined,
      telefono:       telefono       || undefined,
      efectivo: parseFloat(String(efectivo)) || undefined,
      yape:     parseFloat(String(yape))     || undefined,
      bipay:    parseFloat(String(bipay))    || undefined,
      plin:     parseFloat(String(plin))     || undefined,
    }
    actualizar.mutate({ id: ticket.id, data }, { onSuccess })
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="grid grid-cols-2 gap-4">
        <div>
          <label className="block text-xs text-kyro-muted mb-1">Nombre cliente</label>
          <Input value={nombre_cliente} onChange={(e) => setNombreCliente(e.target.value)} />
        </div>
        <div>
          <label className="block text-xs text-kyro-muted mb-1">Teléfono</label>
          <Input value={telefono} onChange={(e) => setTelefono(e.target.value)} />
        </div>
      </div>
      <div className="grid grid-cols-2 gap-4">
        <div>
          <label className="block text-xs text-kyro-muted mb-1">Efectivo (S/)</label>
          <Input type="number" step="0.01" min="0" value={efectivo} onChange={(e) => setEfectivo(e.target.value)} />
        </div>
        <div>
          <label className="block text-xs text-kyro-muted mb-1">Yape (S/)</label>
          <Input type="number" step="0.01" min="0" value={yape} onChange={(e) => setYape(e.target.value)} />
        </div>
        <div>
          <label className="block text-xs text-kyro-muted mb-1">Bipay (S/)</label>
          <Input type="number" step="0.01" min="0" value={bipay} onChange={(e) => setBipay(e.target.value)} />
        </div>
        <div>
          <label className="block text-xs text-kyro-muted mb-1">Plin (S/)</label>
          <Input type="number" step="0.01" min="0" value={plin} onChange={(e) => setPlin(e.target.value)} />
        </div>
      </div>
      <div className="flex justify-end gap-2 pt-2 border-t border-kyro-border">
        <Button type="button" variant="outline" onClick={onCancel}>Cancelar</Button>
        <Button type="submit" disabled={actualizar.isPending}>
          {actualizar.isPending ? 'Guardando...' : 'Guardar cambios'}
        </Button>
      </div>
    </form>
  )
}

export function TicketsPage() {
  const { usuario }                        = useAuth()
  const isAdmin                            = usuario?.rol === 'admin'
  const [desde, setDesde]                  = useState('')
  const [hasta, setHasta]                  = useState('')
  const [tienda_id, setTiendaId]           = useState('')
  const [q, setQ]                          = useState('')
  const [dniCliente, setDniCliente]        = useState('')
  const [formaPago, setFormaPago]          = useState('')
  const [pagination, setPagination]        = useState<PaginationState>({ pageIndex: 0, pageSize: 20 })
  const [dialogOpen, setDialogOpen]        = useState(false)
  const [editando, setEditando]            = useState<Ticket | undefined>()
  const [modoNuevo, setModoNuevo]          = useState(false)

  const anular = useActualizarTicket()

  const { data, isLoading } = useTickets({
    desde:       desde       || undefined,
    hasta:       hasta       || undefined,
    tienda_id:   tienda_id   || undefined,
    q:           q           || undefined,
    dni_cliente: dniCliente  || undefined,
    forma_pago:  formaPago   || undefined,
    page:        pagination.pageIndex + 1,
    per_page:    pagination.pageSize,
  })

  const abrirNuevo  = () => { setEditando(undefined); setModoNuevo(true); setDialogOpen(true) }
  const abrirEditar = (t: Ticket) => { setEditando(t); setModoNuevo(false); setDialogOpen(true) }
  const cerrar      = () => setDialogOpen(false)

  const handleReimprimir = (t: Ticket) => {
    window.open(`/tickets/imprimir/${t.id}?print=1`, '_blank')
  }

  const handleAnular = (t: Ticket) => {
    if (!window.confirm(`¿Anular el ticket #${padTicket(t.id)}? Esta acción no se puede deshacer.`)) return
    anular.mutate({ id: t.id, data: { estado: 'ANULADO' } })
  }

  const limpiarFiltros = () => {
    setDesde(''); setHasta(''); setTiendaId('')
    setQ(''); setDniCliente(''); setFormaPago('')
    setPagination((p) => ({ ...p, pageIndex: 0 }))
  }

  const hayFiltros = desde || hasta || tienda_id || q || dniCliente || formaPago

  const columns = getColumns(abrirEditar, handleReimprimir, handleAnular, isAdmin, anular.isPending)

  return (
    <div className="space-y-6">
      <PageHeader
        title="Tickets"
        description="Gestión de tickets emitidos."
        actions={<Button onClick={abrirNuevo}>+ Nuevo ticket</Button>}
      />

      <ListToolbar description="Combina fechas, tienda, cliente y forma de pago.">
        <div className="flex items-center gap-2">
          <label className="text-xs text-kyro-muted">Desde</label>
          <Input type="date" value={desde} onChange={(e) => { setDesde(e.target.value); setPagination(p => ({ ...p, pageIndex: 0 })) }} className="w-36" />
        </div>
        <div className="flex items-center gap-2">
          <label className="text-xs text-kyro-muted">Hasta</label>
          <Input type="date" value={hasta} onChange={(e) => { setHasta(e.target.value); setPagination(p => ({ ...p, pageIndex: 0 })) }} className="w-36" />
        </div>
        <Select value={tienda_id} onChange={(e) => { setTiendaId(e.target.value); setPagination(p => ({ ...p, pageIndex: 0 })) }} className="w-44">
          <option value="">Todas las tiendas</option>
          {TIENDAS.map((t) => (
            <option key={t.codigo} value={t.codigo}>{t.nombre}</option>
          ))}
        </Select>
        <Input
          value={q}
          onChange={(e) => { setQ(e.target.value); setPagination(p => ({ ...p, pageIndex: 0 })) }}
          placeholder="Buscar descripción..."
          className="w-44"
        />
        <Input
          value={dniCliente}
          onChange={(e) => { setDniCliente(e.target.value); setPagination(p => ({ ...p, pageIndex: 0 })) }}
          placeholder="Cliente / DNI..."
          className="w-36"
          maxLength={20}
        />
        <Select value={formaPago} onChange={(e) => { setFormaPago(e.target.value); setPagination(p => ({ ...p, pageIndex: 0 })) }} className="w-40">
          <option value="">Forma de pago</option>
          {FORMA_PAGO_OPCIONES.map(fp => (
            <option key={fp} value={fp}>{fp}</option>
          ))}
        </Select>
        {hayFiltros && (
          <Button variant="ghost" onClick={limpiarFiltros}>Limpiar</Button>
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

      <Dialog
        open={dialogOpen}
        onClose={cerrar}
        title={modoNuevo ? 'Nuevo ticket' : `Editar ticket #${editando ? padTicket(editando.id) : ''}`}
        maxWidth="lg"
      >
        {modoNuevo ? (
          <NuevoTicketForm onSuccess={cerrar} onCancel={cerrar} />
        ) : editando ? (
          <EditarTicketForm ticket={editando} onSuccess={cerrar} onCancel={cerrar} />
        ) : null}
      </Dialog>
    </div>
  )
}
