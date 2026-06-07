import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useForm, useFieldArray, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useAuth } from '../../hooks/useAuth'
import { useCrearReporte, usePlanesComisiones } from '../../hooks/useReportes'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { Select } from '../../components/ui/select'
import { Card } from '../../components/ui/card'
import { PageHeader } from '../../components/PageHeader'

const TIENDAS = [
  'PUNDA50', 'PUNDA11', 'PUNSC01', 'PUNDA23',
  'TACDA13', 'TACDA17', 'TACDA21', 'TACDA25', 'TACDA27', 'TACDA30',
]

const FINANCIERAS = ['Krece', 'Tasa Cero', 'Contado directo']

// ── Zod schema ───────────────────────────────────────────────────────────────

const ventaSchema = z.object({
  tipo_venta:         z.enum(['EQUIPO', 'ACCESORIO', 'POSTPAGO', 'PREPAGO', 'OTROS_FLUJO']),
  subtipo:            z.string().optional().or(z.literal('')),
  monto_total:        z.number().min(0),
  efectivo_inicial:   z.number().min(0),
  cross_selling:      z.boolean(),
  tienda_destino:     z.string().optional().or(z.literal('')),
  es_remate:          z.boolean(),
  es_extranjero:      z.boolean(),
  cliente_dni:        z.string().max(11).optional().or(z.literal('')),
  // Equipo / Accesorio
  producto_nombre:    z.string().optional().or(z.literal('')),
  imei_serial:        z.string().optional().or(z.literal('')),
  tipo_pago:          z.enum(['CONTADO', 'CUOTAS']),
  financiera:         z.string().optional().or(z.literal('')),
  precio_venta:       z.number().min(0),
  costo_snap:         z.number().min(0),
  por_cobrar_financiera: z.number().min(0),
  inventario_tienda_id: z.number().int().min(0),
  // Postpago / Prepago
  plan_nombre:        z.string().optional().or(z.literal('')),
  tipo_alta:          z.string().optional().or(z.literal('')),
  cantidad:           z.number().int().min(1),
  cobrado_unitario:   z.number().min(0),
  comision_unitaria:  z.number().min(0),
})

const schema = z.object({
  agente_id:          z.number().int().min(1),
  tienda_id:          z.string().min(1, 'Selecciona una tienda'),
  fecha:              z.string().min(1, 'La fecha es obligatoria'),
  caja_inicial:       z.number().min(0),
  yape:               z.number().min(0),
  bipay:              z.number().min(0),
  transferencia:      z.number().min(0),
  recarga_bipay:      z.number().min(0),
  pago_servicio:      z.number().min(0),
  pago_krece:         z.number().min(0),
  tickets_tusamy:     z.number().min(0),
  retiro_bipay:       z.number().min(0),
  efectivo_entregado: z.number().min(0),
  total_salidas:      z.number().min(0),
  nombre_cubre:       z.string().optional().or(z.literal('')),
  observaciones:      z.string().optional().or(z.literal('')),
  destino_efectivo:   z.enum(['TIENDA', 'ENTREGADO', 'EN_CAJA']),
  ventas:             z.array(ventaSchema),
})

type FormData = z.infer<typeof schema>
type VentaFormData = z.infer<typeof ventaSchema>

const VENTA_DEFAULT: VentaFormData = {
  tipo_venta: 'POSTPAGO',
  subtipo: '',
  monto_total: 0,
  efectivo_inicial: 0,
  cross_selling: false,
  tienda_destino: '',
  es_remate: false,
  es_extranjero: false,
  cliente_dni: '',
  producto_nombre: '',
  imei_serial: '',
  tipo_pago: 'CONTADO',
  financiera: '',
  precio_venta: 0,
  costo_snap: 0,
  por_cobrar_financiera: 0,
  inventario_tienda_id: 0,
  plan_nombre: '',
  tipo_alta: 'MNP',
  cantidad: 1,
  cobrado_unitario: 0,
  comision_unitaria: 0,
}

// ── Componente VentaCard ─────────────────────────────────────────────────────

function VentaCard({
  index,
  register,
  control,
  errors,
  onRemove,
  planes,
}: {
  index: number
  register: ReturnType<typeof useForm<FormData>>['register']
  control: ReturnType<typeof useForm<FormData>>['control']
  errors: ReturnType<typeof useForm<FormData>>['formState']['errors']
  onRemove: () => void
  planes: Array<{ nombre_plan: string; tipo_alta: string }>
}) {
  const tipo = useWatch({ control, name: `ventas.${index}.tipo_venta` })
  const cross = useWatch({ control, name: `ventas.${index}.cross_selling` })
  const e = errors.ventas?.[index]

  const isEquipo  = tipo === 'EQUIPO' || tipo === 'ACCESORIO'
  const isLinea   = tipo === 'POSTPAGO' || tipo === 'PREPAGO'
  const isOtros   = tipo === 'OTROS_FLUJO'

  return (
    <Card className="p-4 relative">
      <button
        type="button"
        onClick={onRemove}
        className="absolute top-2 right-2 text-gray-400 hover:text-red-500 text-lg leading-none"
        aria-label="Eliminar venta"
      >
        ×
      </button>

      <div className="grid grid-cols-2 gap-3 mb-3">
        <div>
          <Label>Tipo de venta *</Label>
          <Select {...register(`ventas.${index}.tipo_venta`)} className="mt-1">
            <option value="POSTPAGO">Postpago (plan)</option>
            <option value="PREPAGO">Prepago / Chip</option>
            <option value="EQUIPO">Equipo</option>
            <option value="ACCESORIO">Accesorio</option>
            <option value="OTROS_FLUJO">Otros / Flujo</option>
          </Select>
        </div>

        <div>
          <Label>Cliente DNI</Label>
          <Input
            {...register(`ventas.${index}.cliente_dni`)}
            placeholder="12345678"
            maxLength={11}
            className="mt-1"
          />
        </div>
      </div>

      {/* ── Líneas postpago / prepago ── */}
      {isLinea && (
        <div className="grid grid-cols-2 gap-3 mb-3">
          <div>
            <Label>Plan *</Label>
            <Select {...register(`ventas.${index}.plan_nombre`)} className="mt-1">
              <option value="">Selecciona plan</option>
              {planes.map((p, i) => (
                <option key={i} value={p.nombre_plan}>
                  {p.nombre_plan} — {p.tipo_alta}
                </option>
              ))}
            </Select>
            {e?.plan_nombre && <p className="text-red-500 text-xs mt-1">{e.plan_nombre.message}</p>}
          </div>
          <div>
            <Label>Tipo de alta *</Label>
            <Select {...register(`ventas.${index}.tipo_alta`)} className="mt-1">
              <option value="MNP">Portabilidad (MNP)</option>
              <option value="LN">Alta nueva (LN)</option>
              <option value="RECUPERO">Recupero</option>
              <option value="BIFRI">Bifri / Familia</option>
              <option value="TURISTA">Turista</option>
              <option value="PAQUETE">Paquete</option>
            </Select>
          </div>
          <div>
            <Label>Cantidad</Label>
            <Input
              type="number"
              min="1"
              {...register(`ventas.${index}.cantidad`, { valueAsNumber: true })}
              className="mt-1"
            />
          </div>
          <div>
            <Label>Cobrado unitario (S/)</Label>
            <Input
              type="number"
              step="0.01"
              min="0"
              {...register(`ventas.${index}.cobrado_unitario`, { valueAsNumber: true })}
              className="mt-1"
            />
          </div>
          <div className="flex items-center gap-4 mt-2">
            <label className="flex items-center gap-1 text-sm">
              <input type="checkbox" {...register(`ventas.${index}.es_extranjero`)} />
              Extranjero
            </label>
            <label className="flex items-center gap-1 text-sm">
              <input type="checkbox" {...register(`ventas.${index}.es_remate`)} />
              Remate
            </label>
          </div>
        </div>
      )}

      {/* ── Equipo / Accesorio ── */}
      {isEquipo && (
        <div className="grid grid-cols-2 gap-3 mb-3">
          <div className="col-span-2">
            <Label>Producto *</Label>
            <Input
              {...register(`ventas.${index}.producto_nombre`)}
              placeholder="Samsung Galaxy A15 128GB"
              className="mt-1"
            />
            {e?.producto_nombre && <p className="text-red-500 text-xs mt-1">{e.producto_nombre.message}</p>}
          </div>
          <div>
            <Label>IMEI / Serie</Label>
            <Input
              {...register(`ventas.${index}.imei_serial`)}
              placeholder="358461082345678"
              maxLength={50}
              className="mt-1"
            />
          </div>
          <div>
            <Label>Forma de pago</Label>
            <Select {...register(`ventas.${index}.tipo_pago`)} className="mt-1">
              <option value="CONTADO">Contado</option>
              <option value="CUOTAS">Cuotas (financiera)</option>
            </Select>
          </div>
          <div>
            <Label>Financiera</Label>
            <Select {...register(`ventas.${index}.financiera`)} className="mt-1">
              <option value="">Ninguna</option>
              {FINANCIERAS.map((f) => <option key={f} value={f}>{f}</option>)}
            </Select>
          </div>
          <div>
            <Label>Precio de costo (S/)</Label>
            <Input
              type="number"
              step="0.01"
              min="0"
              {...register(`ventas.${index}.costo_snap`, { valueAsNumber: true })}
              className="mt-1"
            />
          </div>
          <div>
            <Label>Por cobrar a financiera (S/)</Label>
            <Input
              type="number"
              step="0.01"
              min="0"
              {...register(`ventas.${index}.por_cobrar_financiera`, { valueAsNumber: true })}
              className="mt-1"
            />
          </div>
        </div>
      )}

      {/* ── Otros flujo ── */}
      {isOtros && (
        <div className="mb-3">
          <Label>Descripción / Motivo</Label>
          <Input
            {...register(`ventas.${index}.subtipo`)}
            placeholder="Devolución, recarga manual, etc."
            className="mt-1"
          />
        </div>
      )}

      {/* ── Campos comunes ── */}
      <div className="grid grid-cols-2 gap-3 border-t pt-3 mt-2">
        <div>
          <Label>Monto total (S/) *</Label>
          <Input
            type="number"
            step="0.01"
            min="0"
            {...register(`ventas.${index}.monto_total`, { valueAsNumber: true })}
            className="mt-1"
          />
          {e?.monto_total && <p className="text-red-500 text-xs mt-1">{e.monto_total.message}</p>}
        </div>
        <div>
          <Label>Efectivo recibido (S/)</Label>
          <Input
            type="number"
            step="0.01"
            min="0"
            {...register(`ventas.${index}.efectivo_inicial`, { valueAsNumber: true })}
            className="mt-1"
          />
        </div>

        <div className="col-span-2 flex items-center gap-4">
          <label className="flex items-center gap-1 text-sm">
            <input type="checkbox" {...register(`ventas.${index}.cross_selling`)} />
            Cross-selling (apoyo a otra tienda)
          </label>
        </div>

        {cross && (
          <div>
            <Label>Tienda destino</Label>
            <Select {...register(`ventas.${index}.tienda_destino`)} className="mt-1">
              <option value="">Selecciona</option>
              {TIENDAS.map((t) => <option key={t} value={t}>{t}</option>)}
            </Select>
          </div>
        )}
      </div>
    </Card>
  )
}

// ── Página principal ─────────────────────────────────────────────────────────

export function NuevoReportePage() {
  const navigate   = useNavigate()
  const { usuario } = useAuth()
  const crear       = useCrearReporte()
  const { data: planesData = [] } = usePlanesComisiones()

  const today = new Date().toISOString().slice(0, 10)

  const { register, control, handleSubmit, watch, setValue, formState: { errors } } =
    useForm<FormData>({
      resolver: zodResolver(schema),
      defaultValues: {
        agente_id:          usuario?.id ?? 0,
        tienda_id:          usuario?.tienda_id ?? '',
        fecha:              today,
        caja_inicial:       0,
        yape:               0,
        bipay:              0,
        transferencia:      0,
        recarga_bipay:      0,
        pago_servicio:      0,
        pago_krece:         0,
        tickets_tusamy:     0,
        retiro_bipay:       0,
        efectivo_entregado: 0,
        total_salidas:      0,
        destino_efectivo:   'TIENDA',
        ventas:             [],
      },
    })

  // Sincronizar agente_id y tienda_id cuando carga el usuario
  useEffect(() => {
    if (usuario) {
      setValue('agente_id', usuario.id)
      setValue('tienda_id', usuario.tienda_id)
    }
  }, [usuario, setValue])

  const { fields, append, remove } = useFieldArray({ control, name: 'ventas' })

  // Totales calculados en tiempo real
  const ventas        = watch('ventas')
  const caja_inicial  = watch('caja_inicial')
  const total_salidas = watch('total_salidas')
  const yape          = watch('yape')
  const bipay         = watch('bipay')
  const transferencia = watch('transferencia')
  const efectivo_entregado = watch('efectivo_entregado')

  const total_calculado   = ventas.reduce((acc, v) => acc + (v.monto_total || 0), 0)
  const suma_efectivo_v   = ventas.reduce((acc, v) => acc + (v.efectivo_inicial || 0), 0)
  const efectivo_esperado = suma_efectivo_v + (caja_inicial || 0) - (total_salidas || 0)
  const diferencia        = (efectivo_entregado || 0) - efectivo_esperado

  const onSubmit = (data: FormData) => {
    crear.mutate(
      { ...data, usuario_id: usuario?.id ?? data.agente_id },
      { onSuccess: () => navigate('/reportes') },
    )
  }

  return (
    <div className="max-w-4xl mx-auto">
      <PageHeader
        title="Nuevo Reporte Diario"
        description="Registra las ventas y el cierre de caja del día."
        actions={
          <Button variant="outline" onClick={() => navigate('/reportes')}>
            Cancelar
          </Button>
        }
      />

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">

        {/* ── Cabecera del reporte ── */}
        <Card className="p-5">
          <h2 className="text-base font-semibold text-gray-800 mb-4">Datos del reporte</h2>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label htmlFor="fecha">Fecha *</Label>
              <Input id="fecha" type="date" {...register('fecha')} className="mt-1" />
              {errors.fecha && <p className="text-red-500 text-xs mt-1">{errors.fecha.message}</p>}
            </div>
            <div>
              <Label htmlFor="tienda_id">Tienda *</Label>
              <Select id="tienda_id" {...register('tienda_id')} className="mt-1">
                <option value="">Selecciona</option>
                {TIENDAS.map((t) => <option key={t} value={t}>{t}</option>)}
              </Select>
              {errors.tienda_id && <p className="text-red-500 text-xs mt-1">{errors.tienda_id.message}</p>}
            </div>
            <div>
              <Label htmlFor="agente_id">ID Agente *</Label>
              <Input
                id="agente_id"
                type="number"
                {...register('agente_id', { valueAsNumber: true })}
                className="mt-1"
              />
              {errors.agente_id && <p className="text-red-500 text-xs mt-1">{errors.agente_id.message}</p>}
            </div>
            <div>
              <Label htmlFor="nombre_cubre">Nombre cubre tienda (si aplica)</Label>
              <Input id="nombre_cubre" {...register('nombre_cubre')} placeholder="Juan Pérez" className="mt-1" />
            </div>
            <div>
              <Label htmlFor="caja_inicial">Caja inicial (S/) *</Label>
              <Input
                id="caja_inicial"
                type="number"
                step="0.01"
                min="0"
                {...register('caja_inicial', { valueAsNumber: true })}
                className="mt-1"
              />
            </div>
            <div>
              <Label htmlFor="total_salidas">Total salidas / adelantos (S/)</Label>
              <Input
                id="total_salidas"
                type="number"
                step="0.01"
                min="0"
                {...register('total_salidas', { valueAsNumber: true })}
                className="mt-1"
              />
            </div>
            <div>
              <Label htmlFor="destino_efectivo">Destino del efectivo</Label>
              <Select id="destino_efectivo" {...register('destino_efectivo')} className="mt-1">
                <option value="TIENDA">En tienda</option>
                <option value="ENTREGADO">Entregado a gerencia</option>
                <option value="EN_CAJA">En caja central</option>
              </Select>
            </div>
          </div>
        </Card>

        {/* ── Medios de cobro ── */}
        <Card className="p-5">
          <h2 className="text-base font-semibold text-gray-800 mb-4">Medios de cobro</h2>
          <div className="grid grid-cols-3 gap-4">
            {([
              ['yape',           'Yape / Plin (S/)'],
              ['bipay',          'Bipay (S/)'],
              ['transferencia',  'Transferencia (S/)'],
              ['recarga_bipay',  'Recarga Bipay (S/)'],
              ['pago_servicio',  'Pago servicios (S/)'],
              ['pago_krece',     'Krece (S/)'],
              ['tickets_tusamy', 'Tickets Tusamy (S/)'],
              ['retiro_bipay',   'Retiro Bipay (S/)'],
            ] as const).map(([field, label]) => (
              <div key={field}>
                <Label>{label}</Label>
                <Input
                  type="number"
                  step="0.01"
                  min="0"
                  {...register(field as keyof FormData, { valueAsNumber: true })}
                  className="mt-1"
                />
              </div>
            ))}
          </div>
        </Card>

        {/* ── Ventas ── */}
        <div>
          <div className="flex items-center justify-between mb-3">
            <h2 className="text-base font-semibold text-gray-800">
              Ventas del día
              {fields.length > 0 && (
                <span className="ml-2 text-sm text-gray-500 font-normal">({fields.length} items)</span>
              )}
            </h2>
            <Button
              type="button"
              variant="outline"
              onClick={() => append({ ...VENTA_DEFAULT })}
            >
              + Agregar venta
            </Button>
          </div>

          {fields.length === 0 ? (
            <div className="border-2 border-dashed border-gray-200 rounded-lg p-8 text-center text-gray-400 text-sm">
              Sin ventas registradas. Haz clic en &ldquo;+ Agregar venta&rdquo; para comenzar.
            </div>
          ) : (
            <div className="space-y-4">
              {fields.map((field, index) => (
                <VentaCard
                  key={field.id}
                  index={index}
                  register={register}
                  control={control}
                  errors={errors}
                  onRemove={() => remove(index)}
                  planes={planesData}
                />
              ))}
            </div>
          )}
        </div>

        {/* ── Resumen calculado ── */}
        <Card className="p-5 bg-gray-50">
          <h2 className="text-base font-semibold text-gray-800 mb-4">Resumen de cierre</h2>
          <div className="grid grid-cols-2 gap-y-2 text-sm">
            <span className="text-gray-600">Total ventas calculado:</span>
            <span className="font-medium">S/ {total_calculado.toFixed(2)}</span>

            <span className="text-gray-600">Efectivo esperado en caja:</span>
            <span className="font-medium">S/ {efectivo_esperado.toFixed(2)}</span>

            <span className="text-gray-600">No-efectivo (Yape + Bipay + Transf.):</span>
            <span className="font-medium">
              S/ {((yape || 0) + (bipay || 0) + (transferencia || 0)).toFixed(2)}
            </span>

            <span className="text-gray-600">Efectivo entregado:</span>
            <div>
              <Input
                type="number"
                step="0.01"
                min="0"
                {...register('efectivo_entregado', { valueAsNumber: true })}
                className="w-36"
              />
            </div>

            <span className="text-gray-600 font-medium">Diferencia:</span>
            <span className={`font-bold text-base ${Math.abs(diferencia) > 0.01 ? 'text-red-600' : 'text-green-600'}`}>
              S/ {diferencia.toFixed(2)}
              {Math.abs(diferencia) > 10 && ' ⚠ Requiere aprobación'}
            </span>
          </div>
        </Card>

        {/* ── Observaciones ── */}
        <Card className="p-5">
          <Label htmlFor="observaciones">Observaciones / Notas del día</Label>
          <textarea
            id="observaciones"
            {...register('observaciones')}
            rows={3}
            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            placeholder="Anotaciones relevantes del día..."
          />
        </Card>

        {crear.isError && (
          <p className="text-red-500 text-sm">
            {(crear.error as { response?: { data?: { error?: string } } })?.response?.data?.error
              ?? 'Error al guardar el reporte. Verifica los datos.'}
          </p>
        )}

        <div className="flex gap-3 pb-6">
          <Button type="submit" disabled={crear.isPending} className="flex-1">
            {crear.isPending ? 'Guardando reporte...' : 'Guardar reporte'}
          </Button>
          <Button type="button" variant="outline" onClick={() => navigate('/reportes')} disabled={crear.isPending}>
            Cancelar
          </Button>
        </div>
      </form>
    </div>
  )
}
