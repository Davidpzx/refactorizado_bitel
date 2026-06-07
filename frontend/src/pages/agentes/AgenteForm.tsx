import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useCrearAgente, useActualizarAgente } from '../../hooks/useAgentes'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { Select } from '../../components/ui/select'
import type { Agente } from '../../types/agente'

// Un único schema: pin siempre opcional, validado manualmente en create
const schema = z.object({
  dni:           z.string().regex(/^\d{8}$/, 'DNI debe tener exactamente 8 dígitos'),
  nombres:       z.string().min(2, 'Nombres requeridos').max(200),
  tienda_base:   z.string().min(1, 'Tienda requerida').max(10),
  sueldo_base:   z.number().min(0, 'El sueldo no puede ser negativo'),
  estado:        z.enum(['ACTIVO', 'INACTIVO', 'BAJA']),
  fecha_ingreso: z.string().min(1, 'Fecha de ingreso requerida'),
  pin_seguridad: z.string().min(4, 'PIN mínimo 4 caracteres').max(8, 'PIN máximo 8 caracteres').optional().or(z.literal('')),
  hora_ingreso:  z.string().optional().or(z.literal('')),
  hora_salida:   z.string().optional().or(z.literal('')),
  dia_descanso:  z.string().optional().or(z.literal('')),
  correo:        z.string().email('Correo inválido').optional().or(z.literal('')),
  telefono:      z.string().max(15).optional().or(z.literal('')),
  direccion:     z.string().max(300).optional().or(z.literal('')),
  es_gerencia:   z.boolean(),
})

type FormData = z.infer<typeof schema>

interface Props {
  agente?: Agente
  onSuccess: () => void
  onCancel: () => void
}

export function AgenteForm({ agente, onSuccess, onCancel }: Props) {
  const esEdicion  = Boolean(agente?.id)
  const crear      = useCrearAgente()
  const actualizar = useActualizarAgente()

  const { register, handleSubmit, setError, formState: { errors } } = useForm<FormData>({
    resolver: zodResolver(schema),
    defaultValues: agente
      ? {
          nombres:       agente.nombres,
          tienda_base:   agente.tienda_base,
          sueldo_base:   parseFloat(agente.sueldo_base),
          estado:        agente.estado,
          fecha_ingreso: agente.fecha_ingreso,
          hora_ingreso:  agente.hora_ingreso  ?? '',
          hora_salida:   agente.hora_salida   ?? '',
          dia_descanso:  agente.dia_descanso  ?? '',
          correo:        agente.correo        ?? '',
          telefono:      agente.telefono      ?? '',
          direccion:     agente.direccion     ?? '',
          es_gerencia:   agente.es_gerencia,
          pin_seguridad: '',
        }
      : { estado: 'ACTIVO', es_gerencia: false, sueldo_base: 0 },
  })

  const onSubmit = (data: FormData) => {
    if (!esEdicion && !data.pin_seguridad) {
      setError('pin_seguridad', { message: 'El PIN es obligatorio para nuevos agentes' })
      return
    }

    const pin = data.pin_seguridad || undefined
    const payload = { ...data, ...(pin ? { pin_seguridad: pin } : {}) }

    if (esEdicion && agente) {
      const { pin_seguridad: _, ...rest } = payload
      const update = pin ? { ...rest, pin_seguridad: pin } : rest
      actualizar.mutate({ id: agente.id, data: update }, { onSuccess })
    } else {
      crear.mutate(payload as FormData & { pin_seguridad: string }, { onSuccess })
    }
  }

  const isPending = crear.isPending || actualizar.isPending
  const mutError  = (crear.error || actualizar.error) as { response?: { data?: { message?: string } } } | null

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      <div className="grid grid-cols-2 gap-4">
        {!esEdicion && (
          <div>
            <Label htmlFor="dni">DNI *</Label>
            <Input id="dni" {...register('dni')} placeholder="12345678" maxLength={8} className="mt-1" />
            {errors.dni && <p className="text-red-500 text-xs mt-1">{errors.dni.message}</p>}
          </div>
        )}
        <div className={esEdicion ? 'col-span-2' : ''}>
          <Label htmlFor="nombres">Nombres completos *</Label>
          <Input id="nombres" {...register('nombres')} placeholder="Juan Pérez García" className="mt-1" />
          {errors.nombres && <p className="text-red-500 text-xs mt-1">{errors.nombres.message}</p>}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div>
          <Label htmlFor="tienda_base">Tienda base *</Label>
          <Input id="tienda_base" {...register('tienda_base')} placeholder="PUNDA11" className="mt-1" />
          {errors.tienda_base && <p className="text-red-500 text-xs mt-1">{errors.tienda_base.message}</p>}
        </div>
        <div>
          <Label htmlFor="estado">Estado</Label>
          <Select id="estado" {...register('estado')} className="mt-1">
            <option value="ACTIVO">Activo</option>
            <option value="INACTIVO">Inactivo</option>
            <option value="BAJA">Baja</option>
          </Select>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div>
          <Label htmlFor="sueldo_base">Sueldo base (S/) *</Label>
          <Input
            id="sueldo_base"
            type="number"
            step="0.01"
            min="0"
            {...register('sueldo_base', { valueAsNumber: true })}
            className="mt-1"
          />
          {errors.sueldo_base && <p className="text-red-500 text-xs mt-1">{errors.sueldo_base.message}</p>}
        </div>
        <div>
          <Label htmlFor="fecha_ingreso">Fecha de ingreso *</Label>
          <Input id="fecha_ingreso" type="date" {...register('fecha_ingreso')} className="mt-1" />
          {errors.fecha_ingreso && <p className="text-red-500 text-xs mt-1">{errors.fecha_ingreso.message}</p>}
        </div>
      </div>

      <div>
        <Label htmlFor="pin_seguridad">
          PIN de seguridad {esEdicion ? '(vacío = sin cambio)' : '*'}
        </Label>
        <Input
          id="pin_seguridad"
          type="password"
          {...register('pin_seguridad')}
          placeholder={esEdicion ? '••••' : 'Mínimo 4 caracteres'}
          maxLength={8}
          className="mt-1"
        />
        {errors.pin_seguridad && <p className="text-red-500 text-xs mt-1">{errors.pin_seguridad.message}</p>}
      </div>

      <div className="grid grid-cols-3 gap-4">
        <div>
          <Label htmlFor="hora_ingreso">Hora ingreso</Label>
          <Input id="hora_ingreso" type="time" {...register('hora_ingreso')} className="mt-1" />
        </div>
        <div>
          <Label htmlFor="hora_salida">Hora salida</Label>
          <Input id="hora_salida" type="time" {...register('hora_salida')} className="mt-1" />
        </div>
        <div>
          <Label htmlFor="dia_descanso">Día de descanso</Label>
          <Select id="dia_descanso" {...register('dia_descanso')} className="mt-1">
            <option value="">Sin descanso fijo</option>
            <option value="LUNES">Lunes</option>
            <option value="MARTES">Martes</option>
            <option value="MIERCOLES">Miércoles</option>
            <option value="JUEVES">Jueves</option>
            <option value="VIERNES">Viernes</option>
            <option value="SABADO">Sábado</option>
            <option value="DOMINGO">Domingo</option>
          </Select>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div>
          <Label htmlFor="correo">Correo</Label>
          <Input id="correo" type="email" {...register('correo')} placeholder="agente@empresa.pe" className="mt-1" />
          {errors.correo && <p className="text-red-500 text-xs mt-1">{errors.correo.message}</p>}
        </div>
        <div>
          <Label htmlFor="telefono">Teléfono</Label>
          <Input id="telefono" {...register('telefono')} placeholder="987654321" maxLength={15} className="mt-1" />
        </div>
      </div>

      <div>
        <Label htmlFor="direccion">Dirección</Label>
        <Input id="direccion" {...register('direccion')} placeholder="Av. Ejemplo 123" className="mt-1" />
      </div>

      <div className="flex items-center gap-2">
        <input
          id="es_gerencia"
          type="checkbox"
          {...register('es_gerencia')}
          className="h-4 w-4 rounded border-gray-300 text-blue-600"
        />
        <Label htmlFor="es_gerencia">¿Es personal de gerencia?</Label>
      </div>

      {mutError && (
        <p className="text-red-500 text-sm">
          {mutError.response?.data?.message ?? 'Error al guardar. Verifica los datos.'}
        </p>
      )}

      <div className="flex gap-3 pt-2">
        <Button type="submit" disabled={isPending} className="flex-1">
          {isPending ? 'Guardando...' : esEdicion ? 'Actualizar agente' : 'Registrar agente'}
        </Button>
        <Button type="button" variant="outline" onClick={onCancel} disabled={isPending}>
          Cancelar
        </Button>
      </div>
    </form>
  )
}
