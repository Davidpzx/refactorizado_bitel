import { useState, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Save, Upload, Trash2, Building2, CheckCircle2 } from 'lucide-react'
import { api } from '../../services/api'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'

// ── Types ─────────────────────────────────────────────────────────────────────

interface Configuracion {
  id: number | null
  razon_social: string
  nombre_comercial: string
  ruc: string
  gerente_general: string
  gerente_dni: string
  sistema_nombre: string
  telefono_contacto: string
  direccion_principal: string
  correo_contacto: string
  logo_base64?: string | null
}

// ── API ───────────────────────────────────────────────────────────────────────

const configuracionApi = {
  get: () =>
    api.get<Configuracion>('/v1/configuracion').then(r => r.data),
  getConLogo: () =>
    api.get<Configuracion>('/v1/configuracion/con-logo').then(r => r.data),
  update: (data: Partial<Configuracion>) =>
    api.put<{ message: string }>('/v1/configuracion', data).then(r => r.data),
  uploadLogo: (file: File) => {
    const fd = new FormData()
    fd.append('logo', file)
    return api.post<{ message: string; logo_base64: string }>('/v1/configuracion/logo', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  deleteLogo: () =>
    api.delete<{ message: string }>('/v1/configuracion/logo').then(r => r.data),
}

// ── Form schema ───────────────────────────────────────────────────────────────

const schema = z.object({
  razon_social:        z.string().min(1, 'Razón social requerida').max(200),
  nombre_comercial:    z.string().max(200).optional().or(z.literal('')),
  ruc:                 z.string().min(1, 'RUC requerido').max(11),
  gerente_general:     z.string().max(100).optional().or(z.literal('')),
  gerente_dni:         z.string().max(8).optional().or(z.literal('')),
  sistema_nombre:      z.string().max(100).optional().or(z.literal('')),
  telefono_contacto:   z.string().max(20).optional().or(z.literal('')),
  direccion_principal: z.string().max(255).optional().or(z.literal('')),
  correo_contacto:     z.string().email('Correo inválido').optional().or(z.literal('')),
})
type FormData = z.infer<typeof schema>

// ── Page ──────────────────────────────────────────────────────────────────────

export function ConfiguracionPage() {
  const qc          = useQueryClient()
  const [saved, setSaved] = useState(false)
  const [logoPreview, setLogoPreview] = useState<string | null>(null)
  const fileRef     = useRef<HTMLInputElement>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['configuracion-con-logo'],
    queryFn: configuracionApi.getConLogo,
  })

  const { register, handleSubmit, formState: { errors, isDirty }, reset } = useForm<FormData>({
    resolver: zodResolver(schema),
    values: data ? {
      razon_social:        data.razon_social        ?? '',
      nombre_comercial:    data.nombre_comercial    ?? '',
      ruc:                 data.ruc                 ?? '',
      gerente_general:     data.gerente_general     ?? '',
      gerente_dni:         data.gerente_dni         ?? '',
      sistema_nombre:      data.sistema_nombre      ?? '',
      telefono_contacto:   data.telefono_contacto   ?? '',
      direccion_principal: data.direccion_principal ?? '',
      correo_contacto:     data.correo_contacto     ?? '',
    } : undefined,
  })

  const updateMutation = useMutation({
    mutationFn: configuracionApi.update,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['configuracion-con-logo'] })
      setSaved(true)
      setTimeout(() => setSaved(false), 3000)
      reset(undefined, { keepValues: true })
    },
  })

  const logoMutation = useMutation({
    mutationFn: configuracionApi.uploadLogo,
    onSuccess: (res) => {
      setLogoPreview(res.logo_base64)
      qc.invalidateQueries({ queryKey: ['configuracion-con-logo'] })
    },
  })

  const deleteLogoMutation = useMutation({
    mutationFn: configuracionApi.deleteLogo,
    onSuccess: () => {
      setLogoPreview(null)
      qc.invalidateQueries({ queryKey: ['configuracion-con-logo'] })
    },
  })

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    logoMutation.mutate(file)
  }

  const currentLogo = logoPreview ?? data?.logo_base64

  if (isLoading) {
    return <div className="flex items-center justify-center h-64 text-gray-400">Cargando configuración...</div>
  }

  return (
    <div className="max-w-4xl space-y-6">
      {/* Header */}
      <div className="flex items-center gap-3">
        <Building2 size={28} className="text-blue-600" />
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Configuración de la Empresa</h1>
          <p className="text-sm text-gray-500">Datos de identificación fiscal y contacto</p>
        </div>
      </div>

      {saved && (
        <div className="flex items-center gap-2 bg-green-50 border border-green-200 rounded-lg px-4 py-3 text-green-700 text-sm">
          <CheckCircle2 size={16} /> Configuración guardada correctamente.
        </div>
      )}

      <form onSubmit={handleSubmit(d => updateMutation.mutate(d))} className="space-y-6">
        {/* Datos fiscales */}
        <div className="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 className="font-semibold text-gray-800 text-base border-b pb-2">Datos fiscales</h2>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <Label htmlFor="razon_social">Razón social *</Label>
              <Input id="razon_social" {...register('razon_social')} placeholder="EMPRESA SAC" className="mt-1" />
              {errors.razon_social && <p className="text-red-500 text-xs mt-1">{errors.razon_social.message}</p>}
            </div>
            <div>
              <Label htmlFor="nombre_comercial">Nombre comercial</Label>
              <Input id="nombre_comercial" {...register('nombre_comercial')} placeholder="Mi Negocio" className="mt-1" />
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <Label htmlFor="ruc">RUC *</Label>
              <Input id="ruc" {...register('ruc')} placeholder="20123456789" maxLength={11} className="mt-1" />
              {errors.ruc && <p className="text-red-500 text-xs mt-1">{errors.ruc.message}</p>}
            </div>
            <div>
              <Label htmlFor="sistema_nombre">Nombre del sistema</Label>
              <Input id="sistema_nombre" {...register('sistema_nombre')} placeholder="SIS-KYRO" className="mt-1" />
            </div>
          </div>
        </div>

        {/* Gerencia */}
        <div className="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 className="font-semibold text-gray-800 text-base border-b pb-2">Gerencia</h2>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <Label htmlFor="gerente_general">Gerente general</Label>
              <Input id="gerente_general" {...register('gerente_general')} placeholder="Juan Pérez García" className="mt-1" />
            </div>
            <div>
              <Label htmlFor="gerente_dni">DNI del gerente</Label>
              <Input id="gerente_dni" {...register('gerente_dni')} placeholder="12345678" maxLength={8} className="mt-1" />
            </div>
          </div>
        </div>

        {/* Contacto */}
        <div className="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 className="font-semibold text-gray-800 text-base border-b pb-2">Contacto</h2>

          <div>
            <Label htmlFor="direccion_principal">Dirección principal</Label>
            <Input id="direccion_principal" {...register('direccion_principal')} placeholder="Av. Ejemplo 123, Lima" className="mt-1" />
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <Label htmlFor="telefono_contacto">Teléfono</Label>
              <Input id="telefono_contacto" {...register('telefono_contacto')} placeholder="01 234 5678" maxLength={20} className="mt-1" />
            </div>
            <div>
              <Label htmlFor="correo_contacto">Correo</Label>
              <Input id="correo_contacto" type="email" {...register('correo_contacto')} placeholder="info@empresa.pe" className="mt-1" />
              {errors.correo_contacto && <p className="text-red-500 text-xs mt-1">{errors.correo_contacto.message}</p>}
            </div>
          </div>
        </div>

        <div className="flex justify-end">
          <Button type="submit" disabled={updateMutation.isPending || !isDirty} className="gap-2">
            <Save size={15} />
            {updateMutation.isPending ? 'Guardando...' : 'Guardar configuración'}
          </Button>
        </div>

        {updateMutation.error && (
          <p className="text-red-500 text-sm text-right">
            {(updateMutation.error as { response?: { data?: { message?: string } } }).response?.data?.message ?? 'Error al guardar.'}
          </p>
        )}
      </form>

      {/* Logo */}
      <div className="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <h2 className="font-semibold text-gray-800 text-base border-b pb-2">Logo de la empresa</h2>

        {currentLogo ? (
          <div className="flex items-start gap-6">
            <img
              src={currentLogo}
              alt="Logo empresa"
              className="h-24 w-auto object-contain border border-gray-200 rounded-lg p-2 bg-white"
            />
            <div className="space-y-2">
              <p className="text-sm text-gray-600">Logo actual. Puedes reemplazarlo subiendo una nueva imagen.</p>
              <div className="flex gap-3">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => fileRef.current?.click()}
                  disabled={logoMutation.isPending}
                >
                  <Upload size={13} className="mr-2" /> Reemplazar
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => deleteLogoMutation.mutate()}
                  disabled={deleteLogoMutation.isPending}
                  className="text-red-600 border-red-200 hover:bg-red-50"
                >
                  <Trash2 size={13} className="mr-2" /> Eliminar logo
                </Button>
              </div>
            </div>
          </div>
        ) : (
          <div className="flex flex-col items-center justify-center border-2 border-dashed border-gray-300 rounded-lg py-10 gap-3 text-gray-400">
            <Upload size={32} />
            <p className="text-sm">Sin logo configurado</p>
            <Button
              type="button"
              variant="outline"
              onClick={() => fileRef.current?.click()}
              disabled={logoMutation.isPending}
            >
              {logoMutation.isPending ? 'Subiendo...' : 'Subir logo'}
            </Button>
          </div>
        )}

        <input
          ref={fileRef}
          type="file"
          accept="image/png,image/jpeg,image/webp"
          onChange={handleFileChange}
          className="hidden"
        />
        <p className="text-xs text-gray-400">PNG, JPG o WebP — máximo 2 MB. Se convierte a base64 para inclusión en documentos PDF.</p>
      </div>
    </div>
  )
}
