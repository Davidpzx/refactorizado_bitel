import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery } from '@tanstack/react-query'
import { useCrearInventario, useActualizarInventario } from '../../hooks/useInventario'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { Select } from '../../components/ui/select'
import { useAuth } from '../../hooks/useAuth'
import { esAdminOGerente } from '../../utils/roles'
import { api } from '../../services/api'
import type { InventarioItem } from '../../types/inventario'

/**
 * Precio opcional: el ingreso de stock es el paso 1 del flujo de 2 pasos del legacy
 * (la tienda registra sin precio y gerencia lo fija después en "Precios pendientes").
 * El input vacío se normaliza a `undefined` en el register (setValueAs), así el
 * campo se omite del payload y el backend lo deja como precio pendiente.
 */
const precioOpcional = z.number().min(0, 'El precio no puede ser negativo').optional()

const comoNumeroOpcional = (v: unknown) =>
  v === '' || v === null || v === undefined || Number.isNaN(Number(v)) ? undefined : Number(v)

const schema = z.object({
  tienda_id:         z.string().min(1, 'La tienda es obligatoria'),
  producto_nombre:   z.string().min(1, 'El nombre del producto es obligatorio').max(150),
  tipo:              z.enum(['EQUIPO', 'ACCESORIO', 'CHIP']),
  imei_serial:       z.string().max(50).optional().or(z.literal('')),
  imei_seriales_text:z.string().optional(),
  precio_costo:      precioOpcional,
  precio_minimo:     precioOpcional,
  precio_normal:     precioOpcional,
  cantidad:          z.number().int().min(1, 'La cantidad mínima es 1'),
  estado:            z.enum(['DISPONIBLE', 'VENDIDO', 'TRASLADO']),
  comision_especial: z.number().min(0).optional(),
  dni_autoriza:      z.string().optional(),
})

type FormData = z.infer<typeof schema>

interface Props {
  item?: InventarioItem
  onSuccess: () => void
  onCancel: () => void
}

export function InventarioForm({ item, onSuccess, onCancel }: Props) {
  const esEdicion  = Boolean(item?.id)
  const crear      = useCrearInventario()
  const actualizar = useActualizarInventario()
  const { usuario } = useAuth()
  const esAdmin     = esAdminOGerente(usuario)

  const { data: tiendasData } = useQuery<{ codigo: string; nombre: string }[]>({
    queryKey: ['tiendas-select'],
    queryFn: () => api.get('/v1/tiendas/select').then(r => r.data),
    staleTime: 5 * 60_000,
  })
  const tiendas = (tiendasData ?? []).map(t => t.codigo)

  const { register, handleSubmit, watch, formState: { errors } } = useForm<FormData>({
    resolver: zodResolver(schema),
    defaultValues: item
      ? {
          tienda_id:         item.tienda_id,
          producto_nombre:   item.producto_nombre,
          tipo:              item.tipo,
          imei_serial:       item.imei_serial ?? '',
          precio_costo:      parseFloat(item.precio_costo),
          precio_minimo:     parseFloat(item.precio_minimo),
          precio_normal:     parseFloat(item.precio_normal),
          cantidad:          item.cantidad,
          estado:            item.estado,
          comision_especial: item.comision_especial ? parseFloat(item.comision_especial) : undefined,
        }
      : {
          tipo: 'EQUIPO',
          estado: 'DISPONIBLE',
          cantidad: 1,
          tienda_id: usuario?.tienda_id ?? '',
        },
  })
  const tipo = watch('tipo')

  // El form de ingreso de la tienda no lleva precios (paridad tienda/registrar_stock.php):
  // los fija gerencia después. Admin y edición sí los ven, y ahí siguen siendo opcionales.
  const mostrarPrecios = esAdmin || esEdicion

  const onSubmit = (data: FormData) => {
    const dniAutoriza = (data.dni_autoriza ?? '').trim()
    if (!esAdmin && !dniAutoriza) {
      return
    }

    const payload = {
      ...data,
      dni_autoriza: dniAutoriza,
      imei_serial: data.imei_serial || null,
      imei_seriales: !esEdicion && data.tipo === 'EQUIPO'
        ? (data.imei_seriales_text ?? '').split(/\r?\n|,/).map(v => v.trim()).filter(Boolean)
        : undefined,
    }
    delete (payload as Partial<FormData>).imei_seriales_text
    if (esAdmin) delete (payload as Partial<FormData>).dni_autoriza

    if (esEdicion && item) {
      actualizar.mutate({ id: item.id, data: payload }, { onSuccess })
    } else {
      crear.mutate(payload, { onSuccess })
    }
  }

  const isPending = crear.isPending || actualizar.isPending
  const mutError  = (crear.error || actualizar.error) as { response?: { data?: { message?: string } } } | null

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      <div className="grid grid-cols-2 gap-4">
        <div>
          <Label htmlFor="tienda_id">Tienda *</Label>
          <Select id="tienda_id" {...register('tienda_id')} className="mt-1" disabled={!esAdmin}>
            <option value="">Selecciona una tienda</option>
            {(esAdmin ? tiendas : tiendas.filter(t => t === usuario?.tienda_id)).map((t) => (
              <option key={t} value={t}>{t}</option>
            ))}
          </Select>
          {errors.tienda_id && <p className="text-red-500 text-xs mt-1">{errors.tienda_id.message}</p>}
        </div>
        <div>
          <Label htmlFor="tipo">Tipo *</Label>
          <Select id="tipo" {...register('tipo')} className="mt-1">
            <option value="EQUIPO">Equipo</option>
            <option value="ACCESORIO">Accesorio</option>
            <option value="CHIP">Chip</option>
          </Select>
          {errors.tipo && <p className="text-red-500 text-xs mt-1">{errors.tipo.message}</p>}
        </div>
      </div>

      <div>
        <Label htmlFor="producto_nombre">Nombre del producto *</Label>
        <Input
          id="producto_nombre"
          {...register('producto_nombre')}
          placeholder="Samsung Galaxy A15 128GB"
          className="mt-1"
        />
        {errors.producto_nombre && <p className="text-red-500 text-xs mt-1">{errors.producto_nombre.message}</p>}
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div>
          <Label htmlFor={tipo === 'EQUIPO' && !esEdicion ? 'imei_seriales_text' : 'imei_serial'}>
            {tipo === 'EQUIPO' && !esEdicion ? 'IMEI / Series (uno por linea)' : 'IMEI / Serie'}
          </Label>
          {tipo === 'EQUIPO' && !esEdicion ? (
            <textarea
              id="imei_seriales_text"
              {...register('imei_seriales_text')}
              rows={4}
              placeholder={'358461082345678\n358461082345679'}
              className="kyro-input mt-1 w-full font-mono text-sm"
            />
          ) : (
            <Input
              id="imei_serial"
              {...register('imei_serial')}
              placeholder="358461082345678"
              maxLength={50}
              className="mt-1"
            />
          )}
          {errors.imei_serial && <p className="text-red-500 text-xs mt-1">{errors.imei_serial.message}</p>}
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

      {mostrarPrecios ? (
        <div className="grid grid-cols-3 gap-4">
          <div>
            <Label htmlFor="precio_costo">Precio costo (S/)</Label>
            <Input
              id="precio_costo"
              type="number"
              step="0.01"
              min="0"
              placeholder="Pendiente"
              {...register('precio_costo', { setValueAs: comoNumeroOpcional })}
              className="mt-1"
            />
            {errors.precio_costo && <p className="text-red-500 text-xs mt-1">{errors.precio_costo.message}</p>}
          </div>
          <div>
            <Label htmlFor="precio_minimo">Precio mínimo (S/)</Label>
            <Input
              id="precio_minimo"
              type="number"
              step="0.01"
              min="0"
              placeholder="Pendiente"
              {...register('precio_minimo', { setValueAs: comoNumeroOpcional })}
              className="mt-1"
            />
            {errors.precio_minimo && <p className="text-red-500 text-xs mt-1">{errors.precio_minimo.message}</p>}
          </div>
          <div>
            <Label htmlFor="precio_normal">Precio normal (S/)</Label>
            <Input
              id="precio_normal"
              type="number"
              step="0.01"
              min="0"
              placeholder="Pendiente"
              {...register('precio_normal', { setValueAs: comoNumeroOpcional })}
              className="mt-1"
            />
            {errors.precio_normal && <p className="text-red-500 text-xs mt-1">{errors.precio_normal.message}</p>}
          </div>
        </div>
      ) : (
        <p className="rounded-kyro-lg border border-kyro-info/30 bg-kyro-info/10 p-3 text-xs text-kyro-info">
          Los precios de venta serán asignados por gerencia tras el registro.
        </p>
      )}

      <div className="grid grid-cols-2 gap-4">
        <div>
          <Label htmlFor="estado">Estado *</Label>
          <Select id="estado" {...register('estado')} className="mt-1">
            <option value="DISPONIBLE">Disponible</option>
            <option value="VENDIDO">Vendido</option>
            <option value="TRASLADO">Traslado</option>
          </Select>
          {errors.estado && <p className="text-red-500 text-xs mt-1">{errors.estado.message}</p>}
        </div>
        <div>
          <Label htmlFor="comision_especial">Comisión especial (S/)</Label>
          <Input
            id="comision_especial"
            type="number"
            step="0.01"
            min="0"
            {...register('comision_especial', { setValueAs: comoNumeroOpcional })}
            className="mt-1"
          />
        </div>
      </div>

      {!esAdmin && (
        <div>
          <Label htmlFor="dni_autoriza">Tu DNI *</Label>
          <Input
            id="dni_autoriza"
            {...register('dni_autoriza')}
            placeholder="12345678"
            maxLength={8}
            required
            className="mt-1"
          />
        </div>
      )}

      {mutError && (
        <p className="text-red-500 text-sm">
          {mutError.response?.data?.message ?? 'Error al guardar. Verifica los datos.'}
        </p>
      )}

      <div className="flex gap-3 pt-2">
        <Button type="submit" variant="default" disabled={isPending} className="flex-1">
          {isPending ? 'Guardando...' : esEdicion ? 'Actualizar item' : 'Registrar item'}
        </Button>
        <Button type="button" variant="outline" onClick={onCancel} disabled={isPending}>
          Cancelar
        </Button>
      </div>
    </form>
  )
}
