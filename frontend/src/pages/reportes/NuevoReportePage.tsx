import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useForm, useFieldArray, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useAuth } from '../../hooks/useAuth'
import { useCrearReporte, usePlanesComisiones } from '../../hooks/useReportes'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { Select } from '../../components/ui/select'
import { GlassPanel } from '../../components/ui/GlassPanel'
import { SectionPanel } from '../../components/ui/SectionPanel'
import { MoneyTotal } from '../../components/ui/MoneyTotal'
import { PageHeader } from '../../components/PageHeader'
import { borradorApi } from '../../services/borrador.api'
import { BipayConsole } from '../../components/BipayConsole'
import { ChipStockBadge } from '../../components/ChipStockBadge'
import { calcularCuadre, calcularComision, validarStock } from '../../lib/cuadre'
import { api } from '../../services/api'
import { inventarioApi } from '../../services/inventario.api'
import type { InventarioItem } from '../../types/inventario'
import { TicketIngresoModal } from './cuadre/TicketIngresoModal'

// ── Acentos por sección (paridad legacy includes/estilos.css) ──────────────────
const ACCENT = {
  postpago: '#60a5fa',
  prepago:  '#22d3ee',
  equipos:  '#fbbf24',
  otros:    '#e4e4e7',
  apoyo:    '#a78bfa',
  total:    '#22d3ee',
} as const

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
  tipo_venta:           z.enum(['EQUIPO','ACCESORIO','POSTPAGO','PREPAGO','OTROS_FLUJO','APOYO']),
  subtipo:              z.string().optional().or(z.literal('')),
  monto_total:          z.number().min(0),
  efectivo_inicial:     z.number().min(0),
  cross_selling:        z.boolean(),
  tienda_destino:       z.string().optional().or(z.literal('')),
  es_remate:            z.boolean(),
  es_extranjero:        z.boolean(),
  es_migracion:         z.boolean(),
  es_upgrade:           z.boolean(),
  es_esim:              z.boolean(),
  plan_anterior:        z.number().min(0),
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
  es_migracion:false, es_upgrade:false, es_esim:false, plan_anterior:0,
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
  const up = useWatch({ control, name: `ventas.${index}.es_upgrade` })
  const e  = errors.ventas?.[index]

  return (
    <div className="grid grid-cols-[120px_1fr_130px_80px_90px_auto] gap-1.5 items-end py-1.5 border-b border-gray-100 last:border-0">
      <div>
        <Input {...register(`ventas.${index}.cliente_dni`)} placeholder="DNI / Celular" maxLength={15} className="h-8 text-xs" />
      </div>
      <div>
        <Select {...register(`ventas.${index}.plan_nombre`)} className="h-8 text-xs">
          <option value="">— Plan —</option>
          {planes.map((p, i) => (
            <option key={i} value={p.nombre_plan}>{p.nombre_plan} ({p.tipo_alta})</option>
          ))}
        </Select>
        {e?.plan_nombre && <p className="text-red-500 text-[10px]">{e.plan_nombre.message}</p>}
      </div>
      <div>
        <Select {...register(`ventas.${index}.tipo_alta`)} className="h-8 text-xs">
          {TIPOS_ALTA.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
        </Select>
      </div>
      <div>
        <Input type="number" step="0.01" min="0" {...register(`ventas.${index}.cobrado_unitario`, { valueAsNumber: true })} placeholder="S/" className="h-8 text-xs" />
      </div>
      <div className="flex flex-col gap-0.5 text-[10px]">
        <label className="flex items-center gap-1 cursor-pointer">
          <input type="checkbox" {...register(`ventas.${index}.es_extranjero`)} className="w-3 h-3" /> Ext
        </label>
        {tipo === 'POSTPAGO' && (
          <label className="flex items-center gap-1 cursor-pointer">
            <input type="checkbox" {...register(`ventas.${index}.es_migracion`)} className="w-3 h-3" /> Migr
          </label>
        )}
        {tipo === 'POSTPAGO' && (
          <label className="flex items-center gap-1 cursor-pointer">
            <input type="checkbox" {...register(`ventas.${index}.es_upgrade`)} className="w-3 h-3" /> Upg
          </label>
        )}
        <label className="flex items-center gap-1 cursor-pointer">
          <input type="checkbox" {...register(`ventas.${index}.es_esim`)} className="w-3 h-3" /> eSIM
        </label>
      </div>
      <button type="button" onClick={onRemove} className="text-red-400 hover:text-red-600 font-bold text-lg leading-none self-center">×</button>

      {up && (
        <div className="col-span-full pl-0 pt-1">
          <Label className="text-[10px] text-gray-500">Fee plan anterior (S/) — para calcular comisión de upgrade</Label>
          <Input type="number" step="0.01" min="0"
            {...register(`ventas.${index}.plan_anterior`, { valueAsNumber: true })}
            className="h-7 text-xs w-40 mt-0.5" placeholder="0.00" />
        </div>
      )}

      <input type="hidden" {...register(`ventas.${index}.monto_total`, { valueAsNumber: true })} />
      <input type="hidden" {...register(`ventas.${index}.efectivo_inicial`, { valueAsNumber: true })} />
    </div>
  )
}

// ── Componente: fila para VENTAS DE APOYO (otras tiendas) ────────────────────

function ApoyoRow({
  index, register, errors, onRemove, planes,
}: {
  index: number
  register: ReturnType<typeof useForm<FormData>>['register']
  errors: ReturnType<typeof useForm<FormData>>['formState']['errors']
  onRemove: () => void
  planes: Array<{ nombre_plan: string; tipo_alta: string }>
}) {
  const e = errors.ventas?.[index]
  return (
    <div className="grid grid-cols-[130px_1fr_70px_90px_auto] gap-1.5 items-end py-1.5 border-b border-gray-100 last:border-0">
      <div>
        <Select {...register(`ventas.${index}.tienda_destino`)} className="h-8 text-xs">
          <option value="">— Tienda —</option>
          {TIENDAS.map(t => <option key={t} value={t}>{t}</option>)}
        </Select>
        {e?.tienda_destino && <p className="text-red-500 text-[10px]">{e.tienda_destino.message}</p>}
      </div>
      <div>
        <Select {...register(`ventas.${index}.plan_nombre`)} className="h-8 text-xs">
          <option value="">— Plan —</option>
          {planes.map((p, i) => <option key={i} value={p.nombre_plan}>{p.nombre_plan} ({p.tipo_alta})</option>)}
        </Select>
      </div>
      <div>
        <Input type="number" step="1" min="1" {...register(`ventas.${index}.cantidad`, { valueAsNumber: true })} placeholder="Cant" className="h-8 text-xs" />
      </div>
      <div>
        <Input type="number" step="0.01" min="0" {...register(`ventas.${index}.cobrado_unitario`, { valueAsNumber: true })} placeholder="S/ c/u" className="h-8 text-xs" />
      </div>
      <button type="button" onClick={onRemove} className="text-red-400 hover:text-red-600 font-bold text-lg leading-none self-center">×</button>
      <input type="hidden" {...register(`ventas.${index}.monto_total`, { valueAsNumber: true })} />
      <input type="hidden" {...register(`ventas.${index}.efectivo_inicial`, { valueAsNumber: true })} />
    </div>
  )
}

// ── Componente: fila compacta para EQUIPO / ACCESORIO ────────────────────────

function EquipoRow({
  index, register, control, errors, onRemove, items, setValue,
}: {
  index: number
  register: ReturnType<typeof useForm<FormData>>['register']
  control: ReturnType<typeof useForm<FormData>>['control']
  errors: ReturnType<typeof useForm<FormData>>['formState']['errors']
  onRemove: () => void
  items: InventarioItem[]
  setValue: ReturnType<typeof useForm<FormData>>['setValue']
}) {
  const tipoPago    = useWatch({ control, name: `ventas.${index}.tipo_pago` })
  const productoNom = useWatch({ control, name: `ventas.${index}.producto_nombre` })
  const precioVenta = useWatch({ control, name: `ventas.${index}.precio_venta` })
  const e        = errors.ventas?.[index]
  const matched  = items.find(it => it.producto_nombre === productoNom)
  const precioMin = matched ? Number(matched.precio_minimo) || 0 : 0
  const bajoMinimo = !!matched && (precioVenta || 0) > 0 && (precioVenta || 0) < precioMin
  const reg = register(`ventas.${index}.producto_nombre`)

  return (
    <div className="space-y-1 py-1.5 border-b border-gray-100 last:border-0">
      <div className="grid grid-cols-[1fr_140px_110px_90px_auto] gap-1.5 items-end">
        <div>
          <Input {...reg} list="inv-equipos-datalist"
            placeholder="Producto (nombre o búsqueda)" className="h-8 text-xs"
            onChange={(ev) => {
              reg.onChange(ev)
              const m = items.find(it => it.producto_nombre === ev.target.value)
              if (m) {
                setValue(`ventas.${index}.inventario_tienda_id`, m.id)
                setValue(`ventas.${index}.costo_snap`, Number(m.precio_costo) || 0)
                if (!precioVenta) setValue(`ventas.${index}.precio_venta`, Number(m.precio_normal) || 0)
              }
            }} />
          {e?.producto_nombre && <p className="text-red-500 text-[10px]">{e.producto_nombre.message}</p>}
          {bajoMinimo && (
            <p className="text-[10px] font-semibold" style={{ color: '#fbbf24' }}>
              ⚠ Precio bajo el mínimo (S/ {precioMin.toFixed(2)})
            </p>
          )}
        </div>
        <div>
          <Input {...register(`ventas.${index}.imei_serial`)} placeholder="IMEI / Serie" maxLength={50} className="h-8 text-xs" />
        </div>
        <div>
          <Select {...register(`ventas.${index}.tipo_pago`)} className="h-8 text-xs">
            <option value="CONTADO">Contado</option>
            <option value="CUOTAS">A cuotas</option>
          </Select>
        </div>
        <div>
          <Input type="number" step="0.01" min="0" {...register(`ventas.${index}.precio_venta`, { valueAsNumber: true })} placeholder="Precio S/" className="h-8 text-xs" />
        </div>
        <button type="button" onClick={onRemove} className="text-red-400 hover:text-red-600 font-bold text-lg leading-none self-center">×</button>
      </div>

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
            <Input type="number" step="0.01" min="0" {...register(`ventas.${index}.por_cobrar_financiera`, { valueAsNumber: true })} className="h-7 text-xs mt-0.5" />
          </div>
          <div>
            <Label className="text-[10px]">Costo snap (S/)</Label>
            <Input type="number" step="0.01" min="0" {...register(`ventas.${index}.costo_snap`, { valueAsNumber: true })} className="h-7 text-xs mt-0.5" />
          </div>
        </div>
      )}

      <div className="pl-2">
        <Input {...register(`ventas.${index}.cliente_dni`)} placeholder="DNI cliente (opcional)" maxLength={15} className="h-7 text-xs w-48" />
      </div>

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
        <Input {...register(`ventas.${index}.subtipo`)} placeholder="Descripción / Motivo" className="h-8 text-xs" />
      </div>
      <div>
        <Input type="number" step="0.01" min="0" {...register(`ventas.${index}.monto_total`, { valueAsNumber: true })} placeholder="S/" className="h-8 text-xs" />
        {e?.monto_total && <p className="text-red-500 text-[10px]">{e.monto_total.message}</p>}
      </div>
      <button type="button" onClick={onRemove} className="text-red-400 hover:text-red-600 font-bold text-lg leading-none">×</button>
      <input type="hidden" {...register(`ventas.${index}.efectivo_inicial`, { valueAsNumber: true })} />
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
        destino_efectivo: 'EN_CAJA', observaciones: '', obs_dia: '',
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

  useEffect(() => {
    const total = salidaItems.reduce((acc, s) => acc + (Number(s.monto) || 0), 0)
    setValue('total_salidas', Math.round(total * 100) / 100)
  }, [salidaItems, setValue])

  // ── Borrador en la nube (auto-save 60s + manual) ──────────────────────────────
  const esTienda = usuario?.rol === 'tienda'
  const [borradorMsg, setBorradorMsg] = useState('')
  const [borradorDisponible, setBorradorDisponible] = useState<Record<string, unknown> | null>(null)
  const [ticketDesc, setTicketDesc] = useState<string | null>(null)

  const LS_KEY = `reporte_borrador_${usuario?.tienda_id ?? 'x'}`

  async function guardarBorrador(silencioso = false) {
    const payload = { form: getValues(), salidaItems, timestamp: Date.now() }
    try {
      await borradorApi.guardar(payload as unknown as Record<string, unknown>)
      // Espejo local para rescate por timestamp si luego se cae la conexión
      try { localStorage.setItem(LS_KEY, JSON.stringify(payload)) } catch { /* quota */ }
      if (!silencioso) { setBorradorMsg('Guardado en la nube ☁️'); setTimeout(() => setBorradorMsg(''), 2000) }
    } catch {
      // Sin conexión: fallback a localStorage
      try { localStorage.setItem(LS_KEY, JSON.stringify(payload)) } catch { /* quota */ }
      if (!silencioso) { setBorradorMsg('Sin conexión — guardado local'); setTimeout(() => setBorradorMsg(''), 2500) }
    }
  }

  function restaurarBorrador(data: Record<string, unknown>) {
    const d = data as { form?: FormData; salidaItems?: SalidaItem[] }
    if (d.form) reset(d.form)
    if (Array.isArray(d.salidaItems)) setSalidaItems(d.salidaItems)
    setBorradorDisponible(null)
    setBorradorMsg('Borrador cargado')
    setTimeout(() => setBorradorMsg(''), 2000)
  }

  useEffect(() => {
    if (!esTienda) return
    let cloud: (Record<string, unknown> & { _cloud_ts?: number }) | null = null
    borradorApi.cargar()
      .then((r) => { cloud = r.borrador })
      .catch(() => {})
      .finally(() => {
        let local: { form?: FormData; salidaItems?: SalidaItem[]; timestamp?: number } | null = null
        try { const raw = localStorage.getItem(LS_KEY); if (raw) local = JSON.parse(raw) } catch { /* corrupt */ }
        const cloudTs = Number(cloud?._cloud_ts ?? 0)
        const localTs = Number(local?.timestamp ?? 0)
        // Rescate: si el local es más nuevo que la nube, ofrecer el local y re-sincronizar
        if (local && localTs > cloudTs) {
          setBorradorDisponible({ form: local.form, salidaItems: local.salidaItems } as Record<string, unknown>)
        } else if (cloud) {
          setBorradorDisponible(cloud)
        }
      })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [esTienda])

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
  const recarga_bipay     = watch('recarga_bipay')     || 0
  const pago_servicio     = watch('pago_servicio')     || 0
  const pago_krece        = watch('pago_krece')        || 0
  const tickets_tusamy    = watch('tickets_tusamy')    || 0
  const efectivo_entregado= watch('efectivo_entregado')|| 0
  const destino           = watch('destino_efectivo')

  // POSTPAGO/PREPAGO/APOYO: monto_total = cobrado_unitario × cantidad
  // EQUIPO/ACCESORIO: monto_total = precio_venta
  useEffect(() => {
    ventas.forEach((v, i) => {
      if (v.tipo_venta === 'POSTPAGO' || v.tipo_venta === 'PREPAGO' || v.tipo_venta === 'APOYO') {
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

  // Comisión por línea en vivo (POSTPAGO / PREPAGO) según el plan seleccionado.
  // NOTA: el backend solo expone comision_dni_n; el caso extranjero usa la misma
  // base hasta que se agregue un campo comision_ext en ComisionPlan.
  useEffect(() => {
    ventas.forEach((v, i) => {
      if (v.tipo_venta !== 'POSTPAGO' && v.tipo_venta !== 'PREPAGO') return
      const plan = planesData.find(p => p.nombre_plan === v.plan_nombre)
      const base = plan ? Number(plan.comision_dni_n) || 0 : 0
      const com = calcularComision({
        comDni: base, comExt: base,
        esExtranjero: !!v.es_extranjero,
        esMigracion:  !!v.es_migracion,
        esUpgrade:    !!v.es_upgrade,
        esEsim:       !!v.es_esim,
        feePlanNuevo: plan ? Number(plan.fee_monto) || 0 : 0,
        feePlanAnterior: v.plan_anterior || 0,
      })
      if (v.comision_unitaria !== com) setValue(`ventas.${i}.comision_unitaria`, com)
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ventas, planesData, setValue])

  const ventasMap         = fields.map((f, i) => ({ ...f, idx: i, tipo: ventas[i]?.tipo_venta }))
  const postpagoRows      = ventasMap.filter(v => v.tipo === 'POSTPAGO')
  const prepagoRows       = ventasMap.filter(v => v.tipo === 'PREPAGO')
  const equipoRows        = ventasMap.filter(v => v.tipo === 'EQUIPO' || v.tipo === 'ACCESORIO')
  const otrosRows         = ventasMap.filter(v => v.tipo === 'OTROS_FLUJO')
  const apoyoRows         = ventasMap.filter(v => v.tipo === 'APOYO')

  const sub = (t: VentaFormData['tipo_venta'] | VentaFormData['tipo_venta'][]) => {
    const tipos = Array.isArray(t) ? t : [t]
    return ventas.filter(v => tipos.includes(v.tipo_venta)).reduce((a, v) => a + (v.monto_total || 0), 0)
  }
  const totalPostpago     = sub('POSTPAGO')
  const totalPrepago      = sub('PREPAGO')
  const totalEquipos      = sub(['EQUIPO','ACCESORIO'])
  const totalOtrosFlujo   = sub('OTROS_FLUJO')
  const totalApoyo        = sub('APOYO')
  const ingresosFijos     = recarga_bipay + pago_servicio + pago_krece + tickets_tusamy
  const otrosFijos        = totalOtrosFlujo + ingresosFijos

  // total_sistema (legacy): suma de las 5 secciones de venta
  const totalSistema = totalPostpago + totalPrepago + totalEquipos + otrosFijos + totalApoyo

  const { totalNoFisico, efectivoEsperado, totalEnCajon, diferencia, requiereAprobacion } =
    calcularCuadre({
      totalSistema,
      yape, bipay, transferencia, retiroBipay: retiro_bipay,
      totalSalidas: total_salidas, cajaInicial: caja_inicial,
      efectivoEntregado: efectivo_entregado,
    })

  // ── Validación de stock de chips en vivo ───────────────────────────────────
  // NOTA: /v1/inventario-chips no entrega stock por origen/tienda; se valida por
  // TOTAL (consumo prepago+apoyo vs disponible) hasta tener desglose por origen.
  const { data: chipsData } = useQuery({
    queryKey: ['inventario-chips-cuadre'],
    queryFn: () => api.get<{ data: Array<{ stock_actual?: number }> }>('/v1/inventario-chips').then((r) => r.data.data),
    staleTime: 60_000, retry: false, enabled: esTienda,
  })
  const chipsDisponibles = (chipsData ?? []).reduce((a, c) => a + (Number(c.stock_actual) || 0), 0)
  const chipsConsumidos = ventas
    .filter(v => v.tipo_venta === 'PREPAGO' || v.tipo_venta === 'APOYO')
    .reduce((a, v) => a + (v.cantidad || 1), 0)
  const stockChk = validarStock([{ codigo: 'CHIP', disponible: chipsDisponibles }], { CHIP: chipsConsumidos })
  const stockInsuficiente = esTienda && chipsData !== undefined && stockChk.hayError

  // ── Inventario de la tienda para el datalist de equipos (T7) ───────────────
  const { data: invData } = useQuery({
    queryKey: ['inventario-cuadre', usuario?.tienda_id],
    queryFn: () => inventarioApi.listar({ tienda: usuario?.tienda_id, per_page: 300 }).then((r) => r.data),
    staleTime: 60_000, retry: false, enabled: esTienda,
  })
  const inventarioItems = (invData ?? []).filter(
    it => it.estado === 'DISPONIBLE' && (it.tipo === 'EQUIPO' || it.tipo === 'ACCESORIO'),
  )

  const onSubmit = (data: FormData) => {
    crear.mutate(
      { ...data, usuario_id: usuario?.id ?? data.agente_id },
      { onSuccess: () => navigate('/reportes') },
    )
  }

  return (
    <div className="max-w-[1100px] mx-auto">
      <PageHeader
        title="Registrar Cuadre Diario"
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
            <Button variant="outline" onClick={() => navigate('/reportes')}>Cancelar</Button>
          </div>
        }
      />

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">

        {esTienda && <BipayConsole />}

        {/* ── Cabecera ── */}
        <GlassPanel className="p-4">
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
        </GlassPanel>

        {/* ── Cuerpo: dos columnas ── */}
        <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,2fr)_minmax(0,380px)] gap-4 items-start">

          {/* ═══════════ COLUMNA IZQUIERDA: Ventas ═══════════ */}
          <div>

            <SectionPanel
              title="Ventas Postpago" accent={ACCENT.postpago}
              count={postpagoRows.length} addLabel="Agregar Postpago"
              subtotal={totalPostpago}
              onAdd={() => append({ ...VENTA_DEFAULT, tipo_venta: 'POSTPAGO', tipo_alta: 'MNP' })}
            >
              {postpagoRows.length > 0 && (
                <div className="grid grid-cols-[120px_1fr_130px_80px_90px_auto] gap-1.5 py-1 text-[10px] text-gray-400 font-medium border-b border-dashed border-gray-100">
                  <span>DNI / Cel.</span><span>Plan</span><span>Tipo alta</span><span>Cobrado</span><span>Opciones</span><span />
                </div>
              )}
              {postpagoRows.length === 0
                ? <p className="text-[11px] text-gray-400 py-2 text-center italic">Sin registros.</p>
                : postpagoRows.map(v => (
                    <LineaRow key={v.id} index={v.idx} register={register} control={control}
                      errors={errors} tipo="POSTPAGO" planes={planesData} onRemove={() => remove(v.idx)} />
                  ))}
            </SectionPanel>

            <SectionPanel
              title="Ventas Prepago / Chips" accent={ACCENT.prepago}
              count={prepagoRows.length} addLabel="Agregar Prepago"
              subtotal={totalPrepago}
              onAdd={() => append({ ...VENTA_DEFAULT, tipo_venta: 'PREPAGO', tipo_alta: 'LN' })}
            >
              {prepagoRows.length > 0 && (
                <div className="grid grid-cols-[120px_1fr_130px_80px_90px_auto] gap-1.5 py-1 text-[10px] text-gray-400 font-medium border-b border-dashed border-gray-100">
                  <span>DNI / Cel.</span><span>Plan</span><span>Tipo alta</span><span>Cobrado</span><span>Opciones</span><span />
                </div>
              )}
              {prepagoRows.length === 0
                ? <p className="text-[11px] text-gray-400 py-2 text-center italic">Sin registros.</p>
                : prepagoRows.map(v => (
                    <LineaRow key={v.id} index={v.idx} register={register} control={control}
                      errors={errors} tipo="PREPAGO" planes={planesData} onRemove={() => remove(v.idx)} />
                  ))}
            </SectionPanel>

            <SectionPanel
              title="Equipos y Accesorios" accent={ACCENT.equipos}
              count={equipoRows.length} addLabel="Vender de Stock"
              subtotal={totalEquipos}
              onAdd={() => append({ ...VENTA_DEFAULT, tipo_venta: 'EQUIPO' })}
            >
              <datalist id="inv-equipos-datalist">
                {inventarioItems.map(it => (
                  <option key={it.id} value={it.producto_nombre}>
                    {it.tipo} · stock {it.cantidad} · S/ {Number(it.precio_normal).toFixed(2)}
                  </option>
                ))}
              </datalist>
              {equipoRows.length === 0
                ? <p className="text-[11px] text-gray-400 py-2 text-center italic">Sin registros.</p>
                : equipoRows.map(v => (
                    <EquipoRow key={v.id} index={v.idx} register={register} control={control}
                      errors={errors} onRemove={() => remove(v.idx)} items={inventarioItems} setValue={setValue} />
                  ))}
              {equipoRows.length > 0 && (
                <button type="button"
                  onClick={() => append({ ...VENTA_DEFAULT, tipo_venta: 'ACCESORIO' })}
                  className="text-xs font-medium mt-1" style={{ color: ACCENT.apoyo }}>
                  + Agregar Accesorio
                </button>
              )}
            </SectionPanel>

            <SectionPanel
              title="Otros Ingresos (Flujo)" accent={ACCENT.otros}
              count={otrosRows.length} addLabel="Agregar"
              subtotal={totalOtrosFlujo}
              onAdd={() => append({ ...VENTA_DEFAULT, tipo_venta: 'OTROS_FLUJO' })}
            >
              {otrosRows.length === 0
                ? <p className="text-[11px] text-gray-400 py-2 text-center italic">Sin registros.</p>
                : otrosRows.map(v => (
                    <OtroRow key={v.id} index={v.idx} register={register} errors={errors} onRemove={() => remove(v.idx)} />
                  ))}
            </SectionPanel>

            <SectionPanel
              title="Ventas de Apoyo (otras tiendas)" accent={ACCENT.apoyo}
              count={apoyoRows.length} addLabel="Agregar Venta de Apoyo"
              subtotal={totalApoyo}
              onAdd={() => append({ ...VENTA_DEFAULT, tipo_venta: 'APOYO', tipo_alta: 'LN' })}
            >
              {apoyoRows.length > 0 && (
                <div className="grid grid-cols-[130px_1fr_70px_90px_auto] gap-1.5 py-1 text-[10px] text-gray-400 font-medium border-b border-dashed border-gray-100">
                  <span>Tienda</span><span>Plan</span><span>Cant</span><span>Cobrado c/u</span><span />
                </div>
              )}
              {apoyoRows.length === 0
                ? <p className="text-[11px] text-gray-400 py-2 text-center italic">Sin ventas de apoyo.</p>
                : apoyoRows.map(v => (
                    <ApoyoRow key={v.id} index={v.idx} register={register} errors={errors}
                      planes={planesData} onRemove={() => remove(v.idx)} />
                  ))}
            </SectionPanel>

            {/* ── Consolidado de ventas (Total Sistema) ── */}
            <GlassPanel className="p-3" style={{ background: 'rgba(6,182,212,0.07)' }}>
              <p className="text-xs uppercase tracking-widest mb-2 font-medium" style={{ color: ACCENT.total }}>Total Sistema Consolidado</p>
              <div className="grid grid-cols-5 gap-2 text-center text-xs mb-3">
                {[
                  { label: 'Postpago', val: totalPostpago, n: postpagoRows.length },
                  { label: 'Prepago',  val: totalPrepago,  n: prepagoRows.length },
                  { label: 'Equipos',  val: totalEquipos,  n: equipoRows.length },
                  { label: 'Otros',    val: otrosFijos,    n: otrosRows.length },
                  { label: 'Apoyo',    val: totalApoyo,    n: apoyoRows.length },
                ].map(({ label, val, n }) => (
                  <div key={label} className="rounded px-2 py-1" style={{ background: 'rgba(255,255,255,0.04)' }}>
                    <div className="text-[10px] text-gray-400">{label}{n > 0 && ` (${n})`}</div>
                    <div className="font-semibold text-gray-200">S/ {val.toFixed(2)}</div>
                  </div>
                ))}
              </div>
              <div className="text-center">
                <span className="text-gray-400 text-xs">TOTAL DEL DÍA</span>
                <div><MoneyTotal value={totalSistema} color={ACCENT.total} size="2rem" /></div>
              </div>
            </GlassPanel>

          </div>

          {/* ═══════════ COLUMNA DERECHA: Caja y Dinero ═══════════ */}
          <div className="space-y-3 lg:sticky lg:top-4">

            <GlassPanel className="p-3">
              <Label className="text-xs font-semibold text-gray-700 uppercase tracking-wide">Caja Inicial (Sencillo)</Label>
              <Input id="caja_inicial" type="number" step="0.01" min="0"
                {...register('caja_inicial', { valueAsNumber: true })}
                className="mt-1.5 h-9 text-sm font-medium" placeholder="S/ 0.00" />
            </GlassPanel>

            <GlassPanel className="p-3 space-y-2">
              <p className="text-xs font-semibold text-gray-700 uppercase tracking-wide">Dinero No Físico</p>
              {([
                ['yape',          'Yape / Plin'],
                ['bipay',         'Bipay'],
                ['transferencia', 'Transferencia'],
              ] as const).map(([field, label]) => (
                <div key={field} className="flex items-center gap-2">
                  <Label className="text-xs text-gray-600 w-28 shrink-0">{label}</Label>
                  <Input type="number" step="0.01" min="0" {...register(field, { valueAsNumber: true })}
                    className="h-7 text-xs text-right" placeholder="0.00" />
                </div>
              ))}
              <div className="flex items-center gap-2 border-t pt-2">
                <Label className="text-xs text-red-600 w-28 shrink-0 font-medium">Retiro Bipay</Label>
                <Input type="number" step="0.01" min="0" {...register('retiro_bipay', { valueAsNumber: true })}
                  className="h-7 text-xs text-right border-red-200 focus:border-red-400" placeholder="0.00" />
              </div>
              <div className="text-right text-xs text-gray-500 pt-1">
                Total no físico: <span className="font-semibold text-gray-300">S/ {totalNoFisico.toFixed(2)}</span>
              </div>
            </GlassPanel>

            <GlassPanel className="p-3 space-y-2">
              <p className="text-xs font-semibold text-gray-700 uppercase tracking-wide">Ingresos Fijos</p>
              {([
                ['recarga_bipay', 'Recarga Bipay'],
                ['pago_servicio', 'Pago de Servicio'],
                ['pago_krece',    'Pago Krece'],
                ['tickets_tusamy','Tickets Tusamy'],
              ] as const).map(([field, label]) => (
                <div key={field} className="flex items-center gap-2">
                  <Label className="text-xs text-gray-600 w-28 shrink-0">{label}</Label>
                  <Input type="number" step="0.01" min="0" {...register(field, { valueAsNumber: true })}
                    className="h-7 text-xs text-right" placeholder="0.00" />
                  {esTienda && (
                    <button type="button" title="Generar ticket de ingreso" onClick={() => setTicketDesc(label)}
                      className="shrink-0 text-cyan-500 hover:text-cyan-300 text-sm leading-none px-1">🧾</button>
                  )}
                </div>
              ))}
            </GlassPanel>

            <GlassPanel className="p-3">
              <div className="flex items-center justify-between mb-2">
                <p className="text-xs font-semibold text-gray-700 uppercase tracking-wide">Salidas de Efectivo</p>
                <button type="button" onClick={agregarSalida}
                  className="text-xs text-red-500 border border-red-300 rounded px-2 py-0.5 hover:bg-red-50 font-medium">
                  + Agregar Salida
                </button>
              </div>
              {salidaItems.length === 0
                ? <p className="text-[11px] text-gray-400 italic text-center py-1">Sin salidas registradas</p>
                : (
                  <div className="space-y-1.5">
                    {salidaItems.map(s => (
                      <div key={s.id} className="grid grid-cols-[90px_70px_1fr_auto] gap-1 items-center">
                        <select value={s.tipo} onChange={e => actualizarSalida(s.id, 'tipo', e.target.value)}
                          className="h-7 text-xs rounded border border-gray-300 px-1">
                          {TIPOS_SALIDA.map(t => <option key={t} value={t}>{t}</option>)}
                        </select>
                        <input type="number" step="0.01" min="0" value={s.monto || ''}
                          onChange={e => actualizarSalida(s.id, 'monto', parseFloat(e.target.value) || 0)}
                          placeholder="S/" className="h-7 text-xs rounded border border-gray-300 px-2 text-right" />
                        <input type="text" value={s.motivo}
                          onChange={e => actualizarSalida(s.id, 'motivo', e.target.value)}
                          placeholder="Motivo" className="h-7 text-xs rounded border border-gray-300 px-2" />
                        <button type="button" onClick={() => eliminarSalida(s.id)}
                          className="text-red-400 hover:text-red-600 font-bold text-sm leading-none">×</button>
                      </div>
                    ))}
                    <div className="text-right text-xs font-semibold text-red-500 pt-1">
                      Total salidas: S/ {total_salidas.toFixed(2)}
                    </div>
                  </div>
                )
              }
            </GlassPanel>

            {/* ── Cuadre Final ── */}
            <GlassPanel className="p-3" style={{ border: '1px solid rgba(34,197,94,0.4)' }}>
              <p className="text-xs font-bold uppercase tracking-wide mb-3" style={{ color: '#22c55e' }}>Cuadre Final</p>

              <div className="space-y-2 text-sm">
                <div className="flex justify-between items-center">
                  <span className="text-xs text-gray-500">Total en cajón:</span>
                  <span className="font-semibold text-gray-300">S/ {totalEnCajon.toFixed(2)}</span>
                </div>
                <div className="flex justify-between items-center">
                  <span className="text-xs text-gray-500">Efectivo esperado:</span>
                  <span className="font-semibold text-gray-200">S/ {efectivoEsperado.toFixed(2)}</span>
                </div>

                <div className="flex justify-between items-center">
                  <Label className="text-xs font-medium text-gray-300">Mi Efectivo (entrego):</Label>
                  <Input type="number" step="0.01" min="0" {...register('efectivo_entregado', { valueAsNumber: true })}
                    className="h-8 w-32 text-sm text-right font-semibold" placeholder="0.00" />
                </div>

                <div className="flex justify-between items-center pt-1 border-t" style={{ borderColor: 'rgba(34,197,94,0.2)' }}>
                  <span className="text-xs font-bold text-gray-300">Diferencia:</span>
                  <span className="font-mono font-bold text-base" style={{
                    color: Math.abs(diferencia) < 0.01 ? '#e4e4e7' : diferencia < 0 ? '#f87171' : '#fbbf24',
                  }}>
                    S/ {diferencia.toFixed(2)}{requiereAprobacion && ' ⚠'}
                  </span>
                </div>

                {requiereAprobacion && (
                  <p className="text-[10px] text-amber-400 rounded px-2 py-1" style={{ background: 'rgba(245,158,11,0.1)', border: '1px solid rgba(245,158,11,0.3)' }}>
                    Diferencia mayor a S/10 — el reporte quedará en espera de aprobación.
                  </p>
                )}
              </div>

              {/* Destino del efectivo — toggles glow (paridad legacy) */}
              <div className="mt-3">
                <p className="text-xs font-medium text-gray-300 mb-1.5">Destino del efectivo:</p>
                <div className="grid grid-cols-2 gap-2">
                  {([
                    { value: 'ENTREGADO', label: 'Lo Entregué', accent: '#22c55e' },
                    { value: 'EN_CAJA',   label: 'En Tienda',   accent: '#fbbf24' },
                  ] as const).map(opt => {
                    const active = destino === opt.value
                    return (
                      <label key={opt.value}
                        className="flex items-center justify-center gap-1.5 cursor-pointer text-xs font-semibold rounded-md py-2 transition-all"
                        style={active
                          ? { background: opt.accent, color: '#0a0a0a', border: `2px solid ${opt.accent}`,
                              boxShadow: `0 0 20px ${opt.accent}80`, transform: 'scale(1.02)' }
                          : { background: 'transparent', color: '#a1a1aa', border: `2px solid ${opt.accent}`, opacity: 0.45 }}>
                        <input type="radio" value={opt.value} {...register('destino_efectivo')} className="hidden" />
                        {opt.label}
                      </label>
                    )
                  })}
                </div>
              </div>

              {/* Observaciones condicionales del destino */}
              {destino === 'ENTREGADO' && (
                <div className="mt-2">
                  <Label className="text-xs text-gray-400">A quién / referencia de depósito *</Label>
                  <Input {...register('observaciones')} placeholder="Nombre o número de operación" className="mt-1 h-8 text-xs" />
                </div>
              )}
              {destino === 'EN_CAJA' && (
                <div className="mt-2">
                  <Label className="text-xs text-gray-400">Observación de caja (opcional)</Label>
                  <textarea {...register('observaciones')} rows={2}
                    className="mt-1 w-full rounded-md border border-gray-300 px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-amber-400 resize-none"
                    placeholder="Detalle de por qué el efectivo queda en tienda" />
                </div>
              )}
            </GlassPanel>

            <GlassPanel className="p-3">
              <Label className="text-xs font-semibold text-gray-700">Observaciones del Día</Label>
              <textarea {...register('obs_dia')} rows={2}
                className="mt-1.5 w-full rounded-md border border-gray-300 px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-400 resize-none"
                placeholder="Anotaciones relevantes del día (incidentes, notas, etc.)" />
            </GlassPanel>

          </div>
        </div>

        {crear.isError && (
          <p className="text-red-500 text-sm border border-red-200 bg-red-50 rounded px-3 py-2">
            {(crear.error as { response?: { data?: { error?: string } } })?.response?.data?.error
              ?? 'Error al guardar el reporte. Revisa los datos e intenta de nuevo.'}
          </p>
        )}

        {stockInsuficiente && (
          <p className="text-red-500 text-sm border border-red-300 bg-red-50 rounded px-3 py-2 font-semibold">
            STOCK INSUFICIENTE — estás reportando {chipsConsumidos} chips pero solo hay {chipsDisponibles} en stock.
          </p>
        )}

        <div className="flex gap-3 pb-8">
          <Button type="submit" disabled={crear.isPending || stockInsuficiente}
            className="flex-1 h-11 text-base font-semibold bg-cyan-600 hover:bg-cyan-700 text-white">
            {stockInsuficiente ? 'STOCK INSUFICIENTE' : crear.isPending ? 'Guardando reporte...' : 'Guardar Reporte Completo'}
          </Button>
          <Button type="button" variant="outline" onClick={() => navigate('/reportes')} disabled={crear.isPending}>
            Cancelar
          </Button>
        </div>

      </form>

      {esTienda && (
        <TicketIngresoModal
          open={ticketDesc !== null}
          onClose={() => setTicketDesc(null)}
          defaultDescripcion={ticketDesc ?? ''}
          tiendaId={usuario?.tienda_id ?? ''}
          agenteId={usuario?.id ?? 0}
          vendedor={usuario?.nombre ?? ''}
        />
      )}
    </div>
  )
}
