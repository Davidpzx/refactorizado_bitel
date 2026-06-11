import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import type { ColumnDef, PaginationState } from '@tanstack/react-table'
import {
  useTrasladosChips,
  useCrearTrasladoChip,
  useConfirmarTrasladoChip,
  useGestionarTrasladoChip,
  useStockChips,
} from '../../hooks/useTraslados'
import { useAuth } from '../../hooks/useAuth'
import { DataTable } from '../../components/DataTable'
import { PageHeader } from '../../components/PageHeader'
import { Dialog } from '../../components/ui/dialog'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { Select } from '../../components/ui/select'
import { Badge } from '../../components/ui/badge'
import type { TrasladoChip, ChipStock, EstadoTraslado } from '../../types/traslados'

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
  chip_id:        z.number({ error: 'Requerido' }).int().positive(),
  tienda_origen:  z.string().min(1, 'Requerido'),
  tienda_destino: z.string().min(1, 'Requerido'),
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

function CrearChipDialog({
  open,
  onClose,
  stockChips,
}: {
  open: boolean
  onClose: () => void
  stockChips: ChipStock[]
}) {
  const crear = useCrearTrasladoChip()
  const { register, handleSubmit, reset, formState: { errors } } = useForm<CrearForm>({
    resolver: zodResolver(crearSchema),
    defaultValues: { cantidad: 1 },
  })

  const onSubmit = (data: CrearForm) => {
    crear.mutate(
      { ...data, auth_agente_id: data.auth_agente_id || undefined, notas: data.notas || undefined },
      { onSuccess: () => { reset(); onClose() } },
    )
  }

  const mutError = crear.error as { response?: { data?: { message?: string } } } | null

  return (
    <Dialog open={open} onClose={onClose} title="Trasladar Chips" maxWidth="md">
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <Label htmlFor="chip_id">Chip *</Label>
            <Select id="chip_id" {...register('chip_id', { valueAsNumber: true })} className="mt-1">
              <option value="">Selecciona chip</option>
              {stockChips.map((c) => (
                <option key={c.id} value={c.id}>
                  #{c.id} — {c.tipo_chip} ({c.tienda_origen}) — Stock: {c.stock_actual}
                </option>
              ))}
            </Select>
            {errors.chip_id && <p className="text-red-500 text-xs mt-1">{errors.chip_id.message}</p>}
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
            {errors.cantidad && <p className="text-red-500 text-xs mt-1">{errors.cantidad.message}</p>}
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <Label htmlFor="tienda_origen">Tienda origen *</Label>
            <Select id="tienda_origen" {...register('tienda_origen')} className="mt-1">
              <option value="">Selecciona tienda</option>
              {TIENDAS.map((t) => (
                <option key={t} value={t}>{t}</option>
              ))}
            </Select>
            {errors.tienda_origen && <p className="text-red-500 text-xs mt-1">{errors.tienda_origen.message}</p>}
          </div>
          <div>
            <Label htmlFor="tienda_destino">Tienda destino *</Label>
            <Select id="tienda_destino" {...register('tienda_destino')} className="mt-1">
              <option value="">Selecciona tienda</option>
              {TIENDAS.map((t) => (
                <option key={t} value={t}>{t}</option>
              ))}
            </Select>
            {errors.tienda_destino && <p className="text-red-500 text-xs mt-1">{errors.tienda_destino.message}</p>}
          </div>
        </div>

        <div>
          <Label htmlFor="notas">Notas</Label>
          <textarea
            id="notas"
            {...register('notas')}
            rows={2}
            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <Label htmlFor="auth_dni">DNI autorización *</Label>
            <Input id="auth_dni" {...register('auth_dni')} placeholder="12345678" className="mt-1" />
            {errors.auth_dni && <p className="text-red-500 text-xs mt-1">{errors.auth_dni.message}</p>}
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
          <p className="text-red-500 text-sm">
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

function ConfirmarChipDialog({
  traslado,
  onClose,
}: {
  traslado: TrasladoChip | null
  onClose: () => void
}) {
  const confirmar = useConfirmarTrasladoChip()
  const { register, handleSubmit, reset, formState: { errors } } = useForm<ConfirmarForm>({
    resolver: zodResolver(confirmarSchema),
  })

  const onSubmit = (data: ConfirmarForm) => {
    if (!traslado) return
    confirmar.mutate(
      { id: traslado.id, data: { ...data, auth_agente_id: data.auth_agente_id || undefined } },
      { onSuccess: () => { reset(); onClose() } },
    )
  }

  const mutError = confirmar.error as { response?: { data?: { message?: string } } } | null

  return (
    <Dialog open={!!traslado} onClose={onClose} title="Confirmar Recepción de Chips" maxWidth="sm">
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
        <div>
          <Label htmlFor="cc_observacion">Observación</Label>
          <textarea
            id="cc_observacion"
            {...register('observacion')}
            rows={2}
            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <Label htmlFor="cc_auth_dni">DNI *</Label>
            <Input id="cc_auth_dni" {...register('auth_dni')} className="mt-1" />
            {errors.auth_dni && <p className="text-red-500 text-xs mt-1">{errors.auth_dni.message}</p>}
          </div>
          <div>
            <Label htmlFor="cc_agente">ID Agente</Label>
            <Input
              id="cc_agente"
              type="number"
              min="1"
              {...register('auth_agente_id', { valueAsNumber: true })}
              className="mt-1"
            />
          </div>
        </div>

        {mutError && (
          <p className="text-red-500 text-sm">
            {mutError.response?.data?.message ?? 'Error al confirmar.'}
          </p>
        )}

        <div className="flex gap-3 pt-2">
          <Button type="submit" disabled={confirmar.isPending} className="flex-1">
            {confirmar.isPending ? 'Confirmando...' : 'Confirmar Recepción'}
          </Button>
          <Button type="button" variant="outline" onClick={onClose} disabled={confirmar.isPending}>
            Cancelar
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

const stockColumns: ColumnDef<ChipStock>[] = [
  { accessorKey: 'id',           header: 'ID' },
  { accessorKey: 'tienda_id',    header: 'Tienda' },
  { accessorKey: 'tienda_origen', header: 'Tienda Origen' },
  { accessorKey: 'tipo_chip',    header: 'Tipo Chip' },
  { accessorKey: 'stock_actual', header: 'Stock Actual' },
]

function getTrasladoColumns(
  usuario: { rol: string; tienda_id: string } | null,
  onConfirmar: (t: TrasladoChip) => void,
  onGestionar: (id: number, action: 'aprobar' | 'rechazar' | 'cancelar') => void,
  gestionando: boolean,
): ColumnDef<TrasladoChip>[] {
  const isAdmin = usuario?.rol === 'admin'

  return [
    { accessorKey: 'id',             header: 'ID' },
    { accessorKey: 'chip_id',        header: 'Chip ID' },
    { accessorKey: 'tienda_origen',  header: 'Origen' },
    { accessorKey: 'tienda_destino', header: 'Destino' },
    { accessorKey: 'cantidad',       header: 'Cant.' },
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
            {puedeAprobarRechazar && (
              <>
                <Button
                  size="sm"
                  onClick={() => onGestionar(t.id, 'aprobar')}
                  disabled={gestionando}
                  className="bg-green-600 hover:bg-green-700 text-white"
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
                className="text-gray-500"
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

export function TrasladoChipsPage() {
  const { usuario } = useAuth()
  const [pagination, setPagination]             = useState<PaginationState>({ pageIndex: 0, pageSize: 20 })
  const [stockPagination, setStockPagination]   = useState<PaginationState>({ pageIndex: 0, pageSize: 10 })
  const [estado, setEstado]                     = useState('')
  const [dialogCrear, setDialogCrear]           = useState(false)
  const [trasladoConfirmar, setTrasladoConfirmar] = useState<TrasladoChip | null>(null)

  const { data, isLoading }           = useTrasladosChips({
    estado: estado || undefined,
    page:   pagination.pageIndex + 1,
    per_page: pagination.pageSize,
  })
  const { data: stockData, isLoading: stockLoading } = useStockChips()
  const gestionar = useGestionarTrasladoChip()

  const handleGestionar = (id: number, action: 'aprobar' | 'rechazar' | 'cancelar') => {
    const labels = { aprobar: 'aprobar', rechazar: 'rechazar', cancelar: 'cancelar' }
    if (!confirm(`¿Seguro que deseas ${labels[action]} este traslado?`)) return
    gestionar.mutate({ id, data: { action } })
  }

  const stockRows = stockData ?? []
  const pagedStock = stockRows.slice(
    stockPagination.pageIndex * stockPagination.pageSize,
    (stockPagination.pageIndex + 1) * stockPagination.pageSize,
  )
  const stockPageCount = Math.ceil(stockRows.length / stockPagination.pageSize)

  const trasladoColumns = getTrasladoColumns(
    usuario ? { rol: usuario.rol, tienda_id: usuario.tienda_id } : null,
    setTrasladoConfirmar,
    handleGestionar,
    gestionar.isPending,
  )

  return (
    <div className="space-y-8">
      <PageHeader
        title="Traslados de Chips"
        description="Stock de chips por tienda y gestión de traslados."
        actions={<Button onClick={() => setDialogCrear(true)}>+ Trasladar Chips</Button>}
      />

      <section>
        <h2 className="text-sm font-semibold text-gray-700 mb-3 uppercase tracking-wider">
          Stock de Chips por Tienda
        </h2>
        <DataTable
          data={pagedStock}
          columns={stockColumns}
          pageCount={stockPageCount || 1}
          pagination={stockPagination}
          onPaginationChange={setStockPagination}
          isLoading={stockLoading}
          total={stockRows.length}
        />
      </section>

      <section>
        <div className="flex flex-wrap items-center justify-between gap-3 mb-3">
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wider">
            Traslados Pendientes
          </h2>
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
        </div>
        <DataTable
          data={data?.data ?? []}
          columns={trasladoColumns}
          pageCount={data?.last_page ?? 0}
          pagination={pagination}
          onPaginationChange={setPagination}
          isLoading={isLoading}
          total={data?.total}
        />
      </section>

      <CrearChipDialog
        open={dialogCrear}
        onClose={() => setDialogCrear(false)}
        stockChips={stockData ?? []}
      />

      <ConfirmarChipDialog
        traslado={trasladoConfirmar}
        onClose={() => setTrasladoConfirmar(null)}
      />
    </div>
  )
}
