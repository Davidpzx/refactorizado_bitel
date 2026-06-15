import { useState } from 'react'
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
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { Select } from '../../components/ui/select'
import { Badge } from '../../components/ui/badge'
import type { Traslado, EstadoTraslado } from '../../types/traslados'

const TIENDAS = [
  'PUNDA50', 'PUNDA11', 'PUNSC01', 'PUNDA23',
  'TACDA13', 'TACDA17', 'TACDA21', 'TACDA25', 'TACDA27', 'TACDA30',
]

type BadgeVariant = 'default' | 'warning' | 'success' | 'destructive' | 'outline'

const estadoBadge: Record<EstadoTraslado, BadgeVariant> = {
  PENDIENTE:             'default',
  PENDIENTE_APROBACION:  'warning',
  CONFIRMADO:            'success',
  RECHAZADO:             'destructive',
  CANCELADO:             'outline',
}

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

function CrearTrasladoDialog({
  open,
  onClose,
}: {
  open: boolean
  onClose: () => void
}) {
  const crear = useCrearTraslado()
  const { register, handleSubmit, reset, formState: { errors } } = useForm<CrearForm>({
    resolver: zodResolver(crearSchema),
    defaultValues: { cantidad: 1 },
  })

  const onSubmit = (data: CrearForm) => {
    const payload = {
      ...data,
      auth_agente_id: data.auth_agente_id || undefined,
      notas: data.notas || undefined,
    }
    crear.mutate(payload, {
      onSuccess: () => { reset(); onClose() },
    })
  }

  const mutError = crear.error as { response?: { data?: { message?: string } } } | null

  return (
    <Dialog open={open} onClose={onClose} title="Nuevo Traslado" maxWidth="md">
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <Label htmlFor="tienda_destino">Tienda destino *</Label>
            <Select id="tienda_destino" {...register('tienda_destino')} className="mt-1">
              <option value="">Selecciona tienda</option>
              {TIENDAS.map((t) => (
                <option key={t} value={t}>{t}</option>
              ))}
            </Select>
            {errors.tienda_destino && <p className="text-kyro-danger text-xs mt-1">{errors.tienda_destino.message}</p>}
          </div>
          <div>
            <Label htmlFor="producto_id">ID Producto *</Label>
            <Input
              id="producto_id"
              type="number"
              min="1"
              {...register('producto_id', { valueAsNumber: true })}
              className="mt-1"
            />
            {errors.producto_id && <p className="text-kyro-danger text-xs mt-1">{errors.producto_id.message}</p>}
          </div>
        </div>

        <div>
          <Label htmlFor="cantidad">Cantidad *</Label>
          <Input
            id="cantidad"
            type="number"
            min="1"
            {...register('cantidad', { valueAsNumber: true })}
            className="mt-1"
          />
          {errors.cantidad && <p className="text-kyro-danger text-xs mt-1">{errors.cantidad.message}</p>}
        </div>

        <div>
          <Label htmlFor="notas">Notas</Label>
          <textarea
            id="notas"
            {...register('notas')}
            rows={2}
            className="kyro-input mt-1 w-full"
          />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <Label htmlFor="auth_dni">DNI autorización *</Label>
            <Input
              id="auth_dni"
              {...register('auth_dni')}
              placeholder="12345678"
              className="mt-1"
            />
            {errors.auth_dni && <p className="text-kyro-danger text-xs mt-1">{errors.auth_dni.message}</p>}
          </div>
          <div>
            <Label htmlFor="auth_agente_id">ID Agente autoriza</Label>
            <Input
              id="auth_agente_id"
              type="number"
              min="1"
              {...register('auth_agente_id', { valueAsNumber: true })}
              className="mt-1"
            />
          </div>
        </div>

        {mutError && (
          <p className="text-kyro-danger text-sm">
            {mutError.response?.data?.message ?? 'Error al crear traslado.'}
          </p>
        )}

        <div className="flex gap-3 pt-2">
          <Button type="submit" disabled={crear.isPending} className="flex-1">
            {crear.isPending ? 'Creando...' : 'Crear Traslado'}
          </Button>
          <Button type="button" variant="outline" onClick={onClose} disabled={crear.isPending}>
            Cancelar
          </Button>
        </div>
      </form>
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
            <Label htmlFor="c_agente">ID Agente</Label>
            <Input
              id="c_agente"
              type="number"
              min="1"
              {...register('auth_agente_id', { valueAsNumber: true })}
              className="mt-1"
            />
          </div>
        </div>

        {mutError && (
          <p className="text-kyro-danger text-sm">
            {mutError.response?.data?.message ?? 'Error al confirmar.'}
          </p>
        )}

        <div className="flex gap-3 pt-2">
          <Button type="submit" disabled={isPending} className="flex-1">
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

        if (!puedeConfirmar && !puedeAprobarRechazar && !puedeCancelar) return null

        return (
          <div className="flex items-center gap-2 flex-wrap">
            {puedeConfirmar && (
              <Button size="sm" variant="outline" onClick={() => onConfirmar(t)}>
                Confirmar
              </Button>
            )}
            {puedeConfirmar && t.codigo_lote && (
              <Button size="sm" onClick={() => onConfirmarLote(t)}>
                Confirmar lote
              </Button>
            )}
            {puedeAprobarRechazar && (
              <>
                <Button
                  size="sm"
                  onClick={() => onGestionar(t.id, 'aprobar')}
                  disabled={gestionando}
                  className="bg-kyro-success text-kyro-text hover:brightness-110"
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
                variant="outline"
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

  const columns = getColumns(
    usuario ? { rol: usuario.rol, tienda_id: usuario.tienda_id } : null,
    setTrasladoConfirmar,
    setLoteConfirmar,
    handleGestionar,
    gestionar.isPending,
  )

  return (
    <div>
      <PageHeader
        title="Traslados de Equipos/Accesorios"
        description="Gestión de traslados de inventario entre tiendas."
        actions={<Button onClick={() => setDialogCrear(true)}>+ Nuevo Traslado</Button>}
      />

      <ListToolbar description="Consulta traslados por estado, origen y destino.">
        <Select
          value={estado}
          onChange={(e) => {
            setEstado(e.target.value)
            setPagination((p) => ({ ...p, pageIndex: 0 }))
          }}
          className="w-48"
        >
          <option value="">Todos los estados</option>
          <option value="PENDIENTE">Pendiente</option>
          <option value="PENDIENTE_APROBACION">Pendiente Aprobación</option>
          <option value="CONFIRMADO">Confirmado</option>
          <option value="RECHAZADO">Rechazado</option>
          <option value="CANCELADO">Cancelado</option>
        </Select>

        <Select
          value={origen}
          onChange={(e) => {
            setOrigen(e.target.value)
            setPagination((p) => ({ ...p, pageIndex: 0 }))
          }}
          className="w-44"
        >
          <option value="">Todas las tiendas origen</option>
          {TIENDAS.map((t) => (
            <option key={t} value={t}>{t}</option>
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
          {TIENDAS.map((t) => (
            <option key={t} value={t}>{t}</option>
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
