import { useState } from 'react'
import { useQuery, useMutation } from '@tanstack/react-query'
import { useTiendasSelect } from '../../hooks/useTiendasSelect'
import { useAgentesSelect } from '../../hooks/useAgentesSelect'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import type { ColumnDef, PaginationState } from '@tanstack/react-table'
import {
  useTraslados,
  useCrearTraslado,
  useConfirmarTraslado,
  useConfirmarLoteTraslado,
  useGestionarTraslado,
} from '../../hooks/useTraslados'
import { useAuth } from '../../hooks/useAuth'
import { DataTable } from '../../components/DataTable'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { Dialog } from '../../components/ui/dialog'
import { api } from '../../services/api'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { Select } from '../../components/ui/select'
import { Badge } from '../../components/ui/badge'
import { SegmentedToggle } from '../../components/ui/SegmentedToggle'
import type { Traslado, EstadoTraslado } from '../../types/traslados'

type BadgeVariant = 'default' | 'warning' | 'success' | 'destructive' | 'outline'

const estadoBadge: Record<EstadoTraslado, BadgeVariant> = {
  PENDIENTE:             'default',
  PENDIENTE_APROBACION:  'warning',
  CONFIRMADO:            'success',
  RECHAZADO:             'destructive',
  CANCELADO:             'outline',
}

const ESTADOS = [
  { value: '', label: 'Todos', tone: 'indigo' as const },
  { value: 'PENDIENTE', label: 'Pendiente', tone: 'warning' as const },
  { value: 'PENDIENTE_APROBACION', label: 'Aprobacion', tone: 'gold' as const },
  { value: 'CONFIRMADO', label: 'Confirmado', tone: 'success' as const },
  { value: 'RECHAZADO', label: 'Rechazado', tone: 'danger' as const },
  { value: 'CANCELADO', label: 'Cancelado', tone: 'danger' as const },
]

const crearSchema = z.object({
  tienda_destino: z.string().min(1, 'Requerido'),
  producto_id:    z.number({ error: 'Requerido' }).int().positive(),
  cantidad:       z.number({ error: 'Requerido' }).int().min(1),
  notas:          z.string().optional(),
  auth_dni:       z.string().min(1, 'Requerido'),
  auth_agente_id: z.number().int().positive().optional(),
})

const confirmarSchema = z.object({
  observacion:    z.string().optional(),
  auth_dni:       z.string().min(1, 'Requerido'),
  auth_agente_id: z.number().int().positive().optional(),
})

type CrearForm = z.infer<typeof crearSchema>
type ConfirmarForm = z.infer<typeof confirmarSchema>

interface ChipOption {
  id: number
  tienda_origen: string
  tipo_chip: string
  stock_actual: number
  tienda?: { codigo: string; nombre: string } | null
}

function CrearTrasladoDialog({
  open,
  onClose,
}: {
  open: boolean
  onClose: () => void
}) {
  const crear = useCrearTraslado()
  const { tiendas } = useTiendasSelect()
  const { agentes } = useAgentesSelect()
  const [tipoItem, setTipoItem] = useState<'inventario' | 'chip'>('inventario')

  // ── Inventario (equipos/accesorios) ──────────────────────────────────────
  const { data: inventarioData } = useQuery({
    queryKey: ['inventario-disponible'],
    queryFn: () => api.get('/v1/inventario', { params: { estado: 'DISPONIBLE', per_page: 500 } }).then(r => r.data),
    enabled: open,
    staleTime: 60_000,
  })
  const inventario: Array<{ id: number; producto_nombre: string; tipo: string; imei_serial: string | null; cantidad: number }> =
    Array.isArray(inventarioData) ? inventarioData : (inventarioData?.data ?? [])

  // ── Chips ─────────────────────────────────────────────────────────────────
  const { data: chipsData } = useQuery<{ data: ChipOption[] }>({
    queryKey: ['chips'],
    queryFn: () => api.get<{ data: ChipOption[] }>('\/v1\/chips').then(r => r.data),
    enabled: open && tipoItem === 'chip',
    staleTime: 60_000,
  })
  const chips = chipsData?.data ?? []

  // ── Form inventario ───────────────────────────────────────────────────────
  const { register, handleSubmit, reset, formState: { errors } } = useForm<CrearForm>({
    resolver: zodResolver(crearSchema),
    defaultValues: { cantidad: 1 },
  })

  // ── Form chip (estado simple) ─────────────────────────────────────────────
  const [chipForm, setChipForm] = useState({ chip_id: '', tienda_destino: '', cantidad: '1', notas: '', auth_dni: '', auth_agente_id: '' })
  const [chipError, setChipError] = useState('')
  const crearChip = useMutation({
    mutationFn: (body: object) => api.post('/v1/traslados-chips', body).then(r => r.data),
    onSuccess: () => { resetAll(); onClose() },
    onError: (e: unknown) => {
      const err = e as { response?: { data?: { message?: string } } }
      setChipError(err?.response?.data?.message ?? 'Error al crear traslado de chips.')
    },
  })

  const resetAll = () => {
    reset()
    setChipForm({ chip_id: '', tienda_destino: '', cantidad: '1', notas: '', auth_dni: '', auth_agente_id: '' })
    setChipError('')
    setTipoItem('inventario')
  }

  const onSubmitInventario = (data: CrearForm) => {
    crear.mutate(
      { ...data, auth_agente_id: data.auth_agente_id || undefined, notas: data.notas || undefined },
      { onSuccess: () => { resetAll(); onClose() } },
    )
  }

  const handleSubmitChip = () => {
    setChipError('')
    if (!chipForm.chip_id)       return setChipError('Selecciona un lote de chips.')
    if (!chipForm.tienda_destino) return setChipError('Selecciona tienda destino.')
    if (Number(chipForm.cantidad) < 1) return setChipError('Cantidad inválida.')
    if (!chipForm.auth_dni)      return setChipError('DNI de autorización requerido.')
    const chip = chips.find(c => String(c.id) === chipForm.chip_id)
    crearChip.mutate({
      chip_id:        Number(chipForm.chip_id),
      tienda_origen:  chip?.tienda?.codigo ?? '',
      tienda_destino: chipForm.tienda_destino,
      cantidad:       Number(chipForm.cantidad),
      notas:          chipForm.notas || undefined,
      auth_dni:       chipForm.auth_dni,
      auth_agente_id: chipForm.auth_agente_id ? Number(chipForm.auth_agente_id) : undefined,
    })
  }

  const mutError = crear.error as { response?: { data?: { message?: string } } } | null

  return (
    <Dialog open={open} onClose={() => { resetAll(); onClose() }} title="Nuevo Traslado" maxWidth="md">
      {/* Toggle tipo */}
      <div className="flex gap-2 mb-5">
        <Button type="button" size="sm" variant={tipoItem === 'inventario' ? 'gold' : 'outline'} onClick={() => setTipoItem('inventario')}>
          Equipo / Accesorio
        </Button>
        <Button type="button" size="sm" variant={tipoItem === 'chip' ? 'gold' : 'outline'} onClick={() => setTipoItem('chip')}>
          Chips
        </Button>
      </div>

      {tipoItem === 'inventario' ? (
        <form onSubmit={handleSubmit(onSubmitInventario)} className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label htmlFor="tienda_destino">Tienda destino *</Label>
              <Select id="tienda_destino" {...register('tienda_destino')} className="mt-1">
                <option value="">Selecciona tienda</option>
                {tiendas.map(t => <option key={t.codigo} value={t.codigo}>{t.codigo} — {t.nombre}</option>)}
              </Select>
              {errors.tienda_destino && <p className="text-kyro-danger text-xs mt-1">{errors.tienda_destino.message}</p>}
            </div>
            <div>
              <Label htmlFor="producto_id">Producto *</Label>
              <Select id="producto_id" {...register('producto_id', { valueAsNumber: true })} className="mt-1">
                <option value="">Selecciona producto</option>
                {inventario.map(p => (
                  <option key={p.id} value={p.id}>
                    {p.producto_nombre}{p.imei_serial ? ` — ${p.imei_serial}` : p.cantidad > 1 ? ` (×${p.cantidad})` : ''}
                  </option>
                ))}
              </Select>
              {errors.producto_id && <p className="text-kyro-danger text-xs mt-1">{errors.producto_id.message}</p>}
            </div>
          </div>
          <div>
            <Label htmlFor="cantidad">Cantidad *</Label>
            <Input id="cantidad" type="number" min="1" {...register('cantidad', { valueAsNumber: true })} className="mt-1" />
            {errors.cantidad && <p className="text-kyro-danger text-xs mt-1">{errors.cantidad.message}</p>}
          </div>
          <div>
            <Label htmlFor="notas">Notas</Label>
            <textarea id="notas" {...register('notas')} rows={2} className="kyro-input mt-1 w-full" />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label htmlFor="auth_dni">DNI autorización *</Label>
              <Input id="auth_dni" {...register('auth_dni')} placeholder="12345678" className="mt-1" />
              {errors.auth_dni && <p className="text-kyro-danger text-xs mt-1">{errors.auth_dni.message}</p>}
            </div>
            <div>
              <Label htmlFor="auth_agente_id">Agente autoriza</Label>
              <Select id="auth_agente_id" {...register('auth_agente_id', { valueAsNumber: true })} className="mt-1">
                <option value="">Ninguno</option>
                {agentes.map(a => <option key={a.id} value={a.id}>{a.nombres}</option>)}
              </Select>
            </div>
          </div>
          {mutError && <p className="text-kyro-danger text-sm">{mutError.response?.data?.message ?? 'Error al crear traslado.'}</p>}
          <div className="flex gap-3 pt-2">
            <Button type="submit" variant="gold" disabled={crear.isPending} className="flex-1">
              {crear.isPending ? 'Creando...' : 'Crear Traslado'}
            </Button>
            <Button type="button" variant="outline" onClick={() => { resetAll(); onClose() }} disabled={crear.isPending}>Cancelar</Button>
          </div>
        </form>
      ) : (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label>Tienda destino *</Label>
              <Select className="mt-1" value={chipForm.tienda_destino}
                onChange={e => setChipForm(f => ({ ...f, tienda_destino: e.target.value }))}>
                <option value="">Selecciona tienda</option>
                {tiendas.map(t => <option key={t.codigo} value={t.codigo}>{t.codigo} — {t.nombre}</option>)}
              </Select>
            </div>
            <div>
              <Label>Lote de chips *</Label>
              <Select className="mt-1" value={chipForm.chip_id}
                onChange={e => setChipForm(f => ({ ...f, chip_id: e.target.value }))}>
                <option value="">Selecciona lote</option>
                {chips.map(c => (
                  <option key={c.id} value={String(c.id)}>
                    {c.tienda_origen} — {c.tipo_chip} (stock: {c.stock_actual})
                  </option>
                ))}
              </Select>
            </div>
          </div>
          <div>
            <Label>Cantidad *</Label>
            <Input type="number" min="1" className="mt-1" value={chipForm.cantidad}
              onChange={e => setChipForm(f => ({ ...f, cantidad: e.target.value }))} />
          </div>
          <div>
            <Label>Notas</Label>
            <textarea rows={2} className="kyro-input mt-1 w-full" value={chipForm.notas}
              onChange={e => setChipForm(f => ({ ...f, notas: e.target.value }))} />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label>DNI autorización *</Label>
              <Input placeholder="12345678" className="mt-1" value={chipForm.auth_dni}
                onChange={e => setChipForm(f => ({ ...f, auth_dni: e.target.value }))} />
            </div>
            <div>
              <Label>Agente autoriza</Label>
              <Select className="mt-1" value={chipForm.auth_agente_id}
                onChange={e => setChipForm(f => ({ ...f, auth_agente_id: e.target.value }))}>
                <option value="">Ninguno</option>
                {agentes.map(a => <option key={a.id} value={String(a.id)}>{a.nombres}</option>)}
              </Select>
            </div>
          </div>
          {chipError && <p className="text-kyro-danger text-sm">{chipError}</p>}
          <div className="flex gap-3 pt-2">
            <Button variant="gold" onClick={handleSubmitChip} disabled={crearChip.isPending} className="flex-1">
              {crearChip.isPending ? 'Creando...' : 'Crear Traslado'}
            </Button>
            <Button variant="outline" onClick={() => { resetAll(); onClose() }} disabled={crearChip.isPending}>Cancelar</Button>
          </div>
        </div>
      )}
    </Dialog>
  )
}

function ConfirmarDialog({
  traslado,
  lote = false,
  onClose,
}: {
  traslado: Traslado | null
  lote?: boolean
  onClose: () => void
}) {
  const confirmar = useConfirmarTraslado()
  const confirmarLote = useConfirmarLoteTraslado()
  const { agentes } = useAgentesSelect()
  const { register, handleSubmit, reset, formState: { errors } } = useForm<ConfirmarForm>({
    resolver: zodResolver(confirmarSchema),
  })

  const onSubmit = (data: ConfirmarForm) => {
    if (!traslado) return
    if (lote && traslado.codigo_lote) {
      confirmarLote.mutate(
        { codigoLote: traslado.codigo_lote, data: { ...data, auth_agente_id: data.auth_agente_id || undefined } },
        { onSuccess: () => { reset(); onClose() } },
      )
      return
    }
    confirmar.mutate(
      { id: traslado.id, data: { ...data, auth_agente_id: data.auth_agente_id || undefined } },
      { onSuccess: () => { reset(); onClose() } },
    )
  }

  const mutError = (confirmar.error || confirmarLote.error) as { response?: { data?: { message?: string } } } | null
  const isPending = confirmar.isPending || confirmarLote.isPending

  return (
    <Dialog open={!!traslado} onClose={onClose} title="Confirmar Recepción" maxWidth="sm">
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
        <div>
          <Label htmlFor="c_observacion">Observación</Label>
          <textarea
            id="c_observacion"
            {...register('observacion')}
            rows={2}
            className="kyro-input mt-1 w-full"
          />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <Label htmlFor="c_auth_dni">DNI *</Label>
            <Input id="c_auth_dni" {...register('auth_dni')} className="mt-1" />
            {errors.auth_dni && <p className="text-kyro-danger text-xs mt-1">{errors.auth_dni.message}</p>}
          </div>
          <div>
            <Label htmlFor="c_agente">Agente</Label>
            <Select id="c_agente" {...register('auth_agente_id', { valueAsNumber: true })} className="mt-1">
              <option value="">Ninguno</option>
              {agentes.map(a => <option key={a.id} value={a.id}>{a.nombres}</option>)}
            </Select>
          </div>
        </div>

        {mutError && (
          <p className="text-kyro-danger text-sm">
            {mutError.response?.data?.message ?? 'Error al confirmar.'}
          </p>
        )}

        <div className="flex gap-3 pt-2">
          <Button type="submit" variant="gold" disabled={isPending} className="flex-1">
            {confirmar.isPending ? 'Confirmando...' : 'Confirmar Recepción'}
          </Button>
          <Button type="button" variant="outline" onClick={onClose} disabled={isPending}>
            Cancelar
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function getColumns(
  usuario: { rol: string; tienda_id: string } | null,
  onConfirmar: (t: Traslado) => void,
  onConfirmarLote: (t: Traslado) => void,
  onGestionar: (id: number, action: 'aprobar' | 'rechazar' | 'cancelar') => void,
  gestionando: boolean,
  onConstancia: (t: Traslado) => void,
): ColumnDef<Traslado>[] {
  const isAdmin = usuario?.rol === 'admin'

  return [
    { accessorKey: 'id', header: 'ID' },
    { accessorKey: 'producto_nombre_snap', header: 'Producto' },
    { accessorKey: 'tienda_origen', header: 'Origen' },
    { accessorKey: 'tienda_destino', header: 'Destino' },
    { accessorKey: 'cantidad', header: 'Cant.' },
    {
      accessorKey: 'estado',
      header: 'Estado',
      cell: ({ row }) => (
        <Badge variant={estadoBadge[row.original.estado]}>{row.original.estado}</Badge>
      ),
    },
    {
      accessorKey: 'created_at',
      header: 'Fecha',
      cell: ({ row }) => row.original.created_at?.slice(0, 10) ?? '—',
    },
    {
      id: 'acciones',
      header: 'Acciones',
      cell: ({ row }) => {
        const t = row.original
        const puedeConfirmar =
          t.estado === 'PENDIENTE' &&
          (isAdmin || t.tienda_destino === usuario?.tienda_id)
        const puedeAprobarRechazar = t.estado === 'PENDIENTE_APROBACION' && isAdmin
        const puedeCancelar =
          isAdmin &&
          (t.estado === 'PENDIENTE' || t.estado === 'PENDIENTE_APROBACION')

        const esConfirmado = t.estado === 'CONFIRMADO'
        if (!puedeConfirmar && !puedeAprobarRechazar && !puedeCancelar && !esConfirmado) return null

        return (
          <div className="flex items-center gap-2 flex-wrap">
            {esConfirmado && (
              <Button size="sm" variant="glassInfo" onClick={() => onConstancia(t)}>
                Constancia
              </Button>
            )}
            {puedeConfirmar && (
              <Button size="sm" variant="gold" onClick={() => onConfirmar(t)}>
                Confirmar
              </Button>
            )}
            {puedeConfirmar && t.codigo_lote && (
              <Button size="sm" variant="gold" onClick={() => onConfirmarLote(t)}>
                Confirmar lote
              </Button>
            )}
            {puedeAprobarRechazar && (
              <>
                <Button
                  size="sm"
                  variant="gold"
                  onClick={() => onGestionar(t.id, 'aprobar')}
                  disabled={gestionando}
                >
                  Aprobar
                </Button>
                <Button
                  size="sm"
                  variant="destructive"
                  onClick={() => onGestionar(t.id, 'rechazar')}
                  disabled={gestionando}
                >
                  Rechazar
                </Button>
              </>
            )}
            {puedeCancelar && (
              <Button
                size="sm"
                variant="ghost"
                onClick={() => onGestionar(t.id, 'cancelar')}
                disabled={gestionando}
                className="text-kyro-muted"
              >
                Cancelar
              </Button>
            )}
          </div>
        )
      },
    },
  ]
}

export function TrasladosPage() {
  const { usuario } = useAuth()
  const { tiendas: tiendasFiltro } = useTiendasSelect()
  const [pagination, setPagination] = useState<PaginationState>({ pageIndex: 0, pageSize: 20 })
  const [estado, setEstado]         = useState('')
  const [origen, setOrigen]         = useState('')
  const [destino, setDestino]       = useState('')
  const [dialogCrear, setDialogCrear]       = useState(false)
  const [trasladoConfirmar, setTrasladoConfirmar] = useState<Traslado | null>(null)
  const [loteConfirmar, setLoteConfirmar] = useState<Traslado | null>(null)

  const { data, isLoading } = useTraslados({
    estado:         estado   || undefined,
    tienda_origen:  origen   || undefined,
    tienda_destino: destino  || undefined,
    page:           pagination.pageIndex + 1,
    per_page:       pagination.pageSize,
  })

  const gestionar = useGestionarTraslado()

  const handleGestionar = (id: number, action: 'aprobar' | 'rechazar' | 'cancelar') => {
    const labels = { aprobar: 'aprobar', rechazar: 'rechazar', cancelar: 'cancelar' }
    if (!confirm(`¿Seguro que deseas ${labels[action]} este traslado?`)) return
    gestionar.mutate({ id, data: { action } })
  }

  const limpiarFiltros = () => {
    setEstado('')
    setOrigen('')
    setDestino('')
    setPagination((p) => ({ ...p, pageIndex: 0 }))
  }

  const hayFiltros = estado || origen || destino

  function descargarConstancia(t: Traslado) {
    const token = localStorage.getItem('auth_token')
    const base  = (api.defaults.baseURL ?? '').replace(/\/$/, '')
    const params = t.codigo_lote
      ? `tipo=equipos&lote=${encodeURIComponent(t.codigo_lote)}`
      : `tipo=equipos&id=${t.id}`
    const url = `${base}/v1/constancias/traslado?${params}`
    const a = document.createElement('a')
    a.href = url
    a.setAttribute('download', `constancia_traslado_${t.id}.pdf`)
    // Use fetch to attach auth header
    fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      .then(r => r.blob())
      .then(blob => {
        a.href = URL.createObjectURL(blob)
        a.click()
        URL.revokeObjectURL(a.href)
      })
  }

  const columns = getColumns(
    usuario ? { rol: usuario.rol, tienda_id: usuario.tienda_id } : null,
    setTrasladoConfirmar,
    setLoteConfirmar,
    handleGestionar,
    gestionar.isPending,
    descargarConstancia,
  )

  return (
    <div className="space-y-6">
      <PageHeader
        title="Traslados de Equipos/Accesorios"
        description="Gestión de traslados de inventario entre tiendas."
        actions={<Button variant="gold" onClick={() => setDialogCrear(true)}>+ Nuevo Traslado</Button>}
      />

      <ListToolbar description="Consulta traslados por estado, origen y destino.">
        <SegmentedToggle
          ariaLabel="Filtrar traslados por estado"
          size="sm"
          options={ESTADOS}
          value={estado}
          onChange={(value) => {
            setEstado(value)
            setPagination((p) => ({ ...p, pageIndex: 0 }))
          }}
        />

        <Select
          value={origen}
          onChange={(e) => {
            setOrigen(e.target.value)
            setPagination((p) => ({ ...p, pageIndex: 0 }))
          }}
          className="w-44"
        >
          <option value="">Todas las tiendas origen</option>
          {tiendasFiltro.map(t => (
            <option key={t.codigo} value={t.codigo}>{t.codigo} — {t.nombre}</option>
          ))}
        </Select>

        <Select
          value={destino}
          onChange={(e) => {
            setDestino(e.target.value)
            setPagination((p) => ({ ...p, pageIndex: 0 }))
          }}
          className="w-44"
        >
          <option value="">Todas las tiendas destino</option>
          {tiendasFiltro.map(t => (
            <option key={t.codigo} value={t.codigo}>{t.codigo} — {t.nombre}</option>
          ))}
        </Select>

        {hayFiltros && (
          <Button variant="ghost" onClick={limpiarFiltros}>Limpiar filtros</Button>
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

      <CrearTrasladoDialog open={dialogCrear} onClose={() => setDialogCrear(false)} />

      <ConfirmarDialog
        traslado={trasladoConfirmar}
        onClose={() => setTrasladoConfirmar(null)}
      />
      <ConfirmarDialog
        traslado={loteConfirmar}
        lote
        onClose={() => setLoteConfirmar(null)}
      />
    </div>
  )
}
