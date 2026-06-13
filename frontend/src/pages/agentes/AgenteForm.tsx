import { useState, type ReactNode } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { BriefcaseBusiness, Clock3, Contact, KeyRound, Loader2, Search, ShieldCheck } from 'lucide-react'
import { useCrearAgente, useActualizarAgente } from '../../hooks/useAgentes'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { Select } from '../../components/ui/select'
import { api } from '../../services/api'
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

function FormSection({
  title,
  description,
  icon,
  children,
}: {
  title: string
  description: string
  icon: ReactNode
  children: ReactNode
}) {
  return (
    <section className="kyro-card p-4">
      <div className="mb-4 flex items-center gap-2.5 border-b border-kyro-border pb-3">
        <span className="flex h-8 w-8 items-center justify-center rounded-kyro border border-kyro-indigo bg-kyro-elevated text-kyro-gold">
          {icon}
        </span>
        <div>
          <h3 className="text-sm font-semibold text-kyro-text">{title}</h3>
          <p className="text-xs text-kyro-muted">{description}</p>
        </div>
      </div>
      {children}
    </section>
  )
}

function FieldError({ children }: { children?: ReactNode }) {
  return children ? <p className="mt-1 text-xs text-kyro-danger">{children}</p> : null
}

export function AgenteForm({ agente, onSuccess, onCancel }: Props) {
  const esEdicion  = Boolean(agente?.id)
  const crear      = useCrearAgente()
  const actualizar = useActualizarAgente()
  const [dniLoading, setDniLoading] = useState(false)
  const [dniError,   setDniError]   = useState<string | null>(null)

  const { register, handleSubmit, setError, setValue, control, formState: { errors } } = useForm<FormData>({
    resolver: zodResolver(schema),
    defaultValues: agente
      ? {
          dni:           agente.dni,
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

  const dniValue = useWatch({ control, name: 'dni' }) ?? ''

  const buscarDni = async () => {
    if (!/^\d{8}$/.test(dniValue)) {
      setDniError('Ingresa un DNI de 8 dígitos antes de buscar.')
      return
    }
    setDniLoading(true)
    setDniError(null)
    try {
      const res = await api.get<{ nombres?: string; apellido_paterno?: string; apellido_materno?: string; nombre_completo?: string }>(`/v1/dni/${dniValue}`)
      const d = res.data
      const nombreCompleto = d.nombre_completo
        ?? [d.nombres, d.apellido_paterno, d.apellido_materno].filter(Boolean).join(' ')
      if (nombreCompleto) {
        setValue('nombres', nombreCompleto, { shouldValidate: true })
      } else {
        setDniError('DNI encontrado pero sin nombre registrado.')
      }
    } catch {
      setDniError('No se encontró información para ese DNI.')
    } finally {
      setDniLoading(false)
    }
  }

  const onSubmit = (data: FormData) => {
    if (!esEdicion && !data.pin_seguridad) {
      setError('pin_seguridad', { message: 'El PIN es obligatorio para nuevos agentes' })
      return
    }

    const pin = data.pin_seguridad || undefined
    const payload = { ...data, ...(pin ? { pin_seguridad: pin } : {}) }

    if (esEdicion && agente) {
      const { pin_seguridad: omittedPin, ...rest } = payload
      void omittedPin
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
      <FormSection
        title="Identificación"
        description="Información principal del agente."
        icon={<Contact size={15} />}
      >
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          {!esEdicion ? (
            <div>
              <Label htmlFor="dni">DNI *</Label>
              <div className="mt-1 flex gap-2">
                <Input id="dni" {...register('dni')} placeholder="12345678" maxLength={8} inputMode="numeric" />
                <Button type="button" variant="outline" size="icon" onClick={buscarDni} disabled={dniLoading} title="Buscar en RENIEC">
                  {dniLoading ? <Loader2 size={15} className="animate-spin" /> : <Search size={15} />}
                </Button>
              </div>
              <FieldError>{errors.dni?.message}</FieldError>
              {dniError && <p className="mt-1 text-xs text-kyro-warning">{dniError}</p>}
            </div>
          ) : (
            <div>
              <Label htmlFor="dni">DNI</Label>
              <Input id="dni" {...register('dni')} readOnly className="mt-1 cursor-not-allowed bg-kyro-base font-mono" />
            </div>
          )}
          <div>
            <Label htmlFor="nombres">Nombres completos *</Label>
            <Input id="nombres" {...register('nombres')} placeholder="Juan Pérez García" className="mt-1" />
            <FieldError>{errors.nombres?.message}</FieldError>
          </div>
        </div>
      </FormSection>

      <FormSection
        title="Información laboral"
        description="Asignación, estado y condiciones de ingreso."
        icon={<BriefcaseBusiness size={15} />}
      >
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label htmlFor="tienda_base">Tienda base *</Label>
            <Input id="tienda_base" {...register('tienda_base')} placeholder="PUNDA11" className="mt-1 uppercase" />
            <FieldError>{errors.tienda_base?.message}</FieldError>
          </div>
          <div>
            <Label htmlFor="estado">Estado</Label>
            <Select id="estado" {...register('estado')} className="mt-1">
              <option value="ACTIVO">Activo</option>
              <option value="INACTIVO">Inactivo</option>
              <option value="BAJA">Baja</option>
            </Select>
          </div>
          <div>
            <Label htmlFor="sueldo_base">Sueldo base (S/) *</Label>
            <Input id="sueldo_base" type="number" step="0.01" min="0" {...register('sueldo_base', { valueAsNumber: true })} className="mt-1" />
            <FieldError>{errors.sueldo_base?.message}</FieldError>
          </div>
          <div>
            <Label htmlFor="fecha_ingreso">Fecha de ingreso *</Label>
            <Input id="fecha_ingreso" type="date" {...register('fecha_ingreso')} className="mt-1" />
            <FieldError>{errors.fecha_ingreso?.message}</FieldError>
          </div>
        </div>
      </FormSection>

      <FormSection
        title="Horario"
        description="Jornada habitual y descanso semanal."
        icon={<Clock3 size={15} />}
      >
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
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
      </FormSection>

      <FormSection
        title="Contacto y acceso"
        description="Canales de contacto y credencial de seguridad."
        icon={<ShieldCheck size={15} />}
      >
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label htmlFor="correo">Correo</Label>
            <Input id="correo" type="email" {...register('correo')} placeholder="agente@empresa.pe" className="mt-1" />
            <FieldError>{errors.correo?.message}</FieldError>
          </div>
          <div>
            <Label htmlFor="telefono">Teléfono</Label>
            <Input id="telefono" {...register('telefono')} placeholder="987654321" maxLength={15} inputMode="tel" className="mt-1" />
          </div>
          <div className="sm:col-span-2">
            <Label htmlFor="direccion">Dirección</Label>
            <Input id="direccion" {...register('direccion')} placeholder="Av. Ejemplo 123" className="mt-1" />
          </div>
          <div className="sm:col-span-2">
            <Label htmlFor="pin_seguridad">
              PIN de seguridad {esEdicion ? '(vacío = sin cambio)' : '*'}
            </Label>
            <div className="relative mt-1">
              <KeyRound size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-kyro-subtle" />
              <Input id="pin_seguridad" type="password" {...register('pin_seguridad')} placeholder={esEdicion ? '••••' : 'Mínimo 4 caracteres'} maxLength={8} className="pl-9" />
            </div>
            <FieldError>{errors.pin_seguridad?.message}</FieldError>
          </div>
          <label className="flex cursor-pointer items-center gap-3 rounded-kyro border border-kyro-border bg-kyro-elevated px-3 py-2.5 text-sm text-kyro-body sm:col-span-2">
            <input id="es_gerencia" type="checkbox" {...register('es_gerencia')} className="h-4 w-4 rounded border-kyro-border accent-kyro-gold" />
            Personal de gerencia
          </label>
        </div>
      </FormSection>

      {mutError && (
        <p className="rounded-kyro border border-kyro-danger bg-kyro-danger/10 px-3 py-2 text-sm text-kyro-danger">
          {mutError.response?.data?.message ?? 'Error al guardar. Verifica los datos.'}
        </p>
      )}

      <div className="flex flex-col-reverse gap-3 border-t border-kyro-border pt-4 sm:flex-row sm:justify-end">
        <Button type="button" variant="outline" onClick={onCancel} disabled={isPending}>
          Cancelar
        </Button>
        <Button type="submit" disabled={isPending} className="sm:min-w-40">
          {isPending ? 'Guardando...' : esEdicion ? 'Actualizar agente' : 'Registrar agente'}
        </Button>
      </div>
    </form>
  )
}
