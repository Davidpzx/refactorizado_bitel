import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useForm, useFieldArray, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useAuth } from '../../hooks/useAuth'
import { Receipt, X, FileText, Cpu, Package, Coins, Users, Save, UploadCloud, FolderDown, Printer, Plus } from 'lucide-react'
import { usePlanesComisiones } from '../../hooks/useReportes'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { Select } from '../../components/ui/select'
import { GlassPanel } from '../../components/ui/GlassPanel'
import { SectionPanel } from '../../components/ui/SectionPanel'
import { AddRowButton } from '../../components/ui/AddRowButton'
import { ToggleSwitch } from '../../components/ui/ToggleSwitch'
import { MoneyTotal } from '../../components/ui/MoneyTotal'
import { PageHeader } from '../../components/PageHeader'
import { borradorApi } from '../../services/borrador.api'
import { BipayConsole } from '../../components/BipayConsole'
import { ChipStockBadge } from '../../components/ChipStockBadge'
import { calcularCuadre, calcularComision, validarStock } from '../../lib/cuadre'
import { api } from '../../services/api'
import { inventarioApi } from '../../services/inventario.api'
import type { InventarioItem } from '../../types/inventario'
import { reportesApi } from '../../services/reportes.api'
import type { VendedorReporte } from '../../types/reporte'
import { TicketIngresoModal } from './cuadre/TicketIngresoModal'

// ── Acentos por sección (paridad legacy includes/estilos.css) ──────────────────
const ACCENT = {
  postpago: 'var(--color-kyro-indigo)',
  prepago:  'var(--color-kyro-info)',
  equipos:  'var(--color-kyro-warning)',
  otros:    'var(--color-kyro-body)',
  apoyo:    'var(--color-kyro-indigo)',
  total:    'var(--color-kyro-info)',
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
  venta_id:             z.number().int().positive().optional(),
  vendedor_id:          z.number().int().min(1, 'Selecciona el vendedor'),
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
  cliente_nombre:       z.string().optional().or(z.literal('')),
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
  pago_payjoy:        z.number().min(0),
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
  vendedor_id:0,
  tipo_venta:'POSTPAGO', subtipo:'', monto_total:0, efectivo_inicial:0,
  cross_selling:false, tienda_destino:'', es_remate:false, es_extranjero:false,
  es_migracion:false, es_upgrade:false, es_esim:false, plan_anterior:0,
  cliente_dni:'', cliente_nombre:'', producto_nombre:'', imei_serial:'', tipo_pago:'CONTADO',
  financiera:'', precio_venta:0, costo_snap:0, por_cobrar_financiera:0,
  inventario_tienda_id:0, plan_nombre:'', tipo_alta:'MNP', cantidad:1,
  cobrado_unitario:0, comision_unitaria:0,
}

// ── Salidas detalladas ────────────────────────────────────────────────────────

type SalidaItem = { id: string; tipo: string; monto: number; motivo: string }

function VendedorSelect({
  index, register, vendedores,
}: {
  index: number
  register: ReturnType<typeof useForm<FormData>>['register']
  vendedores: VendedorReporte[]
}) {
  return (
    <Select {...register(`ventas.${index}.vendedor_id`, { valueAsNumber: true })} className="kyro-input h-8 text-xs">
      <option value={0}>Vendedor...</option>
      {vendedores.map((v) => (
        <option key={v.id} value={v.id}>
          {v.nombres} ({v.tienda_base})
        </option>
      ))}
    </Select>
  )
}

// ── Componente: fila compacta para POSTPAGO / PREPAGO ────────────────────────

function LineaRow({
  index, register, control, errors, tipo, onRemove, onPrint, planes, vendedores,
}: {
  index: number
  register: ReturnType<typeof useForm<FormData>>['register']
  control: ReturnType<typeof useForm<FormData>>['control']
  errors: ReturnType<typeof useForm<FormData>>['formState']['errors']
  tipo: 'POSTPAGO' | 'PREPAGO'
  onRemove: () => void
  onPrint?: () => void
  planes: Array<{ nombre_plan: string; tipo_alta: string }>
  vendedores: VendedorReporte[]
}) {
  const up = useWatch({ control, name: `ventas.${index}.es_upgrade` })
  const e  = errors.ventas?.[index]
  const borderColor = tipo === 'POSTPAGO' ? 'rgba(59,130,246,0.3)' : 'rgba(6,182,212,0.3)'

  return (
    <div className="rounded-lg border p-2 mb-2.5" style={{ background: 'rgba(0,0,0,0.05)', borderColor }}>
      {/* Fila superior: vendedor + DNI + toggles horizontales (paridad legacy) */}
      <div className="flex items-center gap-2 flex-wrap pb-2 mb-2 border-b border-dashed border-kyro-border">
        <div className="w-[120px] shrink-0">
          <VendedorSelect index={index} register={register} vendedores={vendedores} />
        </div>
        <Input {...register(`ventas.${index}.cliente_dni`)} placeholder="DNI / Cel" maxLength={15} className="kyro-input h-8 text-xs w-[95px] shrink-0" />
        <div className="flex items-center gap-3 flex-wrap">
          <ToggleSwitch label="Extranjero" accent="#a1a1aa" {...register(`ventas.${index}.es_extranjero`)} />
          {tipo === 'POSTPAGO' && <ToggleSwitch label="Migración" accent="#06b6d4" {...register(`ventas.${index}.es_migracion`)} />}
          {tipo === 'POSTPAGO' && <ToggleSwitch label="Upgrade" accent="#f59e0b" {...register(`ventas.${index}.es_upgrade`)} />}
          <ToggleSwitch label="eSIM" accent="#a78bfa" {...register(`ventas.${index}.es_esim`)} />
        </div>
      </div>

      {/* Fila inferior: plan + tipo alta + cobrado + acciones */}
      <div className="flex items-end gap-2">
        <div className="flex-1 min-w-[140px]">
          <Select {...register(`ventas.${index}.plan_nombre`)} className="kyro-input h-8 text-xs">
            <option value="">— Plan —</option>
            {planes.map((p, i) => (
              <option key={i} value={p.nombre_plan}>{p.nombre_plan} ({p.tipo_alta})</option>
            ))}
          </Select>
          {e?.plan_nombre && <p className="text-kyro-danger text-[10px]">{e.plan_nombre.message}</p>}
        </div>
        <Select {...register(`ventas.${index}.tipo_alta`)} className="kyro-input h-8 text-xs w-[92px] shrink-0">
          {TIPOS_ALTA.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
        </Select>
        <div className="flex items-center gap-1 w-[118px] shrink-0">
          <span className="text-xs text-kyro-muted">S/</span>
          <Input type="number" step="0.01" min="0" {...register(`ventas.${index}.cobrado_unitario`, { valueAsNumber: true })} placeholder="0.00" className="kyro-input h-8 text-xs text-right" />
        </div>
        <div className="flex flex-col gap-1 shrink-0">
          {onPrint && (
            <Button type="button" variant="glassInfo" size="iconSm" aria-label="Imprimir ticket" title="Imprimir ticket" onClick={onPrint}>
              <Printer size={14} />
            </Button>
          )}
          <Button type="button" variant="glassDanger" size="iconSm" aria-label="Eliminar venta" onClick={onRemove}>
            <X size={14} />
          </Button>
        </div>
      </div>

      {up && (
        <div className="pt-2">
          <Label className="text-[10px] text-kyro-muted">Fee plan anterior (S/) — para comisión de upgrade</Label>
          <Input type="number" step="0.01" min="0"
            {...register(`ventas.${index}.plan_anterior`, { valueAsNumber: true })}
            className="kyro-input h-7 text-xs w-40 mt-0.5" placeholder="0.00" />
        </div>
      )}

      <input type="hidden" {...register(`ventas.${index}.monto_total`, { valueAsNumber: true })} />
      <input type="hidden" {...register(`ventas.${index}.efectivo_inicial`, { valueAsNumber: true })} />
    </div>
  )
}

// ── Componente: fila para VENTAS DE APOYO (otras tiendas) ────────────────────

function ApoyoRow({
  index, register, errors, onRemove, planes, vendedores,
}: {
  index: number
  register: ReturnType<typeof useForm<FormData>>['register']
  errors: ReturnType<typeof useForm<FormData>>['formState']['errors']
  onRemove: () => void
  planes: Array<{ nombre_plan: string; tipo_alta: string }>
  vendedores: VendedorReporte[]
}) {
  const e = errors.ventas?.[index]
  return (
    <div className="grid grid-cols-[150px_130px_1fr_70px_90px_auto] gap-1.5 items-end py-1.5 border-b border-kyro-border last:border-0">
      <VendedorSelect index={index} register={register} vendedores={vendedores} />
      <div>
        <Select {...register(`ventas.${index}.tienda_destino`)} className="kyro-input h-8 text-xs">
          <option value="">— Tienda —</option>
          {TIENDAS.map(t => <option key={t} value={t}>{t}</option>)}
        </Select>
        {e?.tienda_destino && <p className="text-kyro-danger text-[10px]">{e.tienda_destino.message}</p>}
      </div>
      <div>
        <Select {...register(`ventas.${index}.plan_nombre`)} className="kyro-input h-8 text-xs">
          <option value="">— Plan —</option>
          {planes.map((p, i) => <option key={i} value={p.nombre_plan}>{p.nombre_plan} ({p.tipo_alta})</option>)}
        </Select>
      </div>
      <div>
        <Input type="number" step="1" min="1" {...register(`ventas.${index}.cantidad`, { valueAsNumber: true })} placeholder="Cant" className="kyro-input h-8 text-xs" />
      </div>
      <div>
        <Input type="number" step="0.01" min="0" {...register(`ventas.${index}.cobrado_unitario`, { valueAsNumber: true })} placeholder="S/ c/u" className="kyro-input h-8 text-xs" />
      </div>
      <Button type="button" variant="glassDanger" size="iconSm" aria-label="Eliminar venta" onClick={onRemove} className="self-center">
        <X size={14} />
      </Button>
      <input type="hidden" {...register(`ventas.${index}.monto_total`, { valueAsNumber: true })} />
      <input type="hidden" {...register(`ventas.${index}.efectivo_inicial`, { valueAsNumber: true })} />
    </div>
  )
}

// ── Componente: fila compacta para EQUIPO / ACCESORIO ────────────────────────

function EquipoRow({
  index, register, control, errors, onRemove, onPrint, items, setValue, vendedores,
}: {
  index: number
  register: ReturnType<typeof useForm<FormData>>['register']
  control: ReturnType<typeof useForm<FormData>>['control']
  errors: ReturnType<typeof useForm<FormData>>['formState']['errors']
  onRemove: () => void
  onPrint?: () => void
  items: InventarioItem[]
  setValue: ReturnType<typeof useForm<FormData>>['setValue']
  vendedores: VendedorReporte[]
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
    <div className="rounded-lg border p-2 mb-2.5" style={{ background: 'rgba(0,0,0,0.05)', borderColor: 'rgba(245,158,11,0.3)' }}>
      {/* Fila superior: vendedor + DNI cliente */}
      <div className="flex items-center gap-2 flex-wrap pb-2 mb-2 border-b border-dashed border-kyro-border">
        <div className="w-[120px] shrink-0">
          <VendedorSelect index={index} register={register} vendedores={vendedores} />
        </div>
        <Input {...register(`ventas.${index}.cliente_dni`)} placeholder="DNI cliente (opcional)" maxLength={15} className="kyro-input h-8 text-xs w-[150px] shrink-0" />
      </div>

      {/* Fila inferior: producto + imei + pago + precio + acciones */}
      <div className="flex items-end gap-2 flex-wrap">
        <div className="flex-1 min-w-[160px]">
          <Input {...reg} list="inv-equipos-datalist"
            placeholder="Producto (nombre o búsqueda)" className="kyro-input h-8 text-xs"
            onChange={(ev) => {
              reg.onChange(ev)
              const m = items.find(it => it.producto_nombre === ev.target.value)
              if (m) {
                setValue(`ventas.${index}.inventario_tienda_id`, m.id)
                setValue(`ventas.${index}.costo_snap`, Number(m.precio_costo) || 0)
                if (!precioVenta) setValue(`ventas.${index}.precio_venta`, Number(m.precio_normal) || 0)
              }
            }} />
          {e?.producto_nombre && <p className="text-kyro-danger text-[10px]">{e.producto_nombre.message}</p>}
          {bajoMinimo && (
            <p className="text-[10px] font-semibold text-kyro-warning">
              ⚠ Precio bajo el mínimo (S/ {precioMin.toFixed(2)})
            </p>
          )}
        </div>
        <Input {...register(`ventas.${index}.imei_serial`)} placeholder="IMEI / Serie" maxLength={50} className="kyro-input h-8 text-xs w-[130px] shrink-0" />
        <Select {...register(`ventas.${index}.tipo_pago`)} className="kyro-input h-8 text-xs w-[100px] shrink-0">
          <option value="CONTADO">Contado</option>
          <option value="CUOTAS">A cuotas</option>
        </Select>
        <div className="flex items-center gap-1 w-[118px] shrink-0">
          <span className="text-xs text-kyro-muted">S/</span>
          <Input type="number" step="0.01" min="0" {...register(`ventas.${index}.precio_venta`, { valueAsNumber: true })} placeholder="0.00" className="kyro-input h-8 text-xs text-right" />
        </div>
        <div className="flex flex-col gap-1 shrink-0">
          {onPrint && (
            <Button type="button" variant="glassInfo" size="iconSm" aria-label="Imprimir ticket" title="Imprimir ticket" onClick={onPrint}>
              <Printer size={14} />
            </Button>
          )}
          <Button type="button" variant="glassDanger" size="iconSm" aria-label="Eliminar venta" onClick={onRemove}>
            <X size={14} />
          </Button>
        </div>
      </div>

      {tipoPago === 'CUOTAS' && (
        <div className="grid grid-cols-[170px_120px_120px] gap-1.5 mt-2">
          <div>
            <Label className="text-[10px]">Financiera</Label>
            <Select {...register(`ventas.${index}.financiera`)} className="kyro-input h-7 text-xs mt-0.5">
              <option value="">Ninguna</option>
              {FINANCIERAS.map(f => <option key={f} value={f}>{f}</option>)}
            </Select>
          </div>
          <div>
            <Label className="text-[10px]">Por cobrar financiera (S/)</Label>
            <Input type="number" step="0.01" min="0" {...register(`ventas.${index}.por_cobrar_financiera`, { valueAsNumber: true })} className="kyro-input h-7 text-xs mt-0.5" />
          </div>
          <div>
            <Label className="text-[10px]">Costo snap (S/)</Label>
            <Input type="number" step="0.01" min="0" {...register(`ventas.${index}.costo_snap`, { valueAsNumber: true })} className="kyro-input h-7 text-xs mt-0.5" />
          </div>
        </div>
      )}

      <input type="hidden" {...register(`ventas.${index}.monto_total`, { valueAsNumber: true })} />
      <input type="hidden" {...register(`ventas.${index}.efectivo_inicial`, { valueAsNumber: true })} />
    </div>
  )
}

// ── Componente: fila compacta para OTROS_FLUJO ───────────────────────────────

function OtroRow({
  index, register, errors, onRemove, vendedores,
}: {
  index: number
  register: ReturnType<typeof useForm<FormData>>['register']
  errors: ReturnType<typeof useForm<FormData>>['formState']['errors']
  onRemove: () => void
  vendedores: VendedorReporte[]
}) {
  const e = errors.ventas?.[index]
  return (
    <div className="grid grid-cols-[150px_1fr_100px_auto] gap-1.5 items-end py-1.5 border-b border-kyro-border last:border-0">
      <VendedorSelect index={index} register={register} vendedores={vendedores} />
      <div>
        <Input {...register(`ventas.${index}.subtipo`)} placeholder="Descripción / Motivo" className="kyro-input h-8 text-xs" />
      </div>
      <div>
        <Input type="number" step="0.01" min="0" {...register(`ventas.${index}.monto_total`, { valueAsNumber: true })} placeholder="S/" className="kyro-input h-8 text-xs" />
        {e?.monto_total && <p className="text-kyro-danger text-[10px]">{e.monto_total.message}</p>}
      </div>
      <Button type="button" variant="glassDanger" size="iconSm" aria-label="Eliminar venta" onClick={onRemove}>
        <X size={14} />
      </Button>
      <input type="hidden" {...register(`ventas.${index}.efectivo_inicial`, { valueAsNumber: true })} />
    </div>
  )
}

// ── Modal: Agregar Venta Unificado ────────────────────────────────────────────

type ModalSeccion = 'POSTPAGO' | 'PREPAGO' | 'EQUIPO' | 'ACCESORIO' | 'OTROS_FLUJO' | 'APOYO' | ''

interface ModalVentaState {
  vendedor_id:           number
  seccion:               ModalSeccion
  cliente_dni:           string
  cliente_nombre:        string
  es_extranjero:         boolean
  es_migracion:          boolean
  es_upgrade:            boolean
  es_esim:               boolean
  plan_nombre:           string
  tipo_alta:             string
  cobrado_unitario:      number
  plan_anterior:         number
  cantidad:              number
  producto_nombre:       string
  inventario_tienda_id:  number
  imei_serial:           string
  tipo_pago:             'CONTADO' | 'CUOTAS'
  precio_venta:          number
  financiera:            string
  por_cobrar_financiera: number
  costo_snap:            number
  subtipo:               string
  monto_otros:           number
  tienda_destino:        string
}

const MODAL_DEFAULT: ModalVentaState = {
  vendedor_id: 0, seccion: '',
  cliente_dni: '', cliente_nombre: '',
  es_extranjero: false, es_migracion: false, es_upgrade: false, es_esim: false,
  plan_nombre: '', tipo_alta: 'MNP', cobrado_unitario: 0, plan_anterior: 0, cantidad: 1,
  producto_nombre: '', inventario_tienda_id: 0, imei_serial: '',
  tipo_pago: 'CONTADO', precio_venta: 0, financiera: '', por_cobrar_financiera: 0, costo_snap: 0,
  subtipo: '', monto_otros: 0, tienda_destino: '',
}

const MODAL_SECCIONES: { value: Exclude<ModalSeccion,''>; label: string; color: string }[] = [
  { value: 'POSTPAGO',    label: 'Postpago',           color: 'var(--color-kyro-indigo)'  },
  { value: 'PREPAGO',     label: 'Prepago / Chip',     color: 'var(--color-kyro-info)'    },
  { value: 'EQUIPO',      label: 'Equipo / Accesorio', color: 'var(--color-kyro-warning)' },
  { value: 'OTROS_FLUJO', label: 'Otros Ingresos',     color: 'var(--color-kyro-body)'    },
  { value: 'APOYO',       label: 'Ventas de Apoyo',    color: 'var(--color-kyro-gold)'    },
]

function AgregarVentaModal({
  open, onClose, onConfirm, vendedores, planes, inventarioItems,
}: {
  open: boolean
  onClose: () => void
  onConfirm: (data: ModalVentaState) => void
  vendedores: VendedorReporte[]
  planes: Array<{ nombre_plan: string; tipo_alta: string }>
  inventarioItems: InventarioItem[]
}) {
  const [m, setM] = useState<ModalVentaState>(MODAL_DEFAULT)

  useEffect(() => { if (open) setM(MODAL_DEFAULT) }, [open])

  const upd = <K extends keyof ModalVentaState>(k: K, v: ModalVentaState[K]) =>
    setM(prev => ({ ...prev, [k]: v }))

  const s        = m.seccion
  const esLinea  = s === 'POSTPAGO' || s === 'PREPAGO'
  const esEquipo = s === 'EQUIPO' || s === 'ACCESORIO'
  const esOtros  = s === 'OTROS_FLUJO'
  const esApoyo  = s === 'APOYO'
  const haCliente = esLinea || esEquipo

  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />
      <div className="relative z-10 kyro-card w-full max-w-lg max-h-[90vh] overflow-y-auto p-5 space-y-4 shadow-2xl">

        {/* Header */}
        <div className="flex items-center justify-between border-b border-kyro-border pb-3">
          <h2 className="font-semibold text-base text-kyro-text">Agregar Venta</h2>
          <Button type="button" variant="glassDanger" size="iconSm" onClick={onClose}><X size={14} /></Button>
        </div>

        {/* 1. Vendedor */}
        <div>
          <Label className="text-[11px] font-semibold uppercase tracking-wide text-kyro-muted">1. Vendedor</Label>
          <Select value={m.vendedor_id} onChange={e => upd('vendedor_id', Number(e.target.value))} className="kyro-input mt-1 h-9 text-sm">
            <option value={0}>Selecciona el vendedor...</option>
            {vendedores.map(v => <option key={v.id} value={v.id}>{v.nombres} ({v.tienda_base})</option>)}
          </Select>
        </div>

        {/* 2. Sección */}
        <div>
          <Label className="text-[11px] font-semibold uppercase tracking-wide text-kyro-muted">2. Sección</Label>
          <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 mt-1.5">
            {MODAL_SECCIONES.map(sec => {
              const active = m.seccion === sec.value
              return (
                <button key={sec.value} type="button"
                  onClick={() => upd('seccion', sec.value)}
                  className="rounded-lg px-3 py-2 text-xs font-semibold transition-all border"
                  style={active
                    ? { background: sec.color, color: '#fff', borderColor: sec.color, boxShadow: `0 0 14px color-mix(in srgb, ${sec.color} 40%, transparent)` }
                    : { background: 'transparent', color: 'var(--color-kyro-muted)', borderColor: 'var(--color-kyro-border)' }
                  }
                >
                  {sec.label}
                </button>
              )
            })}
          </div>
        </div>

        {/* 3. Cliente */}
        {haCliente && (
          <div>
            <Label className="text-[11px] font-semibold uppercase tracking-wide text-kyro-muted">3. Cliente</Label>
            <div className="grid grid-cols-2 gap-2 mt-1.5">
              <div>
                <Label className="text-[10px] text-kyro-muted">DNI / Celular</Label>
                <Input value={m.cliente_dni} onChange={e => upd('cliente_dni', e.target.value)} maxLength={15} placeholder="DNI o celular" className="kyro-input mt-0.5 h-8 text-xs" />
              </div>
              <div>
                <Label className="text-[10px] text-kyro-muted">Nombre completo</Label>
                <Input value={m.cliente_nombre} onChange={e => upd('cliente_nombre', e.target.value)} placeholder="Nombre del cliente" className="kyro-input mt-0.5 h-8 text-xs" />
              </div>
            </div>
          </div>
        )}

        {/* 4a. Postpago / Prepago */}
        {esLinea && (
          <div className="space-y-3">
            <Label className="text-[11px] font-semibold uppercase tracking-wide text-kyro-muted">4. Detalle</Label>
            <div className="flex flex-wrap gap-2">
              <button type="button" onClick={() => setM(p => ({ ...p, es_extranjero: !p.es_extranjero }))}
                className="text-xs px-2.5 py-1 rounded-full border font-medium transition-all"
                style={m.es_extranjero ? { background:'#a1a1aa', color:'#fff', borderColor:'#a1a1aa' } : { background:'transparent', color:'var(--color-kyro-muted)', borderColor:'var(--color-kyro-border)' }}>
                Extranjero
              </button>
              {s === 'POSTPAGO' && (
                <button type="button" onClick={() => setM(p => ({ ...p, es_migracion: !p.es_migracion }))}
                  className="text-xs px-2.5 py-1 rounded-full border font-medium transition-all"
                  style={m.es_migracion ? { background:'#06b6d4', color:'#fff', borderColor:'#06b6d4' } : { background:'transparent', color:'var(--color-kyro-muted)', borderColor:'var(--color-kyro-border)' }}>
                  Migración
                </button>
              )}
              {s === 'POSTPAGO' && (
                <button type="button" onClick={() => setM(p => ({ ...p, es_upgrade: !p.es_upgrade }))}
                  className="text-xs px-2.5 py-1 rounded-full border font-medium transition-all"
                  style={m.es_upgrade ? { background:'#f59e0b', color:'#fff', borderColor:'#f59e0b' } : { background:'transparent', color:'var(--color-kyro-muted)', borderColor:'var(--color-kyro-border)' }}>
                  Upgrade
                </button>
              )}
              <button type="button" onClick={() => setM(p => ({ ...p, es_esim: !p.es_esim }))}
                className="text-xs px-2.5 py-1 rounded-full border font-medium transition-all"
                style={m.es_esim ? { background:'#a78bfa', color:'#fff', borderColor:'#a78bfa' } : { background:'transparent', color:'var(--color-kyro-muted)', borderColor:'var(--color-kyro-border)' }}>
                eSIM
              </button>
            </div>
            <div className="grid grid-cols-[1fr_130px] gap-2">
              <div>
                <Label className="text-[10px] text-kyro-muted">Plan *</Label>
                <Select value={m.plan_nombre} onChange={e => upd('plan_nombre', e.target.value)} className="kyro-input mt-0.5 h-8 text-xs">
                  <option value="">— Selecciona plan —</option>
                  {planes.map((p, i) => <option key={i} value={p.nombre_plan}>{p.nombre_plan} ({p.tipo_alta})</option>)}
                </Select>
              </div>
              <div>
                <Label className="text-[10px] text-kyro-muted">Tipo de alta</Label>
                <Select value={m.tipo_alta} onChange={e => upd('tipo_alta', e.target.value)} className="kyro-input mt-0.5 h-8 text-xs">
                  {TIPOS_ALTA.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
                </Select>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <Label className="text-[10px] text-kyro-muted w-24 shrink-0">Cobrado (S/)</Label>
              <Input type="number" step="0.01" min="0" value={m.cobrado_unitario || ''} onChange={e => upd('cobrado_unitario', parseFloat(e.target.value) || 0)} placeholder="0.00" className="kyro-input h-8 text-xs w-32" />
            </div>
            {m.es_upgrade && (
              <div className="flex items-center gap-2">
                <Label className="text-[10px] text-kyro-muted w-24 shrink-0">Fee ant. (S/)</Label>
                <Input type="number" step="0.01" min="0" value={m.plan_anterior || ''} onChange={e => upd('plan_anterior', parseFloat(e.target.value) || 0)} className="kyro-input h-8 text-xs w-32" />
              </div>
            )}
          </div>
        )}

        {/* 4b. Equipo / Accesorio */}
        {esEquipo && (
          <div className="space-y-3">
            <Label className="text-[11px] font-semibold uppercase tracking-wide text-kyro-muted">4. Detalle</Label>
            <div>
              <Label className="text-[10px] text-kyro-muted">Producto *</Label>
              <Input value={m.producto_nombre} list="modal-inv-datalist" placeholder="Nombre del producto"
                className="kyro-input mt-0.5 h-8 text-xs"
                onChange={e => {
                  const v = e.target.value
                  const match = inventarioItems.find(it => it.producto_nombre === v)
                  if (match) {
                    setM(p => ({ ...p, producto_nombre: v, inventario_tienda_id: match.id, costo_snap: Number(match.precio_costo) || 0, precio_venta: Number(match.precio_normal) || 0 }))
                  } else {
                    upd('producto_nombre', v)
                  }
                }} />
              <datalist id="modal-inv-datalist">
                {inventarioItems.map(it => <option key={it.id} value={it.producto_nombre}>{it.tipo} · S/ {Number(it.precio_normal).toFixed(2)}</option>)}
              </datalist>
            </div>
            <div className="grid grid-cols-2 gap-2">
              <div>
                <Label className="text-[10px] text-kyro-muted">IMEI / Serie</Label>
                <Input value={m.imei_serial} onChange={e => upd('imei_serial', e.target.value)} maxLength={50} placeholder="IMEI o serie" className="kyro-input mt-0.5 h-8 text-xs" />
              </div>
              <div>
                <Label className="text-[10px] text-kyro-muted">Tipo de pago</Label>
                <Select value={m.tipo_pago} onChange={e => upd('tipo_pago', e.target.value as 'CONTADO' | 'CUOTAS')} className="kyro-input mt-0.5 h-8 text-xs">
                  <option value="CONTADO">Contado</option>
                  <option value="CUOTAS">A cuotas</option>
                </Select>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <Label className="text-[10px] text-kyro-muted w-28 shrink-0">Precio venta (S/)</Label>
              <Input type="number" step="0.01" min="0" value={m.precio_venta || ''} onChange={e => upd('precio_venta', parseFloat(e.target.value) || 0)} className="kyro-input h-8 text-xs w-32" />
            </div>
            {m.tipo_pago === 'CUOTAS' && (
              <div className="grid grid-cols-3 gap-2 pt-2 border-t border-dashed border-kyro-border">
                <div>
                  <Label className="text-[10px] text-kyro-muted">Financiera</Label>
                  <Select value={m.financiera} onChange={e => upd('financiera', e.target.value)} className="kyro-input mt-0.5 h-7 text-xs">
                    <option value="">Ninguna</option>
                    {FINANCIERAS.map(f => <option key={f}>{f}</option>)}
                  </Select>
                </div>
                <div>
                  <Label className="text-[10px] text-kyro-muted">Por cobrar fin.</Label>
                  <Input type="number" step="0.01" min="0" value={m.por_cobrar_financiera || ''} onChange={e => upd('por_cobrar_financiera', parseFloat(e.target.value) || 0)} className="kyro-input mt-0.5 h-7 text-xs" />
                </div>
                <div>
                  <Label className="text-[10px] text-kyro-muted">Costo snap</Label>
                  <Input type="number" step="0.01" min="0" value={m.costo_snap || ''} onChange={e => upd('costo_snap', parseFloat(e.target.value) || 0)} className="kyro-input mt-0.5 h-7 text-xs" />
                </div>
              </div>
            )}
          </div>
        )}

        {/* 4c. Otros Ingresos */}
        {esOtros && (
          <div className="space-y-2">
            <Label className="text-[11px] font-semibold uppercase tracking-wide text-kyro-muted">4. Detalle</Label>
            <div className="grid grid-cols-2 gap-2">
              <div>
                <Label className="text-[10px] text-kyro-muted">Descripción / Motivo *</Label>
                <Input value={m.subtipo} onChange={e => upd('subtipo', e.target.value)} placeholder="Motivo del ingreso" className="kyro-input mt-0.5 h-8 text-xs" />
              </div>
              <div>
                <Label className="text-[10px] text-kyro-muted">Monto (S/) *</Label>
                <Input type="number" step="0.01" min="0" value={m.monto_otros || ''} onChange={e => upd('monto_otros', parseFloat(e.target.value) || 0)} className="kyro-input mt-0.5 h-8 text-xs" />
              </div>
            </div>
          </div>
        )}

        {/* 4d. Ventas de Apoyo */}
        {esApoyo && (
          <div className="space-y-3">
            <Label className="text-[11px] font-semibold uppercase tracking-wide text-kyro-muted">4. Detalle</Label>
            <div>
              <Label className="text-[10px] text-kyro-muted">Tienda *</Label>
              <Select value={m.tienda_destino} onChange={e => upd('tienda_destino', e.target.value)} className="kyro-input mt-0.5 h-8 text-xs">
                <option value="">— Selecciona tienda —</option>
                {TIENDAS.map(t => <option key={t}>{t}</option>)}
              </Select>
            </div>
            <div className="grid grid-cols-[1fr_80px_110px] gap-2">
              <div>
                <Label className="text-[10px] text-kyro-muted">Plan *</Label>
                <Select value={m.plan_nombre} onChange={e => upd('plan_nombre', e.target.value)} className="kyro-input mt-0.5 h-8 text-xs">
                  <option value="">— Plan —</option>
                  {planes.map((p, i) => <option key={i} value={p.nombre_plan}>{p.nombre_plan}</option>)}
                </Select>
              </div>
              <div>
                <Label className="text-[10px] text-kyro-muted">Cant.</Label>
                <Input type="number" step="1" min="1" value={m.cantidad} onChange={e => upd('cantidad', parseInt(e.target.value) || 1)} className="kyro-input mt-0.5 h-8 text-xs" />
              </div>
              <div>
                <Label className="text-[10px] text-kyro-muted">Cobrado c/u (S/)</Label>
                <Input type="number" step="0.01" min="0" value={m.cobrado_unitario || ''} onChange={e => upd('cobrado_unitario', parseFloat(e.target.value) || 0)} className="kyro-input mt-0.5 h-8 text-xs" />
              </div>
            </div>
          </div>
        )}

        {/* Confirmar */}
        <div className="flex gap-2 pt-3 border-t border-kyro-border">
          <Button type="button" variant="gold" className="flex-1 gap-2 h-10" disabled={!m.vendedor_id || !m.seccion}
            onClick={() => { onConfirm(m); onClose() }}>
            <Plus size={15} /> Agregar Venta
          </Button>
          <Button type="button" variant="outline" onClick={onClose}>Cancelar</Button>
        </div>

      </div>
    </div>
  )
}

// ── Página principal ──────────────────────────────────────────────────────────

type NuevoReportePageProps = {
  mode?: 'create' | 'edit'
}

interface TiendaOption {
  codigo: string
  nombre: string
}

export function NuevoReportePage({ mode = 'create' }: NuevoReportePageProps) {
  const navigate    = useNavigate()
  const { id }      = useParams<{ id: string }>()
  const { usuario } = useAuth()
  const { data: planesData = [] } = usePlanesComisiones()
  const esEdicion = mode === 'edit'
  const esAdminReporte = usuario?.rol === 'admin' && !esEdicion
  const reporteId = Number(id ?? 0)
  const inicializadoRef = useRef(false)

  const { data: reporteEditar, isLoading: cargandoReporte } = useQuery({
    queryKey: ['reporte', reporteId],
    queryFn: () => reportesApi.obtener(reporteId),
    enabled: esEdicion && reporteId > 0,
  })

  const today = new Date().toISOString().slice(0, 10)

  const { register, control, handleSubmit, watch, setValue, getValues, reset, formState: { errors } } =
    useForm<FormData>({
      resolver: zodResolver(schema),
      defaultValues: {
        agente_id: usuario?.agente_id ?? 0, tienda_id: usuario?.tienda_id ?? '',
        fecha: today, nombre_cubre: '',
        caja_inicial: 0, yape: 0, bipay: 0, transferencia: 0,
        retiro_bipay: 0, recarga_bipay: 0, pago_servicio: 0,
        pago_krece: 0, pago_payjoy: 0, tickets_tusamy: 0,
        efectivo_entregado: 0, total_salidas: 0,
        destino_efectivo: 'ENTREGADO', observaciones: '', obs_dia: '',
        ventas: [],
      },
    })

  useEffect(() => {
    if (usuario && !esEdicion && usuario.rol !== 'admin') {
      setValue('agente_id', usuario.agente_id ?? 0)
      setValue('tienda_id', usuario.tienda_id)
    }
  }, [esEdicion, usuario, setValue])

  const { fields, append, remove } = useFieldArray({ control, name: 'ventas' })

  // ── Modal agregar venta ────────────────────────────────────────────────────
  const [ventaModalOpen, setVentaModalOpen] = useState(false)

  const handleAgregarVenta = (data: ModalVentaState) => {
    const base = { vendedor_id: data.vendedor_id, cliente_dni: data.cliente_dni, cliente_nombre: data.cliente_nombre }
    switch (data.seccion) {
      case 'POSTPAGO':
      case 'PREPAGO':
        append(ventaNueva({ ...base, tipo_venta: data.seccion, es_extranjero: data.es_extranjero, es_migracion: data.es_migracion, es_upgrade: data.es_upgrade, es_esim: data.es_esim, plan_nombre: data.plan_nombre, tipo_alta: data.tipo_alta, cobrado_unitario: data.cobrado_unitario, plan_anterior: data.plan_anterior }))
        break
      case 'EQUIPO':
      case 'ACCESORIO':
        append(ventaNueva({ ...base, tipo_venta: data.seccion, producto_nombre: data.producto_nombre, inventario_tienda_id: data.inventario_tienda_id, imei_serial: data.imei_serial, tipo_pago: data.tipo_pago, precio_venta: data.precio_venta, financiera: data.financiera, por_cobrar_financiera: data.por_cobrar_financiera, costo_snap: data.costo_snap }))
        break
      case 'OTROS_FLUJO':
        append(ventaNueva({ vendedor_id: data.vendedor_id, tipo_venta: 'OTROS_FLUJO', subtipo: data.subtipo, monto_total: data.monto_otros }))
        break
      case 'APOYO':
        append(ventaNueva({ vendedor_id: data.vendedor_id, tipo_venta: 'APOYO', tienda_destino: data.tienda_destino, plan_nombre: data.plan_nombre, cantidad: data.cantidad, cobrado_unitario: data.cobrado_unitario, tipo_alta: 'LN' }))
        break
    }
  }

  // ── Salidas de efectivo (estado local) ─────────────────────────────────────
  const [salidaItems, setSalidaItems] = useState<SalidaItem[]>([])

  useEffect(() => {
    if (!esEdicion || !reporteEditar || inicializadoRef.current) return

    const ventasIniciales: VentaFormData[] = reporteEditar.ventas.map((venta) => {
      const nombrePlan = venta.linea?.plan_nombre_snap ?? ''
      const tipoPago = venta.equipo?.tipo_pago === 'CUOTAS' ? 'CUOTAS' : 'CONTADO'

      return {
        ...VENTA_DEFAULT,
        venta_id: venta.id,
        vendedor_id: venta.vendedor_id,
        tipo_venta: venta.tipo_venta,
        subtipo: venta.subtipo ?? '',
        monto_total: Number(venta.monto_total),
        efectivo_inicial: Number(venta.efectivo_inicial),
        cross_selling: venta.cross_selling,
        tienda_destino: venta.tienda_destino ?? '',
        es_remate: venta.es_remate,
        es_extranjero: venta.es_extranjero,
        es_migracion: nombrePlan.toUpperCase().includes('MIGRACI'),
        es_upgrade: nombrePlan.toUpperCase().includes('UPGRADE'),
        es_esim: venta.linea?.es_esim ?? false,
        cliente_dni: venta.cliente?.dni_ruc ?? '',
        inventario_tienda_id: venta.equipo?.inventario_tienda_id ?? 0,
        producto_nombre: venta.equipo?.producto_nombre_snap ?? '',
        imei_serial: venta.equipo?.imei_serial_snap ?? '',
        tipo_pago: tipoPago,
        financiera: venta.equipo?.financiera ?? '',
        precio_venta: Number(venta.equipo?.precio_venta ?? venta.monto_total),
        costo_snap: Number(venta.equipo?.costo_snap ?? 0),
        por_cobrar_financiera: Number(venta.equipo?.por_cobrar_financiera ?? 0),
        plan_nombre: nombrePlan,
        tipo_alta: venta.linea?.tipo_alta ?? 'MNP',
        cantidad: venta.linea?.cantidad ?? 1,
        cobrado_unitario: Number(venta.linea?.cobrado_unitario ?? venta.monto_total),
        comision_unitaria: Number(venta.linea?.comision_unitaria ?? 0),
      }
    })

    const destino = ['TIENDA', 'ENTREGADO', 'EN_CAJA'].includes(reporteEditar.destino_efectivo)
      ? reporteEditar.destino_efectivo as FormData['destino_efectivo']
      : 'EN_CAJA'

    reset({
      agente_id: reporteEditar.agente_id,
      tienda_id: reporteEditar.tienda_id,
      fecha: reporteEditar.fecha,
      nombre_cubre: reporteEditar.nombre_cubre ?? '',
      caja_inicial: Number(reporteEditar.caja_inicial),
      yape: Number(reporteEditar.yape),
      bipay: Number(reporteEditar.bipay),
      transferencia: Number(reporteEditar.transferencia),
      retiro_bipay: Number(reporteEditar.retiro_bipay),
      recarga_bipay: Number(reporteEditar.recarga_bipay),
      pago_servicio: Number(reporteEditar.pago_servicio),
      pago_krece: Number(reporteEditar.pago_krece),
      pago_payjoy: Number(reporteEditar.pago_payjoy ?? 0),
      tickets_tusamy: Number(reporteEditar.tickets_tusamy),
      efectivo_entregado: Number(reporteEditar.efectivo_entregado),
      total_salidas: Number(reporteEditar.total_salidas),
      destino_efectivo: destino,
      observaciones: reporteEditar.observaciones ?? '',
      obs_dia: reporteEditar.obs_dia ?? '',
      ventas: ventasIniciales,
    })

    const totalSalidas = Number(reporteEditar.total_salidas)
    setSalidaItems(reporteEditar.salidas?.length
      ? reporteEditar.salidas.map(salida => ({
          id: crypto.randomUUID(),
          tipo: salida.tipo.charAt(0).toUpperCase() + salida.tipo.slice(1),
          monto: Number(salida.monto),
          motivo: salida.observacion ?? '',
        }))
      : totalSalidas > 0
        ? [{ id: crypto.randomUUID(), tipo: 'Otro', monto: totalSalidas, motivo: 'Total previo sin desglose' }]
        : [])
    inicializadoRef.current = true
  }, [esEdicion, reporteEditar, reset])

  const guardar = useMutation({
    mutationFn: (data: FormData) => {
      const payload = {
        ...data,
        usuario_id: usuario?.id ?? 0,
        salidas: salidaItems
          .filter(salida => Number(salida.monto) > 0)
          .map(salida => ({
            tipo: salida.tipo.toLowerCase() as 'adelanto' | 'gasto' | 'pasaje' | 'otro',
            monto: Number(salida.monto),
            observacion: salida.motivo,
          })),
      }
      return esEdicion
        ? reportesApi.reprocesar(reporteId, payload)
        : reportesApi.crear(payload)
    },
    onSuccess: () => navigate(esEdicion ? `/reportes/${reporteId}` : usuario?.rol === 'admin' ? '/reportes' : '/mi-historial'),
  })

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
  const esTienda = usuario?.rol === 'tienda' && !esEdicion
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
    if (d.form) {
      reset({
        ...d.form,
        ventas: (d.form.ventas ?? []).map((venta) => ({
          ...venta,
          vendedor_id: venta.vendedor_id || usuario?.agente_id || 0,
        })),
      })
    }
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
  const tiendaSeleccionada= watch('tienda_id')
  const caja_inicial      = watch('caja_inicial')      || 0
  const total_salidas     = watch('total_salidas')     || 0
  const yape              = watch('yape')              || 0
  const bipay             = watch('bipay')             || 0
  const transferencia     = watch('transferencia')     || 0
  const retiro_bipay      = watch('retiro_bipay')      || 0
  const recarga_bipay     = watch('recarga_bipay')     || 0
  const pago_servicio     = watch('pago_servicio')     || 0
  const pago_krece        = watch('pago_krece')        || 0
  const pago_payjoy       = watch('pago_payjoy')       || 0
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
  const ingresosFijos     = recarga_bipay + pago_servicio + pago_krece + pago_payjoy + tickets_tusamy
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
    queryKey: ['inventario-cuadre', tiendaSeleccionada],
    queryFn: () => inventarioApi.listar({ tienda: tiendaSeleccionada, per_page: 300 }).then((r) => r.data),
    staleTime: 60_000, retry: false, enabled: !!tiendaSeleccionada,
  })
  const inventarioItems = (invData ?? []).filter(
    it => it.estado === 'DISPONIBLE' && (it.tipo === 'EQUIPO' || it.tipo === 'ACCESORIO'),
  )

  const { data: vendedores = [] } = useQuery({
    queryKey: ['vendedores-reporte', tiendaSeleccionada],
    queryFn: () => reportesApi.vendedores(tiendaSeleccionada),
    staleTime: 60_000,
    enabled: !!tiendaSeleccionada,
  })

  const { data: tiendasAdmin = [] } = useQuery({
    queryKey: ['tiendas-modo-dios'],
    queryFn: () => api.get<{ data: TiendaOption[] }>('/v1/tiendas', { params: { per_page: 200 } }).then((r) => r.data.data),
    staleTime: 60_000,
    enabled: esAdminReporte,
  })

  const ventaNueva = (overrides: Partial<VentaFormData>): VentaFormData => ({
    ...VENTA_DEFAULT,
    vendedor_id: usuario?.agente_id ?? reporteEditar?.agente_id ?? 0,
    ...overrides,
  })

  const onSubmit = (data: FormData) => {
    guardar.mutate(data)
  }

  if (esEdicion && cargandoReporte) {
    return <div className="flex h-64 items-center justify-center text-sm text-kyro-muted">Cargando reporte...</div>
  }

  if (esEdicion && (!reporteEditar || reporteEditar.estado_edicion !== 'APROBADO' || usuario?.rol !== 'admin')) {
    return (
      <div className="kyro-card mx-auto max-w-xl p-6 text-center text-sm text-kyro-warning">
        El reprocesado completo requiere una edición aprobada y una cuenta administradora.
      </div>
    )
  }

  return (
    <div className="max-w-[1100px] mx-auto space-y-4">
      <PageHeader
        title={esEdicion ? `Editar Cuadre #${reporteId}` : 'Registrar Cuadre Diario'}
        description="Cierre de caja y ventas del día."
        actions={
          <div className="flex items-center gap-2">
            {esTienda && <ChipStockBadge />}
            {esTienda && borradorDisponible && (
              <Button variant="glassWarning" type="button" className="gap-2"
                onClick={() => restaurarBorrador(borradorDisponible)}>
                <FolderDown size={15} /> Cargar Borrador
              </Button>
            )}
            {esTienda && (
              <Button variant="gold" type="button" className="gap-2" onClick={() => guardarBorrador(false)}>
                <Save size={15} /> Guardar Borrador
              </Button>
            )}
            {borradorMsg && <span className="text-xs text-kyro-muted">{borradorMsg}</span>}
            <Button variant="outline" className="gap-2" onClick={() => navigate(esEdicion ? `/reportes/${reporteId}` : usuario?.rol === 'admin' ? '/reportes' : '/mi-historial')}><X size={15} /> Cancelar</Button>
          </div>
        }
      />

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4 [&_.premium-surface]:!bg-kyro-panel [&_.premium-surface]:!border-kyro-border [&_.premium-surface]:!shadow-kyro-card">

        {esTienda && <BipayConsole />}

        {/* ── Cabecera ── */}
        <GlassPanel className="kyro-card p-4">
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div>
              <Label htmlFor="fecha" className="text-xs font-medium text-kyro-body">Fecha *</Label>
              <Input id="fecha" type="date" {...register('fecha')} className="kyro-input mt-1 h-8 text-sm" />
              {errors.fecha && <p className="text-kyro-danger text-[10px] mt-0.5">{errors.fecha.message}</p>}
            </div>
            <div>
              <Label htmlFor="tienda_id" className="text-xs font-medium text-kyro-body">Tienda *</Label>
              <Select
                id="tienda_id"
                {...register('tienda_id', {
                  onChange: () => {
                    if (esAdminReporte) setValue('agente_id', 0)
                  },
                })}
                className="kyro-input mt-1 h-8 text-sm"
              >
                <option value="">— Selecciona —</option>
                {esAdminReporte
                  ? tiendasAdmin.map(t => <option key={t.codigo} value={t.codigo}>{t.nombre} ({t.codigo})</option>)
                  : TIENDAS.map(t => <option key={t} value={t}>{t}</option>)}
              </Select>
              {errors.tienda_id && <p className="text-kyro-danger text-[10px] mt-0.5">{errors.tienda_id.message}</p>}
            </div>
            <div>
              <Label htmlFor="nombre_cubre" className="text-xs font-medium text-kyro-body">Cubre tienda (si aplica)</Label>
              <Input id="nombre_cubre" {...register('nombre_cubre')} placeholder="Nombre" className="kyro-input mt-1 h-8 text-sm" />
            </div>
            {esAdminReporte && (
              <div>
                <Label htmlFor="agente_id" className="text-xs font-medium text-kyro-body">Agente responsable *</Label>
                <Select id="agente_id" {...register('agente_id', { valueAsNumber: true })} className="kyro-input mt-1 h-8 text-sm">
                  <option value={0}>Selecciona agente</option>
                  {vendedores.map((v) => (
                    <option key={v.id} value={v.id}>{v.nombres}</option>
                  ))}
                </Select>
                {errors.agente_id && <p className="text-kyro-danger text-[10px] mt-0.5">{errors.agente_id.message}</p>}
              </div>
            )}
            {!esAdminReporte && (
            <div className="flex items-end">
              <div className="text-xs text-kyro-muted bg-kyro-elevated rounded-kyro px-2 py-1.5 w-full border border-kyro-border">
                <span className="text-kyro-subtle">Agente:</span>{' '}
                <span className="font-medium text-kyro-body">{usuario?.nombre ?? '—'}</span>
                <input type="hidden" {...register('agente_id', { valueAsNumber: true })} />
              </div>
            </div>
            )}
          </div>
          {esAdminReporte && (
            <div className="mt-3 rounded-kyro border border-kyro-warning/40 bg-kyro-warning/10 px-3 py-2 text-xs text-kyro-warning">
              Modo admin: el cuadre se registrara para la tienda y agente seleccionados.
            </div>
          )}
        </GlassPanel>

        {/* ── Cuerpo: dos columnas ── */}
        <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,2fr)_minmax(0,380px)] gap-4 items-start">

          {/* ═══════════ COLUMNA IZQUIERDA: Ventas ═══════════ */}
          <div>

            {/* Botón unificado de agregar venta */}
            <button
              type="button"
              onClick={() => setVentaModalOpen(true)}
              className="w-full mb-4 flex items-center justify-center gap-2 h-11 rounded-lg font-semibold text-sm transition-all"
              style={{ background: 'var(--color-kyro-gold)', color: 'var(--color-kyro-gold-ink)', boxShadow: '0 0 18px color-mix(in srgb, var(--color-kyro-gold) 35%, transparent)' }}
            >
              <Plus size={18} /> Agregar Venta
            </button>

            <SectionPanel
              title="Ventas Postpago" accent={ACCENT.postpago} icon={<FileText size={15} />} number={1}
              count={postpagoRows.length} subtotal={totalPostpago}
            >
              {postpagoRows.length === 0
                ? <p className="text-[11px] text-kyro-muted py-2 text-center italic">Sin registros.</p>
                : postpagoRows.map(v => (
                    <LineaRow key={v.id} index={v.idx} register={register} control={control}
                      errors={errors} tipo="POSTPAGO" planes={planesData} vendedores={vendedores}
                      onRemove={() => remove(v.idx)} onPrint={esTienda ? () => setTicketDesc('Venta Postpago') : undefined} />
                  ))}
            </SectionPanel>

            <SectionPanel
              title="Ventas Prepago / Chips" accent={ACCENT.prepago} icon={<Cpu size={15} />} number={2}
              count={prepagoRows.length} subtotal={totalPrepago}
            >
              {prepagoRows.length === 0
                ? <p className="text-[11px] text-kyro-muted py-2 text-center italic">Sin registros.</p>
                : prepagoRows.map(v => (
                    <LineaRow key={v.id} index={v.idx} register={register} control={control}
                      errors={errors} tipo="PREPAGO" planes={planesData} vendedores={vendedores}
                      onRemove={() => remove(v.idx)} onPrint={esTienda ? () => setTicketDesc('Venta Prepago / Chip') : undefined} />
                  ))}
            </SectionPanel>

            <SectionPanel
              title="Equipos y Accesorios" accent={ACCENT.equipos} icon={<Package size={15} />} number={3}
              count={equipoRows.length} subtotal={totalEquipos}
            >
              <datalist id="inv-equipos-datalist">
                {inventarioItems.map(it => (
                  <option key={it.id} value={it.producto_nombre}>
                    {it.tipo} · stock {it.cantidad} · S/ {Number(it.precio_normal).toFixed(2)}
                  </option>
                ))}
              </datalist>
              {equipoRows.length === 0
                ? <p className="text-[11px] text-kyro-muted py-2 text-center italic">Sin registros.</p>
                : equipoRows.map(v => (
                    <EquipoRow key={v.id} index={v.idx} register={register} control={control}
                      errors={errors} onRemove={() => remove(v.idx)} onPrint={esTienda ? () => setTicketDesc('Venta Equipo') : undefined}
                      items={inventarioItems} setValue={setValue} vendedores={vendedores} />
                  ))}
            </SectionPanel>

            <SectionPanel
              title="Otros Ingresos (Flujo)" accent={ACCENT.otros} icon={<Coins size={15} />} number={4}
              count={otrosRows.length} subtotal={totalOtrosFlujo}
            >
              {otrosRows.length === 0
                ? <p className="text-[11px] text-kyro-muted py-2 text-center italic">Sin registros.</p>
                : otrosRows.map(v => (
                    <OtroRow key={v.id} index={v.idx} register={register} errors={errors} vendedores={vendedores} onRemove={() => remove(v.idx)} />
                  ))}
            </SectionPanel>

            <SectionPanel
              title="Ventas de Apoyo (otras tiendas)" accent={ACCENT.apoyo} icon={<Users size={15} />} number={5}
              count={apoyoRows.length} subtotal={totalApoyo}
            >
              {apoyoRows.length > 0 && (
                <div className="grid grid-cols-[150px_130px_1fr_70px_90px_auto] gap-1.5 py-1 text-[10px] text-kyro-muted font-medium border-b border-dashed border-kyro-border">
                  <span>Vendedor</span><span>Tienda</span><span>Plan</span><span>Cant</span><span>Cobrado c/u</span><span />
                </div>
              )}
              {apoyoRows.length === 0
                ? <p className="text-[11px] text-kyro-muted py-2 text-center italic">Sin ventas de apoyo.</p>
                : apoyoRows.map(v => (
                    <ApoyoRow key={v.id} index={v.idx} register={register} errors={errors}
                      planes={planesData} vendedores={vendedores} onRemove={() => remove(v.idx)} />
                  ))}
            </SectionPanel>

            {/* ── Consolidado de ventas (Total Sistema) ── */}
            <GlassPanel className="kyro-card p-3 bg-kyro-info/10 border-l-4 border-l-kpi-total">
              <p className="text-xs uppercase tracking-widest mb-2 font-medium text-kyro-info">Total Sistema Consolidado</p>
              <div className="grid grid-cols-5 gap-2 text-center text-xs mb-3">
                {[
                  { label: 'Postpago', val: totalPostpago, n: postpagoRows.length },
                  { label: 'Prepago',  val: totalPrepago,  n: prepagoRows.length },
                  { label: 'Equipos',  val: totalEquipos,  n: equipoRows.length },
                  { label: 'Otros',    val: otrosFijos,    n: otrosRows.length },
                  { label: 'Apoyo',    val: totalApoyo,    n: apoyoRows.length },
                ].map(({ label, val, n }) => (
                  <div key={label} className="rounded-kyro px-2 py-1 bg-kyro-elevated border border-kyro-border">
                    <div className="text-[10px] text-kyro-muted">{label}{n > 0 && ` (${n})`}</div>
                    <div className="font-semibold text-kyro-body">S/ {val.toFixed(2)}</div>
                  </div>
                ))}
              </div>
              <div className="text-center">
                <span className="text-kyro-muted text-xs">TOTAL DEL DÍA</span>
                <div><MoneyTotal value={totalSistema} color={ACCENT.total} size="2rem" /></div>
              </div>
            </GlassPanel>

          </div>

          {/* ═══════════ COLUMNA DERECHA: Caja y Dinero ═══════════ */}
          <div className="space-y-3 lg:sticky lg:top-4">

            <GlassPanel className="kyro-card p-3 border-l-4 border-l-kpi-esperado">
              <Label className="text-xs font-semibold text-kyro-text uppercase tracking-wide">Caja Inicial (Sencillo)</Label>
              <Input id="caja_inicial" type="number" step="0.01" min="0"
                {...register('caja_inicial', { valueAsNumber: true })}
                className="kyro-input mt-1.5 h-9 text-sm font-medium" placeholder="S/ 0.00" />
            </GlassPanel>

            <GlassPanel className="kyro-card p-3 space-y-2">
              <p className="text-xs font-semibold text-kyro-text uppercase tracking-wide">Dinero No Físico</p>
              {([
                ['yape',          'Yape / Plin'],
                ['bipay',         'Bipay'],
                ['transferencia', 'Transferencia'],
              ] as const).map(([field, label]) => (
                <div key={field} className="flex items-center gap-2">
                  <Label className="text-xs text-kyro-body w-28 shrink-0">{label}</Label>
                  <Input type="number" step="0.01" min="0" {...register(field, { valueAsNumber: true })}
                    className="kyro-input h-7 text-xs text-right" placeholder="0.00" />
                </div>
              ))}
              <div className="flex items-center gap-2 border-t border-kyro-border pt-2">
                <Label className="text-xs text-kyro-danger w-28 shrink-0 font-medium">Retiro Bipay</Label>
                <Input type="number" step="0.01" min="0" {...register('retiro_bipay', { valueAsNumber: true })}
                  className="kyro-input h-7 text-xs text-right border-kyro-danger/40 focus:border-kyro-danger" placeholder="0.00" />
              </div>
              <div className="text-right text-xs text-kyro-muted pt-1">
                Total no físico: <span className="font-semibold text-kyro-body">S/ {totalNoFisico.toFixed(2)}</span>
              </div>
            </GlassPanel>

            <GlassPanel className="kyro-card p-3 space-y-2">
              <p className="text-xs font-semibold text-kyro-text uppercase tracking-wide">Ingresos Fijos</p>
              {([
                ['recarga_bipay', 'Recarga Bipay'],
                ['pago_servicio', 'Pago de Servicio'],
                ['pago_krece',    'Pago Krece'],
                ['pago_payjoy',   'Pago Payjoy'],
                ['tickets_tusamy','Tickets Tusamy'],
              ] as const).map(([field, label]) => (
                <div key={field} className="flex items-center gap-2">
                  <Label className="text-xs text-kyro-body w-28 shrink-0">{label}</Label>
                  <Input type="number" step="0.01" min="0" {...register(field, { valueAsNumber: true })}
                    className="kyro-input h-7 text-xs text-right" placeholder="0.00" />
                  {esTienda && (
                    <Button
                      type="button"
                      title="Generar ticket de ingreso"
                      aria-label="Generar ticket de ingreso"
                      variant="glassInfo"
                      size="iconSm"
                      onClick={() => setTicketDesc(label)}
                      className="shrink-0"
                    >
                      <Receipt size={14} />
                    </Button>
                  )}
                </div>
              ))}
            </GlassPanel>

            <GlassPanel className="kyro-card p-3">
              <div className="mb-2">
                <p className="text-xs font-semibold text-kyro-text uppercase tracking-wide">Salidas de Efectivo</p>
              </div>
              {salidaItems.length === 0
                ? <p className="text-[11px] text-kyro-muted italic text-center py-1">Sin salidas registradas</p>
                : (
                  <div className="space-y-1.5">
                    {salidaItems.map(s => (
                      <div key={s.id} className="grid grid-cols-[90px_70px_1fr_auto] gap-1 items-center">
                        <select value={s.tipo} onChange={e => actualizarSalida(s.id, 'tipo', e.target.value)}
                          className="kyro-input h-7 text-xs px-1">
                          {TIPOS_SALIDA.map(t => <option key={t} value={t}>{t}</option>)}
                        </select>
                        <input type="number" step="0.01" min="0" value={s.monto || ''}
                          onChange={e => actualizarSalida(s.id, 'monto', parseFloat(e.target.value) || 0)}
                          placeholder="S/" className="kyro-input h-7 text-xs px-2 text-right" />
                        <input type="text" value={s.motivo}
                          onChange={e => actualizarSalida(s.id, 'motivo', e.target.value)}
                          placeholder="Motivo" className="kyro-input h-7 text-xs px-2" />
                        <Button type="button" variant="glassDanger" size="iconSm" aria-label="Eliminar salida" onClick={() => eliminarSalida(s.id)}>
                          <X size={14} />
                        </Button>
                      </div>
                    ))}
                    <div className="text-right text-xs font-semibold text-kyro-danger pt-1">
                      Total salidas: S/ {total_salidas.toFixed(2)}
                    </div>
                  </div>
                )
              }
              <AddRowButton label="Agregar Salida" accent="#ef4444" onClick={agregarSalida} className="mt-2" />
            </GlassPanel>

            {/* ── Cuadre Final ── */}
            <GlassPanel className="kyro-card p-3 border-kyro-success/40 border-l-4 border-l-kpi-declarado">
              <p className="text-xs font-bold uppercase tracking-wide mb-3 text-kyro-success">Cuadre Final</p>

              <div className="space-y-2 text-sm">
                <div className="flex justify-between items-center">
                  <span className="text-xs text-kyro-muted">Total en cajón:</span>
                  <span className="font-semibold text-kyro-body">S/ {totalEnCajon.toFixed(2)}</span>
                </div>
                <div className="flex justify-between items-center">
                  <span className="text-xs text-kyro-muted">Efectivo esperado:</span>
                  <span className="font-semibold text-kyro-text">S/ {efectivoEsperado.toFixed(2)}</span>
                </div>

                <div className="flex justify-between items-center">
                  <Label className="text-xs font-medium text-kyro-body">Mi Efectivo (entrego):</Label>
                  <Input type="number" step="0.01" min="0" {...register('efectivo_entregado', { valueAsNumber: true })}
                    className="kyro-input h-8 w-32 text-sm text-right font-semibold" placeholder="0.00" />
                </div>

                <div className="flex justify-between items-center pt-1 border-t border-kyro-success/20">
                  <span className="text-xs font-bold text-kyro-body">Diferencia:</span>
                  <span className="font-mono font-bold text-base" style={{
                    color: Math.abs(diferencia) < 0.01 ? 'var(--color-kyro-body)' : diferencia < 0 ? 'var(--color-kyro-danger)' : 'var(--color-kyro-warning)',
                  }}>
                    S/ {diferencia.toFixed(2)}{requiereAprobacion && ' ⚠'}
                  </span>
                </div>

                {requiereAprobacion && (
                  <p className="text-[10px] text-kyro-warning rounded-kyro px-2 py-1 bg-kyro-warning/10 border border-kyro-warning/30">
                    Diferencia mayor a S/10 — el reporte quedará en espera de aprobación.
                  </p>
                )}
              </div>

              {/* Destino del efectivo — toggles glow (paridad legacy) */}
              <div className="mt-3">
                <p className="text-xs font-medium text-kyro-body mb-1.5">Destino del efectivo:</p>
                <div className="grid grid-cols-2 gap-2">
                  {([
                    { value: 'ENTREGADO', label: 'Lo Entregué', accent: 'var(--color-kyro-success)' },
                    { value: 'EN_CAJA',   label: 'En Tienda',   accent: 'var(--color-kyro-warning)' },
                  ] as const).map(opt => {
                    const active = destino === opt.value
                    return (
                      <label key={opt.value}
                        className="flex items-center justify-center gap-1.5 cursor-pointer text-xs font-semibold rounded-md py-2 transition-all"
                        style={active
                          ? { background: opt.accent, color: 'var(--color-kyro-gold-ink)', border: `2px solid ${opt.accent}`,
                              boxShadow: `0 0 20px color-mix(in srgb, ${opt.accent} 50%, transparent)`, transform: 'scale(1.02)' }
                          : { background: 'transparent', color: 'var(--color-kyro-muted)', border: `2px solid ${opt.accent}`, opacity: 0.45 }}>
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
                  <Label className="text-xs text-kyro-muted">A quién / referencia de depósito *</Label>
                  <Input {...register('observaciones')} placeholder="Nombre o número de operación" className="kyro-input mt-1 h-8 text-xs" />
                </div>
              )}
              {destino === 'EN_CAJA' && (
                <div className="mt-2">
                  <Label className="text-xs text-kyro-muted">Observación de caja (opcional)</Label>
                  <textarea {...register('observaciones')} rows={2}
                    className="kyro-input mt-1 w-full px-3 py-1.5 text-xs focus:ring-kyro-warning/40 resize-none"
                    placeholder="Detalle de por qué el efectivo queda en tienda" />
                </div>
              )}
            </GlassPanel>

            <GlassPanel className="kyro-card p-3">
              <Label className="text-xs font-semibold text-kyro-text">Observaciones del Día</Label>
              <textarea {...register('obs_dia')} rows={2}
                className="kyro-input mt-1.5 w-full px-3 py-1.5 text-xs resize-none"
                placeholder="Anotaciones relevantes del día (incidentes, notas, etc.)" />
            </GlassPanel>

          </div>
        </div>

        {guardar.isError && (
          <p className="text-kyro-danger text-sm border border-kyro-danger/30 bg-kyro-danger/10 rounded-kyro px-3 py-2">
            {(guardar.error as { response?: { data?: { error?: string } } })?.response?.data?.error
              ?? 'Error al guardar el reporte. Revisa los datos e intenta de nuevo.'}
          </p>
        )}

        {stockInsuficiente && (
          <p className="text-kyro-danger text-sm border border-kyro-danger/30 bg-kyro-danger/10 rounded-kyro px-3 py-2 font-semibold">
            STOCK INSUFICIENTE — estás reportando {chipsConsumidos} chips pero solo hay {chipsDisponibles} en stock.
          </p>
        )}

        <div className="flex gap-3 pb-8">
          <Button type="submit" variant="gold" disabled={guardar.isPending || stockInsuficiente}
            className="flex-1 h-11 gap-2 text-base font-semibold">
            <UploadCloud size={18} />
            {stockInsuficiente ? 'STOCK INSUFICIENTE' : guardar.isPending ? 'Guardando reporte...' : esEdicion ? 'Aplicar Reprocesado Completo' : 'Guardar Reporte Completo'}
          </Button>
          <Button type="button" variant="outline" className="gap-2" onClick={() => navigate(esEdicion ? `/reportes/${reporteId}` : usuario?.rol === 'admin' ? '/reportes' : '/mi-historial')} disabled={guardar.isPending}>
            <X size={16} /> Cancelar
          </Button>
        </div>

      </form>

      {esTienda && (
        <TicketIngresoModal
          open={ticketDesc !== null}
          onClose={() => setTicketDesc(null)}
          defaultDescripcion={ticketDesc ?? ''}
          tiendaId={usuario?.tienda_id ?? ''}
          agenteId={usuario?.agente_id ?? 0}
          vendedor={usuario?.nombre ?? ''}
        />
      )}

      <AgregarVentaModal
        open={ventaModalOpen}
        onClose={() => setVentaModalOpen(false)}
        onConfirm={handleAgregarVenta}
        vendedores={vendedores}
        planes={planesData}
        inventarioItems={inventarioItems}
      />
    </div>
  )
}
