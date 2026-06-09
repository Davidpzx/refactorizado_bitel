import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Search, Loader2 } from 'lucide-react'
import { useCrearCliente, useActualizarCliente } from '../../hooks/useClientes'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { Select } from '../../components/ui/select'
import { api } from '../../services/api'
import type { Cliente } from '../../types/cliente'

const schema = z.object({
  dni_ruc:        z.string().regex(/^\d{8}(\d{3})?$/, 'DNI: 8 dígitos · RUC: 11 dígitos'),
  nombre:         z.string().min(2, 'Nombre requerido').max(200),
  telefono:       z.string().max(15).optional().or(z.literal('')),
  correo:         z.string().email('Correo inválido').optional().or(z.literal('')),
  tipo_documento: z.enum(['DNI', 'RUC', 'CE', 'PAS']),
})

type ClienteFormData = z.infer<typeof schema>

interface Props {
  cliente?: Cliente
  onSuccess: () => void
  onCancel: () => void
}

export function ClienteForm({ cliente, onSuccess, onCancel }: Props) {
  const esEdicion  = Boolean(cliente?.id)
  const crear      = useCrearCliente()
  const actualizar = useActualizarCliente()
  const [dniLoading, setDniLoading] = useState(false)
  const [dniError,   setDniError]   = useState<string | null>(null)

  const { register, handleSubmit, setValue, watch, formState: { errors } } = useForm<ClienteFormData>({
    resolver: zodResolver(schema),
    defaultValues: cliente
      ? {
          dni_ruc:        cliente.dni_ruc,
          nombre:         cliente.nombre,
          telefono:       cliente.telefono ?? '',
          correo:         cliente.correo   ?? '',
          tipo_documento: cliente.tipo_documento,
        }
      : { tipo_documento: 'DNI' },
  })

  const dniRucValue    = watch('dni_ruc') ?? ''
  const tipoDocumento  = watch('tipo_documento')

  const buscarDni = async () => {
    if (!/^\d{8}$/.test(dniRucValue)) {
      setDniError('Ingresa un DNI de 8 dígitos antes de buscar.')
      return
    }
    setDniLoading(true)
    setDniError(null)
    try {
      const res = await api.get<{ nombres?: string; apellido_paterno?: string; apellido_materno?: string; nombre_completo?: string }>(`/v1/dni/${dniRucValue}`)
      const d = res.data
      const nombreCompleto = d.nombre_completo
        ?? [d.nombres, d.apellido_paterno, d.apellido_materno].filter(Boolean).join(' ')
      if (nombreCompleto) {
        setValue('nombre', nombreCompleto, { shouldValidate: true })
      } else {
        setDniError('DNI encontrado pero sin nombre registrado.')
      }
    } catch {
      setDniError('No se encontró información para ese DNI.')
    } finally {
      setDniLoading(false)
    }
  }

  const onSubmit = (data: ClienteFormData) => {
    if (esEdicion && cliente) {
      actualizar.mutate({ id: cliente.id, data }, { onSuccess })
    } else {
      crear.mutate(data, { onSuccess })
    }
  }

  const isPending = crear.isPending || actualizar.isPending
  const errorMsg  = (crear.error || actualizar.error) as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } } | null

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      <div className="grid grid-cols-2 gap-4">
        <div>
          <Label htmlFor="dni_ruc">DNI / RUC *</Label>
          <div className="flex gap-2 mt-1">
            <Input
              id="dni_ruc"
              {...register('dni_ruc')}
              placeholder="12345678"
              disabled={esEdicion}
            />
            {!esEdicion && tipoDocumento === 'DNI' && (
              <button
                type="button"
                onClick={buscarDni}
                disabled={dniLoading}
                title="Buscar en RENIEC"
                className="px-3 rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50 hover:text-blue-600 transition-colors disabled:opacity-50"
              >
                {dniLoading ? <Loader2 size={15} className="animate-spin" /> : <Search size={15} />}
              </button>
            )}
          </div>
          {errors.dni_ruc && <p className="text-red-500 text-xs mt-1">{errors.dni_ruc.message}</p>}
          {dniError && <p className="text-amber-600 text-xs mt-1">{dniError}</p>}
        </div>
        <div>
          <Label htmlFor="tipo_documento">Tipo de documento</Label>
          <Select id="tipo_documento" {...register('tipo_documento')} className="mt-1">
            <option value="DNI">DNI</option>
            <option value="RUC">RUC</option>
            <option value="CE">Carné de extranjería</option>
            <option value="PAS">Pasaporte</option>
          </Select>
        </div>
      </div>

      <div>
        <Label htmlFor="nombre">Nombre completo / Razón social *</Label>
        <Input id="nombre" {...register('nombre')} placeholder="Juan Pérez" className="mt-1" />
        {errors.nombre && <p className="text-red-500 text-xs mt-1">{errors.nombre.message}</p>}
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div>
          <Label htmlFor="telefono">Teléfono</Label>
          <Input id="telefono" {...register('telefono')} placeholder="987654321" maxLength={15} className="mt-1" />
        </div>
        <div>
          <Label htmlFor="correo">Correo electrónico</Label>
          <Input id="correo" type="email" {...register('correo')} placeholder="cliente@email.com" className="mt-1" />
          {errors.correo && <p className="text-red-500 text-xs mt-1">{errors.correo.message}</p>}
        </div>
      </div>

      {errorMsg && (
        <p className="text-red-500 text-sm">
          {errorMsg.response?.data?.message ?? 'Error al guardar. Verifica los datos.'}
        </p>
      )}

      <div className="flex gap-3 pt-2">
        <Button type="submit" disabled={isPending} className="flex-1">
          {isPending ? 'Guardando...' : esEdicion ? 'Actualizar cliente' : 'Registrar cliente'}
        </Button>
        <Button type="button" variant="outline" onClick={onCancel} disabled={isPending}>
          Cancelar
        </Button>
      </div>
    </form>
  )
}
