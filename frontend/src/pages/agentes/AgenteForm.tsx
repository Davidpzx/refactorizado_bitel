import { useState, useEffect, useRef, type ReactNode } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery } from '@tanstack/react-query'
import { BriefcaseBusiness, Clock3, Contact, KeyRound, Loader2, Search, ShieldCheck } from 'lucide-react'
import { useCrearAgente, useActualizarAgente } from '../../hooks/useAgentes'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { Select } from '../../components/ui/select'
import { api } from '../../services/api'
import { soloFecha } from '../../lib/fechas'
import type { Agente } from '../../types/agente'

interface TiendaOption {
  codigo: string
  nombre: string
}

function fechaISO(date: Date): string {
  return date.toISOString().slice(0, 10)
}

/** Hoy: tope máximo de fecha de ingreso (no se permite ingresar a futuro). */
function fechaMaxIngreso(): string {
  return fechaISO(new Date())
}

/** Hace 5 años: tope mínimo de fecha de ingreso. */
function fechaMinIngreso(): string {
  const d = new Date()
  d.setFullYear(d.getFullYear() - 5)
  return fechaISO(d)
}

// Un único schema: pin siempre opcional, validado manualmente en create
const schema = z.object({
  dni:           z.string().regex(/^\d{8}$/, 'DNI debe tener exactamente 8 dígitos'),
  nombres:       z.string().min(2, 'Nombres requeridos').max(200, 'Máximo 200 caracteres')
                   .regex(/^[A-Za-zÁÉÍÓÚáéíóúÑñ\s'-]+$/, 'El nombre no debe contener números ni símbolos'),
  tienda_base:   z.string().min(1, 'Selecciona una tienda').max(10),
  sueldo_base:   z.number().min(0, 'El sueldo no puede ser negativo'),
  estado:        z.enum(['ACTIVO', 'INACTIVO', 'BAJA']),
  fecha_ingreso: z.string().min(1, 'Fecha de ingreso requerida')
                   .refine(val => val >= fechaMinIngreso() && val <= fechaMaxIngreso(), {
                     message: 'La fecha debe estar entre hace 5 años y hoy',
                   }),
  pin_seguridad: z.string().min(4, 'PIN mínimo 4 caracteres').max(8, 'PIN máximo 8 caracteres').optional().or(z.literal('')),
  hora_ingreso:  z.string().optional().or(z.literal('')),
  hora_salida:   z.string().optional().or(z.literal('')),
  dia_descanso:  z.string().optional().or(z.literal('')),
  correo:        z.string().email('Correo inválido').max(30, 'Máximo 30 caracteres').optional().or(z.literal('')),
  telefono:      z.string().max(15).optional().or(z.literal('')),
  direccion:     z.string().max(60, 'Máximo 60 caracteres').optional().or(z.literal('')),
  es_gerencia:   z.boolean(),
  permiso_largo: z.boolean().optional(),
  fecha_retorno: z.string().optional().or(z.literal('')),
  clasificacion_baja: z.enum(['LISTA_BLANCA', 'LISTA_NEGRA']).optional().or(z.literal('')),
  motivo_baja:   z.string().max(1000, 'Máximo 1000 caracteres').optional().or(z.literal('')),
  fecha_baja:    z.string().optional().or(z.literal('')),
  observacion:   z.string().max(1000, 'Máximo 1000 caracteres').optional().or(z.literal('')),
})

type FormData = z.infer<typeof schema>
type CampoFormulario = keyof FormData

/** Mapea los errores 422 de Laravel a cada campo del formulario; devuelve el mensaje general. */
function aplicarErroresBackend(
  e: unknown,
  setError: (campo: CampoFormulario, error: { message: string }) => void,
): string {
  const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data
  const errores = data?.errors ?? {}
  Object.entries(errores).forEach(([campo, mensajes]) => {
    setError(campo as CampoFormulario, { message: mensajes[0] })
  })
  return data?.message ?? Object.values(errores).flat()[0] ?? 'No se pudo guardar. Verifica los datos.'
}

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
  const [dniLoading, setDniLoading]       = useState(false)
  const [dniError,   setDniError]         = useState<string | null>(null)
  const [errorGeneral, setErrorGeneral]   = useState('')
  const errorRef = useRef<HTMLParagraphElement>(null)

  const { data: tiendas = [] } = useQuery({
    queryKey: ['tiendas-select-agente'],
    queryFn: () => api.get<{ data: TiendaOption[] }>('/v1/tiendas', { params: { per_page: 200 } }).then(r => r.data.data),
    staleTime: 60_000,
  })

  const { register, handleSubmit, setError, setValue, control, formState: { errors } } = useForm<FormData>({
    resolver: zodResolver(schema),
    defaultValues: agente
      ? {
          dni:           agente.dni,
          nombres:       agente.nombres,
          tienda_base:   agente.tienda_base,
          sueldo_base:   parseFloat(agente.sueldo_base),
          estado:        agente.estado,
          fecha_ingreso: soloFecha(agente.fecha_ingreso),
          hora_ingreso:  agente.hora_ingreso  ?? '',
          hora_salida:   agente.hora_salida   ?? '',
          dia_descanso:  agente.dia_descanso  ?? '',
          correo:        agente.correo        ?? '',
          telefono:      agente.telefono      ?? '',
          direccion:     agente.direccion     ?? '',
          es_gerencia:   agente.es_gerencia,
          permiso_largo: agente.permiso_largo ?? false,
          fecha_retorno: soloFecha(agente.fecha_retorno),
          clasificacion_baja: agente.clasificacion_baja ?? '',
          motivo_baja:   agente.motivo_baja ?? '',
          fecha_baja:    soloFecha(agente.fecha_baja),
          observacion:   agente.observacion ?? '',
          pin_seguridad: '',
        }
      : { estado: 'ACTIVO', es_gerencia: false, sueldo_base: 0 },
  })

  const dniValue    = useWatch({ control, name: 'dni' }) ?? ''
  const estadoValue = useWatch({ control, name: 'estado' })
  const permisoLargoValue = useWatch({ control, name: 'permiso_largo' })

  useEffect(() => {
    if (errorGeneral) errorRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }, [errorGeneral])

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
    setErrorGeneral('')
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
      actualizar.mutate({ id: agente.id, data: update }, {
        onSuccess,
        onError: (e) => setErrorGeneral(aplicarErroresBackend(e, setError)),
      })
    } else {
      crear.mutate(payload as FormData & { pin_seguridad: string }, {
        onSuccess,
        onError: (e) => setErrorGeneral(aplicarErroresBackend(e, setError)),
      })
    }
  }

  const isPending = crear.isPending || actualizar.isPending

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      {errorGeneral && (
        <p ref={errorRef} className="rounded-kyro border border-kyro-danger bg-kyro-danger/10 px-3 py-2 text-sm font-medium text-kyro-danger">
          {errorGeneral}
        </p>
      )}
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
                <Button type="button" variant="outline" size="icon" aria-label="Buscar en RENIEC" onClick={buscarDni} disabled={dniLoading} title="Buscar en RENIEC">
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
            <Input
              id="nombres"
              {...register('nombres')}
              placeholder="Juan Pérez García"
              onKeyDown={e => { if (/\d/.test(e.key)) e.preventDefault() }}
              className="mt-1"
            />
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
            <Select id="tienda_base" {...register('tienda_base')} className="mt-1">
              <option value="">— Selecciona una tienda —</option>
              {tiendas.map(t => (
                <option key={t.codigo} value={t.codigo}>{t.nombre} ({t.codigo})</option>
              ))}
            </Select>
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
            <Input
              id="fecha_ingreso"
              type="date"
              min={fechaMinIngreso()}
              max={fechaMaxIngreso()}
              {...register('fecha_ingreso')}
              className="mt-1"
            />
            <FieldError>{errors.fecha_ingreso?.message}</FieldError>
          </div>
        </div>

        {esEdicion && estadoValue !== 'ACTIVO' && (
          <div className="mt-4 grid grid-cols-1 gap-4 rounded-kyro border border-kyro-warning/30 bg-kyro-warning/5 p-3 sm:grid-cols-2">
            <p className="text-xs font-semibold uppercase tracking-wider text-kyro-warning sm:col-span-2">
              Datos de baja
            </p>
            <div>
              <Label htmlFor="clasificacion_baja">Clasificación</Label>
              <Select id="clasificacion_baja" {...register('clasificacion_baja')} className="mt-1">
                <option value="">— Sin clasificar —</option>
                <option value="LISTA_BLANCA">Lista blanca (puede reingresar)</option>
                <option value="LISTA_NEGRA">Lista negra (no recontratar)</option>
              </Select>
            </div>
            <div>
              <Label htmlFor="fecha_baja">Fecha de baja</Label>
              <Input id="fecha_baja" type="date" {...register('fecha_baja')} className="mt-1" />
            </div>
            <div className="sm:col-span-2">
              <Label htmlFor="motivo_baja">Motivo de la baja</Label>
              <textarea id="motivo_baja" rows={2} {...register('motivo_baja')} className="kyro-input mt-1 w-full text-sm" placeholder="Motivo del cese (opcional)" />
              <FieldError>{errors.motivo_baja?.message}</FieldError>
            </div>
            <div className="sm:col-span-2">
              <Label htmlFor="observacion">Observación</Label>
              <textarea id="observacion" rows={2} {...register('observacion')} className="kyro-input mt-1 w-full text-sm" placeholder="Detalle adicional (opcional)" />
              <FieldError>{errors.observacion?.message}</FieldError>
            </div>
            <label className="flex cursor-pointer items-center gap-3 rounded-kyro border border-kyro-border bg-kyro-elevated px-3 py-2.5 text-sm text-kyro-body sm:col-span-2">
              <input id="permiso_largo" type="checkbox" {...register('permiso_largo')} className="h-4 w-4 rounded border-kyro-border accent-kyro-gold" />
              Permiso largo (licencia con retorno programado)
            </label>
            {permisoLargoValue && (
              <div className="sm:col-span-2">
                <Label htmlFor="fecha_retorno">Fecha de retorno prevista</Label>
                <Input id="fecha_retorno" type="date" {...register('fecha_retorno')} className="mt-1" />
              </div>
            )}
          </div>
        )}
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
            <Input id="correo" type="email" {...register('correo')} placeholder="agente@empresa.pe" maxLength={30} className="mt-1" />
            <FieldError>{errors.correo?.message}</FieldError>
          </div>
          <div>
            <Label htmlFor="telefono">Teléfono</Label>
            <Input id="telefono" {...register('telefono')} placeholder="987654321" maxLength={15} inputMode="tel" className="mt-1" />
          </div>
          <div className="sm:col-span-2">
            <Label htmlFor="direccion">Dirección</Label>
            <Input id="direccion" {...register('direccion')} placeholder="Av. Ejemplo 123" maxLength={60} className="mt-1" />
            <FieldError>{errors.direccion?.message}</FieldError>
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

      <div className="flex flex-col-reverse gap-3 border-t border-kyro-border pt-4 sm:flex-row sm:justify-end">
        <Button type="button" variant="outline" onClick={onCancel} disabled={isPending}>
          Cancelar
        </Button>
        <Button type="submit" variant="gold" disabled={isPending} className="sm:min-w-40">
          {isPending ? 'Guardando...' : esEdicion ? 'Actualizar agente' : 'Registrar agente'}
        </Button>
      </div>
    </form>
  )
}
