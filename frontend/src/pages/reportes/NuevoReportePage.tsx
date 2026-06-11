import { useEffect, useState } from 'react'
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
import { borradorApi } from '../../services/borrador.api'
import { BipayConsole } from '../../components/BipayConsole'
import { ChipStockBadge } from '../../components/ChipStockBadge'

// ── Constantes ────────────────────────────────────────────────────────────────

const TIENDAS = [
  'PUNDA50','PUNDA11','PUNSC01','PUNDA23',
  'TACDA13','TACDA17','TACDA21','TACDA25','TACDA27','TACDA30',
]

const FINANCIERAS = ['PayJoy', 'Krece', 'Tasa Cero', 'Otro']

const TIPOS_ALTA = [
  { value: 'MNP',     label: 'Portabilidad (MNP)' },
  { value: 'LN',      label: 'Alta nueva (LN)'    },
  { value: 'RECUPERO',label: 'Recupero'            },
  { value: 'BIFRI',   label: 'Bifri / Familia'     },
  { value: 'TURISTA', label: 'Turista'             },
  { value: 'PAQUETE', label: 'Paquete'             },
]

const TIPOS_SALIDA = ['Pasaje','Gasto','Adelanto','Otro']

// ── Zod ───────────────────────────────────────────────────────────────────────

const ventaSchema = z.object({
  tipo_venta:           z.enum(['EQUIPO','ACCESORIO','POSTPAGO','PREPAGO','OTROS_FLUJO']),
  subtipo:              z.string().optional().or(z.literal('')),
  monto_total:          z.number().min(0),
  efectivo_inicial:     z.number().min(0),
  cross_selling:        z.boolean(),
  tienda_destino:       z.string().optional().or(z.literal('')),
  es_remate:            z.boolean(),
  es_extranjero:        z.boolean(),
  cliente_dni:          z.string().max(15).optional().or(z.literal('')),
  producto_nombre:      z.string().optional().or(z.literal('')),
  imei_serial:          z.string().optional().or(z.literal('')),
  tipo_pago:            z.enum(['CONTADO','CUOTAS']),
  financiera:           z.string().optional().or(z.literal('')),
  precio_venta:         z.number().min(0),
  costo_snap:           z.number().min(0),
  por_cobrar_financiera:z.number().min(0),
  inventario_tienda_id: z.number().int().min(0),
  plan_nombre:          z.string().optional().or(z.literal('')),
  tipo_alta:            z.string().optional().or(z.literal('')),
  cantidad:             z.number().int().min(1),
  cobrado_unitario:     z.number().min(0),
  comision_unitaria:    z.number().min(0),
})

const schema = z.object({
  agente_id:          z.number().int().min(1),
  tienda_id:          z.string().min(1,'Selecciona una tienda'),
  fecha:              z.string().min(1,'La fecha es obligatoria'),
  nombre_cubre:       z.string().optional().or(z.literal('')),
  caja_inicial:       z.number().min(0),
  yape:               z.number().min(0),
  bipay:              z.number().min(0),
  transferencia:      z.number().min(0),
  retiro_bipay:       z.number().min(0),
  recarga_bipay:      z.number().min(0),
  pago_servicio:      z.number().min(0),
  pago_krece:         z.number().min(0),
  tickets_tusamy:     z.number().min(0),
  efectivo_entregado: z.number().min(0),
  total_salidas:      z.number().min(0),
  destino_efectivo:   z.enum(['TIENDA','ENTREGADO','EN_CAJA']),
  observaciones:      z.string().optional().or(z.literal('')),
  obs_dia:            z.string().optional().or(z.literal('')),
  ventas:             z.array(ventaSchema),
})

type FormData     = z.infer<typeof schema>
type VentaFormData = z.infer<typeof ventaSchema>

const VENTA_DEFAULT: VentaFormData = {
  tipo_venta:'POSTPAGO', subtipo:'', monto_total:0, efectivo_inicial:0,
  cross_selling:false, tienda_destino:'', es_remate:false, es_extranjero:false,
  cliente_dni:'', producto_nombre:'', imei_serial:'', tipo_pago:'CONTADO',
  financiera:'', precio_venta:0, costo_snap:0, por_cobrar_financiera:0,
  inventario_tienda_id:0, plan_nombre:'', tipo_alta:'MNP', cantidad:1,
  cobrado_unitario:0, comision_unitaria:0,
}

// ── Salidas locales (no van al schema, se suman en total_salidas) ─────────────

type SalidaItem = { id: string; tipo: string; monto: number; motivo: string }

// ── Componente: fila compacta para POSTPAGO / PREPAGO ────────────────────────

function LineaRow({
  index, register, control, errors, tipo, onRemove, planes,
}: {
  index: number
  register: ReturnType<typeof useForm<FormData>>['register']
  control: ReturnType<typeof useForm<FormData>>['control']
  errors: ReturnType<typeof useForm<FormData>>['formState']['errors']
  tipo: 'POSTPAGO' | 'PREPAGO'
  onRemove: () => void
  planes: Array<{ nombre_plan: string; tipo_alta: string }>
}) {
  const cross = useWatch({ control, name: `ventas.${index}.cross_selling` })
  const e     = errors.ventas?.[index]

  return (
    <div className="grid grid-cols-[120px_1fr_130px_80px_90px_auto] gap-1.5 items-end py-1.5 border-b border-gray-100 last:border-0">
      {/* DNI */}
      <div>
        <Input
          {...register(`ventas.${index}.cliente_dni`)}
          placeholder="DNI / Celular"
          maxLength={15}
          className="h-8 text-xs"
        />
      </div>

      {/* Plan */}
      <div>
        <Select {...register(`ventas.${index}.plan_nombre`)} className="h-8 text-xs">
          <option value="">— Plan —</option>
          {planes.map((p, i) => (
            <option key={i} value={p.nombre_plan}>
              {p.nombre_plan} ({p.tipo_alta})
            </option>
          ))}
        </Select>
        {e?.plan_nombre && <p className="text-red-500 text-[10px]">{e.plan_nombre.message}</p>}
      </div>

      {/* Tipo Alta */}
      <div>
        <Select {...register(`ventas.${index}.tipo_alta`)} className="h-8 text-xs">
          {TIPOS_ALTA.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
        </Select>
      </div>

      {/* Cobrado */}
      <div>
        <Input
          type="number" step="0.01" min="0"
          {...register(`ventas.${index}.cobrado_unitario`, { valueAsNumber: true })}
          placeholder="S/"
          className="h-8 text-xs"
        />
      </div>

      {/* Toggles */}
      <div className="flex flex-col gap-0.5 text-[10px]">
        <label className="flex items-center gap-1 cursor-pointer">
          <input type="checkbox" {...register(`ventas.${index}.es_extranjero`)} className="w-3 h-3" />
          Ext
        </label>
        <label className="flex items-center gap-1 cursor-pointer">
          <input type="checkbox" {...register(`ventas.${index}.es_remate`)} className="w-3 h-3" />
          Rem
        </label>
        <label className="flex items-center gap-1 cursor-pointer">
          <input type="checkbox" {...register(`ventas.${index}.cross_selling`)} className="w-3 h-3" />
          Cruz
        </label>
        {tipo === 'PREPAGO' && (
          <label className="flex items-center gap-1 cursor-pointer">
            <input type="checkbox" {...register(`ventas.${index}.comision_unitaria`, { valueAsNumber: true })} className="w-3 h-3" />
            eSIM
          </label>
        )}
      </div>

      {/* Eliminar */}
      <button
        type="button" onClick={onRemove}
        className="text-red-400 hover:text-red-600 font-bold text-lg leading-none self-center"
      >×</button>

      {/* Cross-selling expand */}
      {cross && (
        <div className="col-span-full pl-0 pt-1">
          <Label className="text-[10px] text-gray-500">Tienda destino (apoyo)</Label>
          <Select {...register(`ventas.${index}.tienda_destino`)} className="h-7 text-xs w-40 mt-0.5">
            <option value="">— Selecciona —</option>
            {TIENDAS.map(t => <option key={t} value={t}>{t}</option>)}
          </Select>
        </div>
      )}

      {/* Monto total oculto — para POSTPAGO/PREPAGO = cobrado_unitario × cantidad */}
      <input type="hidden" {...register(`ventas.${index}.monto_total`, { valueAsNumber: true })} />
      <input type="hidden" {...register(`ventas.${index}.efectivo_inicial`, { valueAsNumber: true })} />
    </div>
  )
}

// ── Componente: fila compacta para EQUIPO / ACCESORIO ────────────────────────

function EquipoRow({
  index, register, control, errors, onRemove,
}: {
  index: number
  register: ReturnType<typeof useForm<FormData>>['register']
  control: ReturnType<typeof useForm<FormData>>['control']
  errors: ReturnType<typeof useForm<FormData>>['formState']['errors']
  onRemove: () => void
}) {
  const tipoPago = useWatch({ control, name: `ventas.${index}.tipo_pago` })
  const e        = errors.ventas?.[index]

  return (
    <div className="space-y-1 py-1.5 border-b border-gray-100 last:border-0">
      <div className="grid grid-cols-[1fr_140px_110px_90px_auto] gap-1.5 items-end">
        {/* Producto */}
        <div>
          <Input
            {...register(`ventas.${index}.producto_nombre`)}
            placeholder="Producto (nombre o búsqueda)"
            className="h-8 text-xs"
          />
          {e?.producto_nombre && <p className="text-red-500 text-[10px]">{e.producto_nombre.message}</p>}
        </div>

        {/* IMEI */}
        <div>
          <Input
            {...register(`ventas.${index}.imei_serial`)}
            placeholder="IMEI / Serie"
            maxLength={50}
            className="h-8 text-xs"
          />
        </div>

        {/* Tipo pago */}
        <div>
          <Select {...register(`ventas.${index}.tipo_pago`)} className="h-8 text-xs">
            <option value="CONTADO">Contado</option>
            <option value="CUOTAS">A cuotas</option>
          </Select>
        </div>

        {/* Precio venta */}
        <div>
          <Input
            type="number" step="0.01" min="0"
            {...register(`ventas.${index}.precio_venta`, { valueAsNumber: true })}
            placeholder="Precio S/"
            className="h-8 text-xs"
          />
        </div>

        {/* Eliminar */}
        <button
          type="button" onClick={onRemove}
          className="text-red-400 hover:text-red-600 font-bold text-lg leading-none self-center"
        >×</button>
      </div>

      {/* Fila extra si es cuotas */}
      {tipoPago === 'CUOTAS' && (
        <div className="grid grid-cols-[170px_120px_120px] gap-1.5 pl-2">
          <div>
            <Label className="text-[10px]">Financiera</Label>
            <Select {...register(`ventas.${index}.financiera`)} className="h-7 text-xs mt-0.5">
              <option value="">Ninguna</option>
              {FINANCIERAS.map(f => <option key={f} value={f}>{f}</option>)}
            </Select>
          </div>
          <div>
            <Label className="text-[10px]">Por cobrar financiera (S/)</Label>
            <Input
              type="number" step="0.01" min="0"
              {...register(`ventas.${index}.por_cobrar_financiera`, { valueAsNumber: true })}
              className="h-7 text-xs mt-0.5"
            />
          </div>
          <div>
            <Label className="text-[10px]">Costo snap (S/)</Label>
            <Input
              type="number" step="0.01" min="0"
              {...register(`ventas.${index}.costo_snap`, { valueAsNumber: true })}
              className="h-7 text-xs mt-0.5"
            />
          </div>
        </div>
      )}

      {/* DNI cliente */}
      <div className="pl-2">
        <Input
          {...register(`ventas.${index}.cliente_dni`)}
          placeholder="DNI cliente (opcional)"
          maxLength={15}
          className="h-7 text-xs w-48"
        />
      </div>

      {/* Monto total — para equipos = precio_venta */}
      <input type="hidden" {...register(`ventas.${index}.monto_total`, { valueAsNumber: true })} />
      <input type="hidden" {...register(`ventas.${index}.efectivo_inicial`, { valueAsNumber: true })} />
    </div>
  )
}

// ── Componente: fila compacta para OTROS_FLUJO ───────────────────────────────

function OtroRow({
  index, register, errors, onRemove,
}: {
  index: number
  register: ReturnType<typeof useForm<FormData>>['register']
  errors: ReturnType<typeof useForm<FormData>>['formState']['errors']
  onRemove: () => void
}) {
  const e = errors.ventas?.[index]
  return (
    <div className="grid grid-cols-[1fr_100px_auto] gap-1.5 items-end py-1.5 border-b border-gray-100 last:border-0">
      <div>
        <Input
          {...register(`ventas.${index}.subtipo`)}
          placeholder="Descripción / Motivo"
          className="h-8 text-xs"
        />
      </div>
      <div>
        <Input
          type="number" step="0.01" min="0"
          {...register(`ventas.${index}.monto_total`, { valueAsNumber: true })}
          placeholder="S/"
          className="h-8 text-xs"
        />
        {e?.monto_total && <p className="text-red-500 text-[10px]">{e.monto_total.message}</p>}
      </div>
      <button
        type="button" onClick={onRemove}
        className="text-red-400 hover:text-red-600 font-bold text-lg leading-none"
      >×</button>
      <input type="hidden" {...register(`ventas.${index}.efectivo_inicial`, { valueAsNumber: true })} />
    </div>
  )
}

// ── Sección de ventas (con botón de agregar) ──────────────────────────────────

function VentasSection({ title, count, addLabel, onAdd, children }: {
  title: string; count: number; addLabel: string; onAdd: () => void; children: React.ReactNode
}) {
  return (
    <div className="border border-gray-200 rounded-lg overflow-hidden">
      <div className="bg-gray-50 px-3 py-2 flex items-center justify-between border-b border-gray-200">
        <span className="text-xs font-semibold text-gray-700 uppercase tracking-wide">
          {title}
          {count > 0 && (
            <span className="ml-2 bg-blue-100 text-blue-700 rounded-full px-1.5 py-0.5 text-[10px] font-bold">
              {count}
            </span>
          )}
        </span>
        <button
          type="button"
          onClick={onAdd}
          className="text-xs text-blue-600 hover:text-blue-800 font-medium flex items-center gap-1"
        >
          <span className="text-base leading-none">+</span> {addLabel}
        </button>
      </div>
      <div className="px-3 py-1 min-h-[40px]">
        {count === 0
          ? <p className="text-[11px] text-gray-400 py-2 text-center italic">Sin registros. Haz clic en &ldquo;{addLabel}&rdquo;</p>
          : children
        }
      </div>
    </div>
  )
}

// ── Página principal ──────────────────────────────────────────────────────────

export function NuevoReportePage() {
  const navigate    = useNavigate()
  const { usuario } = useAuth()
  const crear       = useCrearReporte()
  const { data: planesData = [] } = usePlanesComisiones()

  const today = new Date().toISOString().slice(0, 10)

  const { register, control, handleSubmit, watch, setValue, getValues, reset, formState: { errors } } =
    useForm<FormData>({
      resolver: zodResolver(schema),
      defaultValues: {
        agente_id: usuario?.id ?? 0, tienda_id: usuario?.tienda_id ?? '',
        fecha: today, nombre_cubre: '',
        caja_inicial: 0, yape: 0, bipay: 0, transferencia: 0,
        retiro_bipay: 0, recarga_bipay: 0, pago_servicio: 0,
        pago_krece: 0, tickets_tusamy: 0,
        efectivo_entregado: 0, total_salidas: 0,
        destino_efectivo: 'TIENDA', observaciones: '', obs_dia: '',
        ventas: [],
      },
    })

  useEffect(() => {
    if (usuario) {
      setValue('agente_id', usuario.id)
      setValue('tienda_id', usuario.tienda_id)
    }
  }, [usuario, setValue])

  const { fields, append, remove } = useFieldArray({ control, name: 'ventas' })

  // ── Salidas de efectivo (estado local) ─────────────────────────────────────
  const [salidaItems, setSalidaItems] = useState<SalidaItem[]>([])

  const agregarSalida = () =>
    setSalidaItems(prev => [...prev, { id: crypto.randomUUID(), tipo: 'Pasaje', monto: 0, motivo: '' }])

  const actualizarSalida = (id: string, campo: keyof SalidaItem, valor: string | number) =>
    setSalidaItems(prev => prev.map(s => s.id === id ? { ...s, [campo]: valor } : s))

  const eliminarSalida = (id: string) =>
    setSalidaItems(prev => prev.filter(s => s.id !== id))

  // Sincronizar total_salidas con el form
  useEffect(() => {
    const total = salidaItems.reduce((acc, s) => acc + (Number(s.monto) || 0), 0)
    setValue('total_salidas', Math.round(total * 100) / 100)
  }, [salidaItems, setValue])

  // ── Borrador en la nube (auto-save 60s + manual) ──────────────────────────────
  const esTienda = usuario?.rol === 'tienda'
  const [borradorMsg, setBorradorMsg] = useState('')
  const [borradorDisponible, setBorradorDisponible] = useState<Record<string, unknown> | null>(null)

  async function guardarBorrador(silencioso = false) {
    try {
      await borradorApi.guardar({ form: getValues(), salidaItems } as unknown as Record<string, unknown>)
      if (!silencioso) { setBorradorMsg('Borrador guardado'); setTimeout(() => setBorradorMsg(''), 2000) }
    } catch { if (!silencioso) setBorradorMsg('No se pudo guardar el borrador') }
  }

  function restaurarBorrador(data: Record<string, unknown>) {
    const d = data as { form?: FormData; salidaItems?: SalidaItem[] }
    if (d.form) reset(d.form)
    if (Array.isArray(d.salidaItems)) setSalidaItems(d.salidaItems)
    setBorradorDisponible(null)
    setBorradorMsg('Borrador cargado')
    setTimeout(() => setBorradorMsg(''), 2000)
  }

  // Detectar borrador disponible al entrar (solo tienda)
  useEffect(() => {
    if (!esTienda) return
    borradorApi.cargar().then((r) => { if (r.borrador) setBorradorDisponible(r.borrador) }).catch(() => {})
  }, [esTienda])

  // Auto-guardado cada 60s
  useEffect(() => {
    if (!esTienda) return
    const t = setInterval(() => guardarBorrador(true), 60_000)
    return () => clearInterval(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [esTienda, salidaItems])

  // ── Totales en tiempo real ─────────────────────────────────────────────────
  const ventas            = watch('ventas')
  const caja_inicial      = watch('caja_inicial')      || 0
  const total_salidas     = watch('total_salidas')     || 0
  const yape              = watch('yape')              || 0
  const bipay             = watch('bipay')             || 0
  const transferencia     = watch('transferencia')     || 0
  const retiro_bipay      = watch('retiro_bipay')      || 0
  const efectivo_entregado= watch('efectivo_entregado')|| 0
  const destino           = watch('destino_efectivo')

  // Para POSTPAGO/PREPAGO: cobrado_unitario × cantidad = monto_total
  // Sincronizamos monto_total y efectivo_inicial en tiempo real
  useEffect(() => {
    ventas.forEach((v, i) => {
      if (v.tipo_venta === 'POSTPAGO' || v.tipo_venta === 'PREPAGO') {
        const monto = (v.cobrado_unitario || 0) * (v.cantidad || 1)
        if (v.monto_total !== monto) setValue(`ventas.${i}.monto_total`, monto)
        if (v.efectivo_inicial !== monto) setValue(`ventas.${i}.efectivo_inicial`, monto)
      }
      if (v.tipo_venta === 'EQUIPO' || v.tipo_venta === 'ACCESORIO') {
        const precio = v.precio_venta || 0
        if (v.monto_total !== precio) setValue(`ventas.${i}.monto_total`, precio)
        if (v.efectivo_inicial !== precio) setValue(`ventas.${i}.efectivo_inicial`, precio)
      }
    })
  }, [ventas, setValue])

  const ventasMap         = fields.map((f, i) => ({ ...f, idx: i, tipo: ventas[i]?.tipo_venta }))
  const postpagoRows      = ventasMap.filter(v => v.tipo === 'POSTPAGO')
  const prepagoRows       = ventasMap.filter(v => v.tipo === 'PREPAGO')
  const equipoRows        = ventasMap.filter(v => v.tipo === 'EQUIPO' || v.tipo === 'ACCESORIO')
  const otrosRows         = ventasMap.filter(v => v.tipo === 'OTROS_FLUJO')

  const totalVentas       = ventas.reduce((acc, v) => acc + (v.monto_total || 0), 0)
  const totalPostpago     = ventas.filter(v => v.tipo_venta === 'POSTPAGO').reduce((a, v) => a + (v.monto_total||0), 0)
  const totalPrepago      = ventas.filter(v => v.tipo_venta === 'PREPAGO').reduce((a, v) => a + (v.monto_total||0), 0)
  const totalEquipos      = ventas.filter(v => v.tipo_venta === 'EQUIPO'||v.tipo_venta === 'ACCESORIO').reduce((a,v)=>a+(v.monto_total||0),0)
  const totalOtros        = ventas.filter(v => v.tipo_venta === 'OTROS_FLUJO').reduce((a, v) => a + (v.monto_total||0), 0)

  const sumaEfectivoVentas = ventas.reduce((acc, v) => acc + (v.efectivo_inicial || 0), 0)
  const efectivo_esperado  = sumaEfectivoVentas + caja_inicial - total_salidas
  const diferencia         = efectivo_entregado - efectivo_esperado
  const requiereAprobacion = Math.abs(diferencia) > 10

  const onSubmit = (data: FormData) => {
    crear.mutate(
      { ...data, usuario_id: usuario?.id ?? data.agente_id },
      { onSuccess: () => navigate('/reportes') },
    )
  }

  return (
    <div className="max-w-7xl mx-auto">
      <PageHeader
        title="Nuevo Reporte Diario"
        description="Cierre de caja y ventas del día."
        actions={
          <div className="flex items-center gap-2">
            {esTienda && <ChipStockBadge />}
            {esTienda && borradorDisponible && (
              <Button variant="outline" type="button" className="text-amber-600 border-amber-400"
                onClick={() => restaurarBorrador(borradorDisponible)}>
                Cargar Borrador
              </Button>
            )}
            {esTienda && (
              <Button variant="outline" type="button" onClick={() => guardarBorrador(false)}>
                Guardar Borrador
              </Button>
            )}
            {borradorMsg && <span className="text-xs text-zinc-500">{borradorMsg}</span>}
            <Button variant="outline" onClick={() => navigate('/reportes')}>
              Cancelar
            </Button>
          </div>
        }
      />

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">

        {/* ── Consola Bipay/Anypay (rol tienda) ── */}
        {esTienda && <BipayConsole />}

        {/* ── Cabecera ── */}
        <Card className="p-4">
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div>
              <Label htmlFor="fecha" className="text-xs font-medium text-gray-600">Fecha *</Label>
              <Input id="fecha" type="date" {...register('fecha')} className="mt-1 h-8 text-sm" />
              {errors.fecha && <p className="text-red-500 text-[10px] mt-0.5">{errors.fecha.message}</p>}
            </div>
            <div>
              <Label htmlFor="tienda_id" className="text-xs font-medium text-gray-600">Tienda *</Label>
              <Select id="tienda_id" {...register('tienda_id')} className="mt-1 h-8 text-sm">
                <option value="">— Selecciona —</option>
                {TIENDAS.map(t => <option key={t} value={t}>{t}</option>)}
              </Select>
              {errors.tienda_id && <p className="text-red-500 text-[10px] mt-0.5">{errors.tienda_id.message}</p>}
            </div>
            <div>
              <Label htmlFor="nombre_cubre" className="text-xs font-medium text-gray-600">Cubre tienda (si aplica)</Label>
              <Input id="nombre_cubre" {...register('nombre_cubre')} placeholder="Nombre" className="mt-1 h-8 text-sm" />
            </div>
            <div className="flex items-end">
              <div className="text-xs text-gray-500 bg-gray-50 rounded px-2 py-1.5 w-full">
                <span className="text-gray-400">Agente:</span>{' '}
                <span className="font-medium text-gray-700">{usuario?.nombre ?? '—'}</span>
                <input type="hidden" {...register('agente_id', { valueAsNumber: true })} />
              </div>
            </div>
          </div>
        </Card>

        {/* ── Cuerpo: dos columnas ── */}
        <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,2fr)_minmax(0,380px)] gap-4 items-start">

          {/* ═══════════ COLUMNA IZQUIERDA: Ventas ═══════════ */}
          <div className="space-y-3">

            {/* Postpago */}
            <VentasSection
              title="Ventas Postpago"
              count={postpagoRows.length}
              addLabel="Agregar Postpago"
              onAdd={() => append({ ...VENTA_DEFAULT, tipo_venta: 'POSTPAGO', tipo_alta: 'MNP' })}
            >
              <div className="grid grid-cols-[120px_1fr_130px_80px_90px_auto] gap-1.5 py-1 text-[10px] text-gray-400 font-medium border-b border-dashed border-gray-100">
                <span>DNI / Cel.</span><span>Plan</span><span>Tipo alta</span>
                <span>Cobrado</span><span>Opciones</span><span />
              </div>
              {postpagoRows.map(v => (
                <LineaRow
                  key={v.id} index={v.idx} register={register} control={control}
                  errors={errors} tipo="POSTPAGO" planes={planesData} onRemove={() => remove(v.idx)}
                />
              ))}
              {postpagoRows.length > 0 && (
                <div className="text-right text-xs font-semibold text-gray-700 pt-1">
                  Subtotal: <span className="text-blue-700">S/ {totalPostpago.toFixed(2)}</span>
                </div>
              )}
            </VentasSection>

            {/* Prepago */}
            <VentasSection
              title="Ventas Prepago / Chips"
              count={prepagoRows.length}
              addLabel="Agregar Prepago"
              onAdd={() => append({ ...VENTA_DEFAULT, tipo_venta: 'PREPAGO', tipo_alta: 'LN' })}
            >
              <div className="grid grid-cols-[120px_1fr_130px_80px_90px_auto] gap-1.5 py-1 text-[10px] text-gray-400 font-medium border-b border-dashed border-gray-100">
                <span>DNI / Cel.</span><span>Plan</span><span>Tipo alta</span>
                <span>Cobrado</span><span>Opciones</span><span />
              </div>
              {prepagoRows.map(v => (
                <LineaRow
                  key={v.id} index={v.idx} register={register} control={control}
                  errors={errors} tipo="PREPAGO" planes={planesData} onRemove={() => remove(v.idx)}
                />
              ))}
              {prepagoRows.length > 0 && (
                <div className="text-right text-xs font-semibold text-gray-700 pt-1">
                  Subtotal: <span className="text-blue-700">S/ {totalPrepago.toFixed(2)}</span>
                </div>
              )}
            </VentasSection>

            {/* Equipos y accesorios */}
            <VentasSection
              title="Equipos y Accesorios"
              count={equipoRows.length}
              addLabel="Vender de Stock"
              onAdd={() => append({ ...VENTA_DEFAULT, tipo_venta: 'EQUIPO' })}
            >
              {equipoRows.map(v => (
                <EquipoRow
                  key={v.id} index={v.idx} register={register} control={control}
                  errors={errors} onRemove={() => remove(v.idx)}
                />
              ))}
              {equipoRows.length > 0 && (
                <div className="flex items-center justify-between pt-1">
                  <button
                    type="button"
                    onClick={() => append({ ...VENTA_DEFAULT, tipo_venta: 'ACCESORIO' })}
                    className="text-xs text-purple-600 hover:text-purple-800 font-medium"
                  >
                    + Agregar Accesorio
                  </button>
                  <span className="text-xs font-semibold text-gray-700">
                    Subtotal: <span className="text-blue-700">S/ {totalEquipos.toFixed(2)}</span>
                  </span>
                </div>
              )}
            </VentasSection>

            {/* Otros flujo */}
            <VentasSection
              title="Otros Ingresos (Flujo)"
              count={otrosRows.length}
              addLabel="Agregar"
              onAdd={() => append({ ...VENTA_DEFAULT, tipo_venta: 'OTROS_FLUJO' })}
            >
              {otrosRows.map(v => (
                <OtroRow
                  key={v.id} index={v.idx} register={register}
                  errors={errors} onRemove={() => remove(v.idx)}
                />
              ))}
              {otrosRows.length > 0 && (
                <div className="text-right text-xs font-semibold text-gray-700 pt-1">
                  Subtotal: <span className="text-blue-700">S/ {totalOtros.toFixed(2)}</span>
                </div>
              )}
            </VentasSection>

            {/* ── Consolidado de ventas ── */}
            <Card className="p-3 bg-slate-800 text-white">
              <p className="text-xs text-slate-400 uppercase tracking-widest mb-2 font-medium">Total Sistema Consolidado</p>
              <div className="grid grid-cols-4 gap-2 text-center text-xs mb-3">
                {[
                  { label: 'Postpago', val: totalPostpago },
                  { label: 'Prepago',  val: totalPrepago  },
                  { label: 'Equipos',  val: totalEquipos  },
                  { label: 'Otros',    val: totalOtros    },
                ].map(({ label, val }) => (
                  <div key={label} className="bg-slate-700 rounded px-2 py-1">
                    <div className="text-slate-400 text-[10px]">{label}</div>
                    <div className="font-semibold">S/ {val.toFixed(2)}</div>
                  </div>
                ))}
              </div>
              <div className="text-center">
                <span className="text-slate-400 text-xs">TOTAL DEL DÍA</span>
                <div className="font-mono text-cyan-400 text-3xl font-bold">S/ {totalVentas.toFixed(2)}</div>
              </div>
            </Card>

          </div>

          {/* ═══════════ COLUMNA DERECHA: Caja y Dinero ═══════════ */}
          <div className="space-y-3 lg:sticky lg:top-4">

            {/* Caja inicial */}
            <Card className="p-3">
              <Label className="text-xs font-semibold text-gray-700 uppercase tracking-wide">Caja Inicial (Sencillo)</Label>
              <Input
                id="caja_inicial" type="number" step="0.01" min="0"
                {...register('caja_inicial', { valueAsNumber: true })}
                className="mt-1.5 h-9 text-sm font-medium"
                placeholder="S/ 0.00"
              />
            </Card>

            {/* Medios no físicos */}
            <Card className="p-3 space-y-2">
              <p className="text-xs font-semibold text-gray-700 uppercase tracking-wide">Dinero No Físico</p>
              {([
                ['yape',          'Yape / Plin'],
                ['bipay',         'Bipay'],
                ['transferencia', 'Transferencia'],
              ] as const).map(([field, label]) => (
                <div key={field} className="flex items-center gap-2">
                  <Label className="text-xs text-gray-600 w-28 shrink-0">{label}</Label>
                  <Input
                    type="number" step="0.01" min="0"
                    {...register(field, { valueAsNumber: true })}
                    className="h-7 text-xs text-right"
                    placeholder="0.00"
                  />
                </div>
              ))}
              <div className="flex items-center gap-2 border-t pt-2">
                <Label className="text-xs text-red-600 w-28 shrink-0 font-medium">Retiro Bipay</Label>
                <Input
                  type="number" step="0.01" min="0"
                  {...register('retiro_bipay', { valueAsNumber: true })}
                  className="h-7 text-xs text-right border-red-200 focus:border-red-400"
                  placeholder="0.00"
                />
              </div>
              <div className="text-right text-xs text-gray-500 pt-1">
                Total no físico:{' '}
                <span className="font-semibold text-gray-700">
                  S/ {(yape + bipay + transferencia - retiro_bipay).toFixed(2)}
                </span>
              </div>
            </Card>

            {/* Ingresos fijos */}
            <Card className="p-3 space-y-2">
              <p className="text-xs font-semibold text-gray-700 uppercase tracking-wide">Ingresos Fijos</p>
              {([
                ['recarga_bipay', 'Recarga Bipay'],
                ['pago_servicio', 'Pago de Servicio'],
                ['pago_krece',    'Pago Krece'],
                ['tickets_tusamy','Tickets Tusamy'],
              ] as const).map(([field, label]) => (
                <div key={field} className="flex items-center gap-2">
                  <Label className="text-xs text-gray-600 w-28 shrink-0">{label}</Label>
                  <Input
                    type="number" step="0.01" min="0"
                    {...register(field, { valueAsNumber: true })}
                    className="h-7 text-xs text-right"
                    placeholder="0.00"
                  />
                </div>
              ))}
            </Card>

            {/* Salidas de efectivo */}
            <Card className="p-3">
              <div className="flex items-center justify-between mb-2">
                <p className="text-xs font-semibold text-gray-700 uppercase tracking-wide">Salidas de Efectivo</p>
                <button
                  type="button" onClick={agregarSalida}
                  className="text-xs text-red-500 border border-red-300 rounded px-2 py-0.5 hover:bg-red-50 font-medium"
                >
                  + Agregar Salida
                </button>
              </div>
              {salidaItems.length === 0
                ? <p className="text-[11px] text-gray-400 italic text-center py-1">Sin salidas registradas</p>
                : (
                  <div className="space-y-1.5">
                    {salidaItems.map(s => (
                      <div key={s.id} className="grid grid-cols-[90px_70px_1fr_auto] gap-1 items-center">
                        <select
                          value={s.tipo}
                          onChange={e => actualizarSalida(s.id, 'tipo', e.target.value)}
                          className="h-7 text-xs rounded border border-gray-300 px-1"
                        >
                          {TIPOS_SALIDA.map(t => <option key={t} value={t}>{t}</option>)}
                        </select>
                        <input
                          type="number" step="0.01" min="0" value={s.monto || ''}
                          onChange={e => actualizarSalida(s.id, 'monto', parseFloat(e.target.value) || 0)}
                          placeholder="S/"
                          className="h-7 text-xs rounded border border-gray-300 px-2 text-right"
                        />
                        <input
                          type="text" value={s.motivo}
                          onChange={e => actualizarSalida(s.id, 'motivo', e.target.value)}
                          placeholder="Motivo"
                          className="h-7 text-xs rounded border border-gray-300 px-2"
                        />
                        <button
                          type="button" onClick={() => eliminarSalida(s.id)}
                          className="text-red-400 hover:text-red-600 font-bold text-sm leading-none"
                        >×</button>
                      </div>
                    ))}
                    <div className="text-right text-xs font-semibold text-red-600 pt-1">
                      Total salidas: S/ {total_salidas.toFixed(2)}
                    </div>
                  </div>
                )
              }
            </Card>

            {/* ── Cuadre Final ── */}
            <Card className="p-3 border-2 border-green-400 bg-green-50">
              <p className="text-xs font-bold text-green-800 uppercase tracking-wide mb-3">Cuadre Final</p>

              <div className="space-y-2 text-sm">
                <div className="flex justify-between items-center">
                  <span className="text-xs text-gray-600">Efectivo esperado en cajón:</span>
                  <span className="font-semibold text-gray-800">S/ {efectivo_esperado.toFixed(2)}</span>
                </div>

                <div className="flex justify-between items-center">
                  <Label className="text-xs font-medium text-gray-700">Mi Efectivo (entrego):</Label>
                  <Input
                    type="number" step="0.01" min="0"
                    {...register('efectivo_entregado', { valueAsNumber: true })}
                    className="h-8 w-32 text-sm text-right font-semibold"
                    placeholder="0.00"
                  />
                </div>

                <div className="flex justify-between items-center pt-1 border-t border-green-200">
                  <span className="text-xs font-bold text-gray-700">Diferencia:</span>
                  <span className={`font-mono font-bold text-base ${
                    Math.abs(diferencia) < 0.01 ? 'text-green-700'
                    : diferencia < 0             ? 'text-red-600'
                                                 : 'text-yellow-600'
                  }`}>
                    S/ {diferencia.toFixed(2)}
                    {requiereAprobacion && ' ⚠'}
                  </span>
                </div>

                {requiereAprobacion && (
                  <p className="text-[10px] text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1">
                    Diferencia mayor a S/10 — el reporte quedará en espera de aprobación.
                  </p>
                )}
              </div>

              {/* Destino del efectivo */}
              <div className="mt-3 space-y-1.5">
                <p className="text-xs font-medium text-gray-700">Destino del efectivo:</p>
                {[
                  { value: 'ENTREGADO', label: 'Lo Entregué (a gerencia / depósito)' },
                  { value: 'TIENDA',    label: 'En Tienda (queda en caja)' },
                  { value: 'EN_CAJA',   label: 'En Caja Central' },
                ].map(opt => (
                  <label key={opt.value} className="flex items-center gap-2 cursor-pointer text-xs text-gray-700">
                    <input
                      type="radio"
                      value={opt.value}
                      {...register('destino_efectivo')}
                      className="w-3.5 h-3.5 accent-green-600"
                    />
                    {opt.label}
                  </label>
                ))}
              </div>

              {/* Observaciones del destino — solo cuando ENTREGADO */}
              {destino === 'ENTREGADO' && (
                <div className="mt-2">
                  <Label className="text-xs text-gray-600">A quién / referencia de depósito *</Label>
                  <Input
                    {...register('observaciones')}
                    placeholder="Nombre o número de operación"
                    className="mt-1 h-8 text-xs"
                  />
                </div>
              )}
            </Card>

            {/* Observaciones del día */}
            <Card className="p-3">
              <Label className="text-xs font-semibold text-gray-700">Observaciones del Día</Label>
              <textarea
                {...register('obs_dia')}
                rows={2}
                className="mt-1.5 w-full rounded-md border border-gray-300 px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-400 resize-none"
                placeholder="Anotaciones relevantes del día (incidentes, notas, etc.)"
              />
            </Card>

          </div>
        </div>

        {/* ── Error de envío ── */}
        {crear.isError && (
          <p className="text-red-500 text-sm border border-red-200 bg-red-50 rounded px-3 py-2">
            {(crear.error as { response?: { data?: { error?: string } } })?.response?.data?.error
              ?? 'Error al guardar el reporte. Revisa los datos e intenta de nuevo.'}
          </p>
        )}

        {/* ── Botón principal ── */}
        <div className="flex gap-3 pb-8">
          <Button
            type="submit"
            disabled={crear.isPending}
            className="flex-1 h-11 text-base font-semibold bg-cyan-600 hover:bg-cyan-700 text-white"
          >
            {crear.isPending ? 'Guardando reporte...' : 'Guardar Reporte Completo'}
          </Button>
          <Button
            type="button" variant="outline"
            onClick={() => navigate('/reportes')}
            disabled={crear.isPending}
          >
            Cancelar
          </Button>
        </div>

      </form>
    </div>
  )
}
