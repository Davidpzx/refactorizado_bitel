import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useForm, useFieldArray } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useAuth } from '../../hooks/useAuth'
import { Receipt, X, FileText, Cpu, Package, Coins, Users, Save, UploadCloud, FolderDown, Printer, Plus, Pencil, ClipboardList } from 'lucide-react'
import { usePlanesComisiones } from '../../hooks/useReportes'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { Select } from '../../components/ui/select'
import { GlassPanel } from '../../components/ui/GlassPanel'
import { SectionPanel } from '../../components/ui/SectionPanel'
import { AddRowButton } from '../../components/ui/AddRowButton'
import { MoneyTotal } from '../../components/ui/MoneyTotal'
import { PageHeader } from '../../components/PageHeader'
import { useConfirmDialog } from '../../components/ui/confirm-dialog'
import { borradorApi } from '../../services/borrador.api'
import { BipayConsole } from '../../components/BipayConsole'
import { ChipStockBadge } from '../../components/ChipStockBadge'
import { calcularCuadre, calcularComision, validarStock } from '../../lib/cuadre'
import { api } from '../../services/api'
import { crmApi } from '../../services/crm.api'
import { inventarioApi } from '../../services/inventario.api'
import type { InventarioItem } from '../../types/inventario'
import { reportesApi } from '../../services/reportes.api'
import type { ReporteConVentas, VendedorReporte } from '../../types/reporte'
import { TicketIngresoModal } from './cuadre/TicketIngresoModal'
import { PostVentaModal } from './cuadre/PostVentaModal'

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
  tipo_registro:        z.enum(['VENTA','CONSULTA']).optional(),
  que_le_intereso:      z.string().optional().or(z.literal('')),
  motivo_no_compra:     z.string().optional().or(z.literal('')),
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
  tipo_registro:'VENTA', que_le_intereso:'', motivo_no_compra:'',
}

// ── Salidas detalladas ────────────────────────────────────────────────────────

type SalidaItem = { id: string; tipo: string; monto: number; motivo: string }

interface CarritoEquipoItem {
  id: string
  inventario_tienda_id: number
  producto_nombre: string
  imei_serial: string
  tipo_venta: 'EQUIPO' | 'ACCESORIO'
  tipo_pago: 'CONTADO' | 'CUOTAS'
  precio_venta: number
  financiera: string
  por_cobrar_financiera: number
  costo_snap: number
}

const newCarritoItem = (): CarritoEquipoItem => ({
  id: crypto.randomUUID(),
  inventario_tienda_id: 0, producto_nombre: '', imei_serial: '',
  tipo_venta: 'EQUIPO', tipo_pago: 'CONTADO', precio_venta: 0,
  financiera: '', por_cobrar_financiera: 0, costo_snap: 0,
})

// ── Lista compacta de ventas ──────────────────────────────────────────────────

function VentaFila({
  venta, index, vendedores, onEdit, onRemove, onPrint,
}: {
  venta: VentaFormData
  index: number
  vendedores: VendedorReporte[]
  onEdit: () => void
  onRemove: () => void
  onPrint?: () => void
}) {
  const vendedor = vendedores.find(v => v.id === venta.vendedor_id)
  const vNombre  = vendedor?.nombres ?? `Vendedor #${venta.vendedor_id}`
  const t          = venta.tipo_venta
  const isConsulta = venta.tipo_registro === 'CONSULTA'
  const isLinea    = !isConsulta && (t === 'POSTPAGO' || t === 'PREPAGO')
  const isEquipo   = !isConsulta && (t === 'EQUIPO'   || t === 'ACCESORIO')

  const monto = isConsulta ? null
    : isLinea  ? (venta.cobrado_unitario || 0) * (venta.cantidad || 1)
    : isEquipo ? venta.precio_venta || 0
    : venta.monto_total || 0

  const flags: { label: string; color: string }[] = [
    ...(venta.es_extranjero ? [{ label: 'EXT',  color: '#a1a1aa' }] : []),
    ...(venta.es_migracion  ? [{ label: 'MIG',  color: '#06b6d4' }] : []),
    ...(venta.es_upgrade    ? [{ label: 'UPG',  color: '#f59e0b' }] : []),
    ...(venta.es_esim       ? [{ label: 'eSIM', color: '#a78bfa' }] : []),
  ]

  return (
    <div className="flex items-start gap-2 py-2 px-2.5 rounded-lg border border-kyro-border bg-kyro-elevated/40 mb-1.5">
      <span className="text-[10px] text-kyro-muted w-5 shrink-0 pt-0.5 text-center font-mono">{index + 1}</span>

      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-1.5 flex-wrap">
          <span className="text-xs font-semibold text-kyro-text">{vNombre}</span>
          {venta.cliente_dni    && <span className="text-[10px] text-kyro-muted">· {venta.cliente_dni}</span>}
          {venta.cliente_nombre && <span className="text-[10px] text-kyro-muted">· {venta.cliente_nombre}</span>}
        </div>
        <div className="flex items-center gap-1.5 flex-wrap mt-0.5">
          {isLinea && venta.plan_nombre && (
            <span className="text-[10px] text-kyro-body font-medium">{venta.plan_nombre}</span>
          )}
          {isLinea && venta.tipo_alta && (
            <span className="text-[9px] font-bold px-1.5 py-0.5 rounded"
              style={{ background: 'rgba(99,102,241,0.15)', color: 'var(--color-kyro-indigo)' }}>
              {venta.tipo_alta}
            </span>
          )}
          {isConsulta && venta.que_le_intereso && (
            <span className="text-[10px] text-kyro-info">💬 {venta.que_le_intereso}</span>
          )}
          {isConsulta && venta.motivo_no_compra && (
            <span className="text-[10px] text-kyro-muted">· {venta.motivo_no_compra}</span>
          )}
          {isEquipo && venta.producto_nombre && (
            <span className="text-[10px] text-kyro-body">{venta.producto_nombre}</span>
          )}
          {isEquipo && venta.imei_serial && (
            <span className="text-[10px] text-kyro-muted">· {venta.imei_serial}</span>
          )}
          {isEquipo && (
            <span className="text-[9px] px-1.5 py-0.5 rounded font-medium"
              style={{ background: 'rgba(245,158,11,0.15)', color: 'var(--color-kyro-warning)' }}>
              {venta.tipo_pago === 'CUOTAS' ? 'Cuotas' : 'Contado'}
            </span>
          )}
          {t === 'OTROS_FLUJO' && venta.subtipo && (
            <span className="text-[10px] text-kyro-body">{venta.subtipo}</span>
          )}
          {t === 'APOYO' && (
            <>
              {venta.tienda_destino && <span className="text-[10px] font-medium text-kyro-body">{venta.tienda_destino}</span>}
              {venta.plan_nombre    && <span className="text-[10px] text-kyro-muted">· {venta.plan_nombre}</span>}
              {venta.cantidad > 1   && <span className="text-[10px] text-kyro-muted">× {venta.cantidad}</span>}
            </>
          )}
          {flags.map(f => (
            <span key={f.label} className="text-[9px] font-bold px-1.5 py-0.5 rounded"
              style={{ background: `color-mix(in srgb, ${f.color} 20%, transparent)`, color: f.color }}>
              {f.label}
            </span>
          ))}
        </div>
      </div>

      {monto !== null
        ? <span className="text-xs font-semibold text-kyro-text shrink-0 pt-0.5 tabular-nums">S/ {monto.toFixed(2)}</span>
        : <span className="text-[10px] text-kyro-muted shrink-0 pt-0.5">consulta</span>
      }

      <div className="flex gap-1 shrink-0">
        {onPrint && (
          <Button type="button" variant="glassInfo" size="iconSm" title="Imprimir ticket" onClick={onPrint}>
            <Printer size={13} />
          </Button>
        )}
        <Button type="button" variant="outline" size="iconSm" title="Editar" onClick={onEdit}>
          <Pencil size={13} />
        </Button>
        <Button type="button" variant="glassDanger" size="iconSm" title="Eliminar" onClick={onRemove}>
          <X size={13} />
        </Button>
      </div>
    </div>
  )
}

// ── Modal: Agregar Registro (Venta o Consulta) ──────────────────────────────────

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
  tipo_registro:         'VENTA' | 'CONSULTA'
  que_le_intereso:       string
  motivo_no_compra:      string
}

const MODAL_DEFAULT: ModalVentaState = {
  vendedor_id: 0, seccion: '',
  cliente_dni: '', cliente_nombre: '',
  es_extranjero: false, es_migracion: false, es_upgrade: false, es_esim: false,
  plan_nombre: '', tipo_alta: 'MNP', cobrado_unitario: 0, plan_anterior: 0, cantidad: 1,
  producto_nombre: '', inventario_tienda_id: 0, imei_serial: '',
  tipo_pago: 'CONTADO', precio_venta: 0, financiera: '', por_cobrar_financiera: 0, costo_snap: 0,
  subtipo: '', monto_otros: 0, tienda_destino: '',
  tipo_registro: 'VENTA', que_le_intereso: '', motivo_no_compra: '',
}

const MOTIVOS_NO_COMPRA: { grupo: string; opciones: string[] }[] = [
  {
    grupo: 'Rechazo del Sistema / Crédito',
    opciones: [
      'Error de sistema (BITEL)',
      'No aprobó evaluación crediticia',
      'Tiene deuda pendiente con Bitel',
    ],
  },
  {
    grupo: 'Precio / Condiciones',
    opciones: [
      'Monto del plan muy alto',
      'Precio del equipo muy alto',
      'Cliente indeciso / necesita pensarlo',
      'Regresará después',
    ],
  },
  {
    grupo: 'Stock / Disponibilidad',
    opciones: [
      'Sin stock del equipo solicitado',
      'Sin stock del accesorio',
      'Sin chips disponibles',
    ],
  },
  {
    grupo: 'Cobertura / Servicio',
    opciones: [
      'Mala cobertura en su zona',
      'Ya es cliente Bitel',
    ],
  },
  {
    grupo: 'Pasó a Venta',
    opciones: [
      'Se concretó en VENTA',
    ],
  },
]

const MODAL_SECCIONES: { value: Exclude<ModalSeccion,''>; label: string; color: string }[] = [
  { value: 'POSTPAGO',    label: 'Postpago',           color: 'var(--color-kyro-indigo)'  },
  { value: 'PREPAGO',     label: 'Prepago / Chip',     color: 'var(--color-kyro-info)'    },
  { value: 'EQUIPO',      label: 'Equipo / Accesorio', color: 'var(--color-kyro-warning)' },
  { value: 'OTROS_FLUJO', label: 'Otros Ingresos',     color: 'var(--color-kyro-body)'    },
  { value: 'APOYO',       label: 'Ventas de Apoyo',    color: 'var(--color-kyro-gold)'    },
]

function AgregarRegistroModal({
  open, onClose, onConfirm, vendedores, planes, inventarioItems, initialData, isEdit,
}: {
  open: boolean
  onClose: () => void
  onConfirm: (data: ModalVentaState[]) => void
  vendedores: VendedorReporte[]
  planes: Array<{ nombre_plan: string; tipo_alta: string }>
  inventarioItems: InventarioItem[]
  initialData?: ModalVentaState
  isEdit?: boolean
}) {
  const [m, setM] = useState<ModalVentaState>(MODAL_DEFAULT)
  const [dniStatus, setDniStatus] = useState<'idle' | 'loading' | 'found' | 'found_no_verificado' | 'notfound'>('idle')
  const [crmStatus, setCrmStatus] = useState<'idle' | 'loading' | 'found' | 'notfound'>('idle')
  const [carrito, setCarrito] = useState<CarritoEquipoItem[]>([newCarritoItem()])

  // "Recuperar Cliente": busca el DNI en el CRM ligero (crm_clientes) y pre-rellena.
  const recuperarClienteCrm = () => {
    if (!/^\d{8}$/.test(m.cliente_dni)) return
    setCrmStatus('loading')
    api.get<{ ok: boolean; nombres: string; apellidos: string }>(`/v1/clientes-crm/${m.cliente_dni}`)
      .then(res => {
        const nombre = [res.data.nombres, res.data.apellidos].filter(Boolean).join(' ')
        if (nombre) {
          setM(prev => ({ ...prev, cliente_nombre: nombre }))
          setCrmStatus('found')
        } else { setCrmStatus('notfound') }
      })
      .catch(() => setCrmStatus('notfound'))
  }

  useEffect(() => {
    if (open) {
      setM(initialData ?? MODAL_DEFAULT)
      setDniStatus('idle')
      // Pre-rellenar carrito si editamos un equipo/accesorio existente
      if (isEdit && initialData && (initialData.seccion === 'EQUIPO' || initialData.seccion === 'ACCESORIO')) {
        setCarrito([{
          id: crypto.randomUUID(),
          inventario_tienda_id: initialData.inventario_tienda_id,
          producto_nombre: initialData.producto_nombre,
          imei_serial: initialData.imei_serial,
          tipo_venta: initialData.seccion as 'EQUIPO' | 'ACCESORIO',
          tipo_pago: initialData.tipo_pago,
          precio_venta: initialData.precio_venta,
          financiera: initialData.financiera,
          por_cobrar_financiera: initialData.por_cobrar_financiera,
          costo_snap: initialData.costo_snap,
        }])
      } else {
        setCarrito([newCarritoItem()])
      }
    }
  }, [open, initialData])

  const updCarrito = (id: string, changes: Partial<CarritoEquipoItem>) =>
    setCarrito(prev => prev.map(it => it.id === id ? { ...it, ...changes } : it))
  const addCarritoItem  = () => setCarrito(prev => [...prev, newCarritoItem()])
  const removeCarritoItem = (id: string) => setCarrito(prev => prev.filter(it => it.id !== id))

  // Auto-lookup cuando DNI tiene exactamente 8 dígitos
  useEffect(() => {
    if (!/^\d{8}$/.test(m.cliente_dni)) { setDniStatus('idle'); return }
    setDniStatus('loading')
    api.get<Record<string, string | undefined>>(`/v1/dni/${m.cliente_dni}`)
      .then(res => {
        const d = res.data
        // La API puede devolver camelCase (apellidoPaterno) o snake_case (apellido_paterno)
        const nombre = d.nombre_completo
          ?? d.nombreCompleto
          ?? [
              d.nombres,
              d.apellidoPaterno  ?? d.apellido_paterno,
              d.apellidoMaterno  ?? d.apellido_materno,
            ].filter(Boolean).join(' ')
        if (nombre) {
          setM(prev => ({ ...prev, cliente_nombre: nombre }))
          // Solo RENIEC_API es una fuente verificada; el cache local del CRM puede
          // haber guardado un nombre tipeado a mano (MANUAL_CON_FALLBACK).
          setDniStatus(d.fuente === 'RENIEC_API' ? 'found' : 'found_no_verificado')
        } else { setDniStatus('notfound') }
      })
      .catch(() => setDniStatus('notfound'))
  }, [m.cliente_dni])

  const upd = <K extends keyof ModalVentaState>(k: K, v: ModalVentaState[K]) =>
    setM(prev => ({ ...prev, [k]: v }))

  const cambiarTipo = (tipo: 'VENTA' | 'CONSULTA') => {
    if (tipo === 'CONSULTA') {
      setM(prev => ({
        ...prev, tipo_registro: 'CONSULTA',
        seccion: '', plan_nombre: '', tipo_alta: 'MNP', cobrado_unitario: 0,
        precio_venta: 0, costo_snap: 0, cantidad: 1, monto_otros: 0,
        financiera: '', subtipo: '', tienda_destino: '', inventario_tienda_id: 0,
        imei_serial: '', producto_nombre: '', por_cobrar_financiera: 0,
        plan_anterior: 0, es_extranjero: false, es_migracion: false,
        es_upgrade: false, es_esim: false, tipo_pago: 'CONTADO',
        que_le_intereso: '', motivo_no_compra: '',
      }))
    } else {
      setM(prev => ({ ...prev, tipo_registro: 'VENTA', que_le_intereso: '', motivo_no_compra: '' }))
    }
  }

  const s        = m.seccion
  const esLinea  = s === 'POSTPAGO' || s === 'PREPAGO'
  const esEquipo = s === 'EQUIPO' || s === 'ACCESORIO'
  const esOtros  = s === 'OTROS_FLUJO'
  const esApoyo  = s === 'APOYO'

  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" />
      <div className="relative z-10 kyro-card w-full max-w-lg max-h-[90vh] overflow-y-auto p-5 space-y-4 shadow-2xl">

        {/* Header */}
        <div className="flex items-center justify-between border-b border-kyro-border pb-3">
          <h2 className="font-semibold text-base text-kyro-text">Agregar Registro</h2>
          {m.cliente_dni && <Button type="button" variant="glassDanger" size="iconSm" onClick={onClose}><X size={14} /></Button>}
        </div>

        {/* 1. Vendedor */}
        <div>
          <Label className="text-[11px] font-semibold uppercase tracking-wide text-kyro-muted">1. Vendedor</Label>
          <Select value={m.vendedor_id} onChange={e => upd('vendedor_id', Number(e.target.value))} className="kyro-input mt-1 h-9 text-sm">
            <option value={0}>Selecciona el vendedor...</option>
            {vendedores.map(v => <option key={v.id} value={v.id}>{v.nombres} ({v.tienda_base})</option>)}
          </Select>
        </div>

        {/* 2. DNI / Cliente — siempre visible */}
        <div>
          <Label className="text-[11px] font-semibold uppercase tracking-wide text-kyro-muted">2. Cliente <span className="text-kyro-danger">*</span></Label>
          <div className="grid grid-cols-2 gap-2 mt-1.5 items-start">
            <div>
              <Label className="text-[10px] text-kyro-muted">
                DNI / Celular <span className="text-kyro-danger">*</span>
              </Label>
              <Input value={m.cliente_dni} onChange={e => { upd('cliente_dni', e.target.value); setDniStatus('idle') }} maxLength={15} placeholder="DNI (8 dígitos) o celular" className="kyro-input mt-0.5 h-8 text-xs font-mono" />
              <p className="mt-0.5 text-[9px] h-3 leading-3">
                {dniStatus === 'loading'             && <span className="text-kyro-muted animate-pulse">buscando…</span>}
                {dniStatus === 'found'                && <span className="text-kyro-success">✓ encontrado (RENIEC)</span>}
                {dniStatus === 'found_no_verificado'  && <span className="text-kyro-warning">✓ encontrado (no verificado)</span>}
                {dniStatus === 'notfound' && crmStatus === 'idle' && <span className="text-kyro-danger">no encontrado</span>}
                {crmStatus === 'found'    && <span className="text-kyro-success">✓ recuperado del CRM</span>}
                {crmStatus === 'notfound' && <span className="text-kyro-danger">sin registro previo en CRM</span>}
              </p>
              {/^\d{8}$/.test(m.cliente_dni) && dniStatus !== 'found' && dniStatus !== 'found_no_verificado' && (
                <button type="button" onClick={recuperarClienteCrm} disabled={crmStatus === 'loading'}
                  className="mt-0.5 rounded border border-kyro-gold/40 bg-kyro-gold/10 px-2 py-0.5 text-[9px] font-bold text-kyro-gold disabled:opacity-50">
                  {crmStatus === 'loading' ? 'Buscando…' : 'Recuperar Cliente (CRM)'}
                </button>
              )}
            </div>
            <div>
              <Label className="text-[10px] text-kyro-muted">Nombre completo</Label>
              <Input value={m.cliente_nombre} onChange={e => upd('cliente_nombre', e.target.value)} placeholder="Nombre del cliente" className="kyro-input mt-0.5 h-8 text-xs" />
            </div>
          </div>
          {!m.cliente_dni && <p className="mt-1 text-[10px] text-kyro-danger">Ingresa el DNI para poder continuar y cerrar</p>}
        </div>

        {/* 3. Tipo de registro */}
        <div>
          <Label className="text-[11px] font-semibold uppercase tracking-wide text-kyro-muted">2. Tipo de registro</Label>
          <div className="flex gap-2 mt-1.5">
            {(['VENTA','CONSULTA'] as const).map(tipo => (
              <button key={tipo} type="button"
                onClick={() => cambiarTipo(tipo)}
                className="flex-1 rounded-lg px-3 py-2 text-xs font-semibold transition-all border"
                style={m.tipo_registro === tipo
                  ? { background: tipo === 'VENTA' ? 'var(--color-kyro-gold)' : 'var(--color-kyro-info)', color: '#fff', borderColor: tipo === 'VENTA' ? 'var(--color-kyro-gold)' : 'var(--color-kyro-info)' }
                  : { background: 'transparent', color: 'var(--color-kyro-muted)', borderColor: 'var(--color-kyro-border)' }}
              >{tipo === 'VENTA' ? '🛒 Venta' : '💬 Consulta'}</button>
            ))}
          </div>
        </div>

        {/* 2b. Sección — solo si VENTA y no es edición */}
        {m.tipo_registro === 'VENTA' && !isEdit && <div>
          <Label className="text-[11px] font-semibold uppercase tracking-wide text-kyro-muted">4. Sección</Label>
          <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 mt-1.5">
            {MODAL_SECCIONES.map(sec => {
              const active = m.seccion === sec.value
              return (
                <button key={sec.value} type="button"
                  onClick={() => { upd('seccion', sec.value); setCarrito([newCarritoItem()]) }}
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
        </div>}



        {/* Campos consulta */}
        {m.tipo_registro === 'CONSULTA' && (
          <div className="space-y-2.5 rounded-lg border border-kyro-border p-3 bg-kyro-elevated/40">
            <p className="text-[10px] text-kyro-muted font-semibold uppercase tracking-wide">Detalle de consulta</p>
            <div>
              <Label className="text-[10px] text-kyro-muted">¿Qué le interesó? *</Label>
              <Input
                value={m.que_le_intereso}
                onChange={e => upd('que_le_intereso', e.target.value)}
                placeholder="Ej: Postpago familiar, equipo Samsung A15..."
                className="kyro-input mt-0.5 h-8 text-xs"
              />
            </div>
            <div>
              <Label className="text-[10px] text-kyro-muted">¿Por qué no compró?</Label>
              <select
                value={m.motivo_no_compra}
                onChange={e => upd('motivo_no_compra', e.target.value)}
                className="kyro-input mt-0.5 h-8 text-xs w-full"
              >
                <option value="">— Sin definir —</option>
                {MOTIVOS_NO_COMPRA.map(g => (
                  <optgroup key={g.grupo} label={g.grupo}>
                    {g.opciones.map(op => (
                      <option key={op} value={op}>{op}</option>
                    ))}
                  </optgroup>
                ))}
              </select>
            </div>
          </div>
        )}

        {m.tipo_registro === 'VENTA' && esLinea && (
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

        {m.tipo_registro === 'VENTA' && esEquipo && (
          <div className="space-y-2">
            <Label className="text-[11px] font-semibold uppercase tracking-wide text-kyro-muted">4. Productos</Label>

            {carrito.map((item, idx) => (
              <div key={item.id} className="rounded-lg border border-kyro-border bg-kyro-elevated/30 p-2.5 space-y-2">
                {/* Selector de producto */}
                <div className="flex gap-2 items-end">
                  <div className="flex-1 min-w-0">
                    <Label className="text-[10px] text-kyro-muted">Producto #{idx + 1} *</Label>
                    <Select
                      value={item.inventario_tienda_id}
                      onChange={e => {
                        const id = Number(e.target.value)
                        const found = inventarioItems.find(it => it.id === id)
                        updCarrito(item.id, {
                          inventario_tienda_id: id,
                          producto_nombre: found?.producto_nombre ?? '',
                          precio_venta: found ? Number(found.precio_normal) : 0,
                          costo_snap: found ? Number(found.precio_costo) : 0,
                          imei_serial: found?.imei_serial ?? '',
                          tipo_venta: (found?.tipo === 'ACCESORIO' ? 'ACCESORIO' : 'EQUIPO'),
                        })
                      }}
                      className="kyro-input mt-0.5 h-8 text-xs"
                    >
                      <option value={0}>— Selecciona producto —</option>
                      {inventarioItems.map(it => (
                        <option key={it.id} value={it.id}>
                          {it.producto_nombre} · {it.tipo} · S/ {Number(it.precio_normal).toFixed(2)}
                          {it.cantidad > 1 ? ` (×${it.cantidad})` : ''}
                        </option>
                      ))}
                    </Select>
                  </div>
                  {carrito.length > 1 && (
                    <Button type="button" variant="glassDanger" size="iconSm" onClick={() => removeCarritoItem(item.id)}>
                      <X size={13} />
                    </Button>
                  )}
                </div>

                {/* IMEI + tipo pago + precio */}
                <div className="grid grid-cols-[1fr_100px_90px] gap-2">
                  <div>
                    <Label className="text-[10px] text-kyro-muted">IMEI / Serie</Label>
                    <Input value={item.imei_serial} onChange={e => updCarrito(item.id, { imei_serial: e.target.value })}
                      maxLength={50} placeholder="Opcional" className="kyro-input mt-0.5 h-7 text-xs" />
                  </div>
                  <div>
                    <Label className="text-[10px] text-kyro-muted">Tipo pago</Label>
                    <Select value={item.tipo_pago} onChange={e => updCarrito(item.id, { tipo_pago: e.target.value as 'CONTADO' | 'CUOTAS' })}
                      className="kyro-input mt-0.5 h-7 text-xs">
                      <option value="CONTADO">Contado</option>
                      <option value="CUOTAS">Cuotas</option>
                    </Select>
                  </div>
                  <div>
                    <Label className="text-[10px] text-kyro-muted">Precio S/</Label>
                    <Input type="number" step="0.01" min="0" value={item.precio_venta || ''}
                      onChange={e => updCarrito(item.id, { precio_venta: parseFloat(e.target.value) || 0 })}
                      className="kyro-input mt-0.5 h-7 text-xs text-right" />
                  </div>
                </div>

                {/* Financiera (solo cuotas) */}
                {item.tipo_pago === 'CUOTAS' && (
                  <div className="grid grid-cols-3 gap-2 pt-2 border-t border-dashed border-kyro-border">
                    <div>
                      <Label className="text-[10px] text-kyro-muted">Financiera</Label>
                      <Select value={item.financiera} onChange={e => updCarrito(item.id, { financiera: e.target.value })}
                        className="kyro-input mt-0.5 h-7 text-xs">
                        <option value="">Ninguna</option>
                        {FINANCIERAS.map(f => <option key={f}>{f}</option>)}
                      </Select>
                    </div>
                    <div>
                      <Label className="text-[10px] text-kyro-muted">Por cobrar fin.</Label>
                      <Input type="number" step="0.01" min="0" value={item.por_cobrar_financiera || ''}
                        onChange={e => updCarrito(item.id, { por_cobrar_financiera: parseFloat(e.target.value) || 0 })}
                        className="kyro-input mt-0.5 h-7 text-xs" />
                    </div>
                    <div>
                      <Label className="text-[10px] text-kyro-muted">Costo snap</Label>
                      <Input type="number" step="0.01" min="0" value={item.costo_snap || ''}
                        onChange={e => updCarrito(item.id, { costo_snap: parseFloat(e.target.value) || 0 })}
                        className="kyro-input mt-0.5 h-7 text-xs" />
                    </div>
                  </div>
                )}
              </div>
            ))}

            {/* Agregar otro producto */}
            <button type="button" onClick={addCarritoItem}
              className="w-full text-xs text-kyro-muted border border-dashed border-kyro-border rounded-lg py-2 hover:border-kyro-gold hover:text-kyro-gold transition-colors">
              + Agregar otro producto
            </button>

            {/* Total carrito */}
            {carrito.length > 1 && (
              <div className="text-right text-xs font-semibold text-kyro-body">
                Total: S/ {carrito.reduce((a, it) => a + (it.precio_venta || 0), 0).toFixed(2)}
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
          <Button type="button" variant="gold" className="flex-1 gap-2 h-10"
            disabled={
              !m.cliente_dni || !m.vendedor_id ||
              (m.tipo_registro === 'VENTA' && !m.seccion) ||
              (m.tipo_registro === 'VENTA' && esEquipo && !carrito.some(it => it.producto_nombre && it.precio_venta > 0))
            }
            onClick={() => {
              if (m.tipo_registro === 'VENTA' && esEquipo) {
                const validos = carrito.filter(it => it.producto_nombre && it.precio_venta > 0)
                onConfirm(validos.map(item => ({
                  ...m,
                  seccion: item.tipo_venta as ModalSeccion,
                  producto_nombre: item.producto_nombre,
                  inventario_tienda_id: item.inventario_tienda_id,
                  imei_serial: item.imei_serial,
                  tipo_pago: item.tipo_pago,
                  precio_venta: item.precio_venta,
                  financiera: item.financiera,
                  por_cobrar_financiera: item.por_cobrar_financiera,
                  costo_snap: item.costo_snap,
                })))
              } else {
                onConfirm([m])
              }
              onClose()
            }}>
            {isEdit ? <><Pencil size={15} /> Guardar Cambios</> : <><Plus size={15} /> Guardar Registro{esEquipo && carrito.filter(it => it.precio_venta > 0).length > 1 ? ` (${carrito.filter(it => it.precio_venta > 0).length})` : ''}</>}
          </Button>
          {m.cliente_dni && <Button type="button" variant="outline" onClick={onClose}>Cancelar</Button>}
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
  const navigate     = useNavigate()
  const queryClient  = useQueryClient()
  const { id }       = useParams<{ id: string }>()
  const { usuario }  = useAuth()
  const { data: planesData = [] } = usePlanesComisiones()
  const confirmDialog = useConfirmDialog()
  const esEdicion = mode === 'edit'
  const esAdminReporte = usuario?.rol === 'admin' && !esEdicion
  const reporteId = Number(id ?? 0)
  const inicializadoRef        = useRef(false)
  const lastTicketedCount      = useRef(0)       // cuántas ventas ya tienen ticket
  const cerrarCajaRef          = useRef(false)   // true → tras guardar, limpiar para cuadre nuevo
  const [pendingPrintIds, setPendingPrintIds] = useState<number[]>([])
  const [postVenta, setPostVenta] = useState<{ ticketId: number; ventaId: number | null } | null>(null)

  // ── Reporte activo persistido en localStorage (modo crear) ───────────────────
  // Clave por usuario — así cada agente tiene su propio cuadre activo
  const ACTIVO_LS_KEY = usuario ? `reporte_activo_${usuario.id}` : null
  const [savedReporteId, setSavedReporteId] = useState<number | null>(null)

  // Leer el ID del reporte activo del localStorage al montar
  useEffect(() => {
    if (esEdicion || !ACTIVO_LS_KEY) return
    const stored = localStorage.getItem(ACTIVO_LS_KEY)
    if (stored) {
      const id = Number(stored)
      if (id > 0) setSavedReporteId(id)
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ACTIVO_LS_KEY])

  // ── Queries ──────────────────────────────────────────────────────────────────
  const { data: reporteEditar, isLoading: cargandoReporte } = useQuery({
    queryKey: ['reporte', reporteId],
    queryFn: () => reportesApi.obtener(reporteId),
    enabled: esEdicion && reporteId > 0,
  })

  // Carga silenciosa del reporte activo cuando la página se restaura (sin navegar)
  const { data: savedReporteData } = useQuery({
    queryKey: ['reporte-activo', savedReporteId],
    queryFn: () => reportesApi.obtener(savedReporteId!),
    enabled: !esEdicion && savedReporteId !== null && savedReporteId > 0,
  })

  // El reporte "en uso" unifica edit mode y modo crear restaurado
  const activeReport    = esEdicion ? reporteEditar : savedReporteData
  const activeReporteId = esEdicion ? reporteId     : savedReporteId

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
    if (usuario && !esEdicion) {
      if (usuario.rol !== 'admin') {
        setValue('agente_id', usuario.agente_id ?? 0)
        setValue('tienda_id', usuario.tienda_id)
      } else {
        // Admin siempre empieza sin tienda seleccionada
        setValue('tienda_id', '')
      }
    }
  }, [esEdicion, usuario, setValue])

  const { fields, remove, replace } = useFieldArray({ control, name: 'ventas' })

  // ── Sincronizar ventas del form con la respuesta del servidor ───────────────
  const syncVentasDesdeReporte = (reporte: ReporteConVentas) => {
    // Actualizar cache de React Query para que al volver a la pestaña los datos sean correctos
    queryClient.setQueryData(['reporte-activo', reporte.id], reporte)
    const ventasSync: VentaFormData[] = reporte.ventas.map(venta => {
      const nombrePlan = venta.linea?.plan_nombre_snap ?? ''
      const tipoPago = venta.equipo?.tipo_pago === 'CUOTAS' ? 'CUOTAS' : 'CONTADO'
      return {
        ...VENTA_DEFAULT,
        venta_id: venta.id,
        vendedor_id: venta.vendedor_id,
        tipo_venta: venta.tipo_venta as VentaFormData['tipo_venta'],
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
        tipo_pago: tipoPago as 'CONTADO' | 'CUOTAS',
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
    replace(ventasSync)
  }

  // ── Modal agregar / editar venta ───────────────────────────────────────────
  const [ventaModalOpen, setVentaModalOpen] = useState(false)
  const [editIndex,      setEditIndex]      = useState<number | null>(null)
  const [editData,       setEditData]       = useState<ModalVentaState | undefined>(undefined)
  const [ventaSaving,    setVentaSaving]    = useState(false)
  const [cerrandoCaja,   setCerrandoCaja]   = useState(false)

  const openEdit = (idx: number) => {
    const v = ventas[idx]
    const esConsulta = v.tipo_registro === 'CONSULTA'
    const seccion = esConsulta ? '' : (v.tipo_venta as Exclude<ModalSeccion, ''>)
    setEditData({
      ...MODAL_DEFAULT,
      vendedor_id: v.vendedor_id,
      seccion,
      tipo_registro: v.tipo_registro ?? 'VENTA',
      que_le_intereso:  v.que_le_intereso  ?? '',
      motivo_no_compra: v.motivo_no_compra ?? '',
      cliente_dni:  v.cliente_dni  ?? '',
      cliente_nombre: v.cliente_nombre ?? '',
      es_extranjero: !!v.es_extranjero,
      es_migracion:  !!v.es_migracion,
      es_upgrade:    !!v.es_upgrade,
      es_esim:       !!v.es_esim,
      plan_nombre:   v.plan_nombre  ?? '',
      tipo_alta:     v.tipo_alta    ?? 'MNP',
      cobrado_unitario: v.cobrado_unitario,
      plan_anterior:    v.plan_anterior,
      cantidad:         v.cantidad,
      producto_nombre:  v.producto_nombre  ?? '',
      inventario_tienda_id: v.inventario_tienda_id,
      imei_serial:  v.imei_serial  ?? '',
      tipo_pago:    v.tipo_pago,
      precio_venta: v.precio_venta,
      financiera:   v.financiera   ?? '',
      por_cobrar_financiera: v.por_cobrar_financiera,
      costo_snap:   v.costo_snap,
      subtipo:      v.subtipo      ?? '',
      monto_otros:  v.monto_total,
      tienda_destino: v.tienda_destino ?? '',
    })
    setEditIndex(idx)
    setVentaModalOpen(true)
  }

  const buildVenta = (data: ModalVentaState): VentaFormData => {
    const base = { vendedor_id: data.vendedor_id, cliente_dni: data.cliente_dni, cliente_nombre: data.cliente_nombre }
    switch (data.seccion) {
      case 'POSTPAGO':
      case 'PREPAGO': {
        const monto = (data.cobrado_unitario || 0) * (data.cantidad || 1)
        return ventaNueva({ ...base, tipo_venta: data.seccion, monto_total: monto, efectivo_inicial: monto,
          es_extranjero: data.es_extranjero, es_migracion: data.es_migracion, es_upgrade: data.es_upgrade, es_esim: data.es_esim,
          plan_nombre: data.plan_nombre, tipo_alta: data.tipo_alta, cobrado_unitario: data.cobrado_unitario,
          plan_anterior: data.plan_anterior, cantidad: data.cantidad })
      }
      case 'EQUIPO':
      case 'ACCESORIO': {
        const monto = data.precio_venta || 0
        const efectivo = data.tipo_pago === 'CUOTAS' ? (data.por_cobrar_financiera || 0) : monto
        return ventaNueva({ ...base, tipo_venta: data.seccion, monto_total: monto, efectivo_inicial: efectivo,
          producto_nombre: data.producto_nombre, inventario_tienda_id: data.inventario_tienda_id,
          imei_serial: data.imei_serial, tipo_pago: data.tipo_pago, precio_venta: monto,
          financiera: data.financiera, por_cobrar_financiera: data.por_cobrar_financiera, costo_snap: data.costo_snap })
      }
      case 'OTROS_FLUJO':
        return ventaNueva({ vendedor_id: data.vendedor_id, tipo_venta: 'OTROS_FLUJO', subtipo: data.subtipo,
          monto_total: data.monto_otros, efectivo_inicial: data.monto_otros })
      case 'APOYO': {
        const monto = (data.cobrado_unitario || 0) * (data.cantidad || 1)
        return ventaNueva({ vendedor_id: data.vendedor_id, tipo_venta: 'APOYO', monto_total: monto, efectivo_inicial: monto,
          tienda_destino: data.tienda_destino, plan_nombre: data.plan_nombre,
          cantidad: data.cantidad, cobrado_unitario: data.cobrado_unitario, tipo_alta: 'LN' })
      }
      default:
        return ventaNueva({ vendedor_id: data.vendedor_id })
    }
  }

  const [crmMsg, setCrmMsg] = useState('')

  // ── Ticket de venta ────────────────────────────────────────────────────────
  const crearTicketsVentas = async (lista: VentaFormData[], ventaIds: number[] = []) => {
    if (lista.length === 0) return
    for (let i = 0; i < lista.length; i++) {
      const v       = lista[i]
      const ventaId = ventaIds[i] ?? (v.venta_id ?? null)

      const isLinea  = v.tipo_venta === 'POSTPAGO' || v.tipo_venta === 'PREPAGO'
      const isEquipo = v.tipo_venta === 'EQUIPO'   || v.tipo_venta === 'ACCESORIO'
      const monto    = isLinea  ? (v.cobrado_unitario || 0) * (v.cantidad || 1)
                     : isEquipo ? (v.precio_venta || 0)
                     : (v.monto_total || 0)
      if (monto <= 0) continue

      const desc = isLinea
        ? `${v.tipo_venta === 'POSTPAGO' ? 'Postpago' : 'Prepago'} · ${[v.plan_nombre, v.tipo_alta].filter(Boolean).join(' · ')}`
        : isEquipo
        ? `${v.tipo_venta} · ${v.producto_nombre || ''}${v.imei_serial ? ' · ' + v.imei_serial : ''}`
        : v.tipo_venta === 'APOYO'
        ? `Apoyo ${v.tienda_destino || ''} · ${v.plan_nombre || ''}`
        : v.subtipo || v.tipo_venta

      const vendedorObj = vendedores.find(vv => vv.id === v.vendedor_id)
      try {
        const res = await api.post<{ ok: boolean; id: number }>('/v1/tickets', {
          venta_id:       ventaId ?? undefined,
          tienda_id:      tiendaSeleccionada,
          agente_id:      getValues('agente_id') || usuario?.agente_id || undefined,
          vendedor:       vendedorObj?.nombres ?? usuario?.nombre ?? '',
          descripcion:    desc.trim(),
          monto,
          cantidad:       v.cantidad || 1,
          nombre_cliente: v.cliente_nombre || '',
          dni_cliente:    v.cliente_dni    || '',
        })
        if (res.data?.ok && res.data?.id) {
          // Mostrar modal unificado (ticket + comprobante) solo para el primer resultado
          if (i === 0) setPostVenta({ ticketId: res.data.id, ventaId })
          else setPendingPrintIds(prev => [...prev, res.data.id])
        }
      } catch (err: unknown) {
        const msg = (err as { response?: { data?: unknown } })?.response?.data ?? err
        setBorradorMsg(`Error ticket: ${JSON.stringify(msg)}`)
        setTimeout(() => setBorradorMsg(''), 6000)
      }
    }
  }

  const handleVentaConfirm = async (items: ModalVentaState[]) => {
    const consultaItems = items.filter(d => d.tipo_registro === 'CONSULTA')
    const ventaItems    = items.filter(d => d.tipo_registro !== 'CONSULTA')

    // Guardar consultas directo al CRM (no tocan el cuadre)
    consultaItems.forEach(d => {
      const notas = [
        d.cliente_dni    && `DNI: ${d.cliente_dni}`,
        d.cliente_nombre && `Nombre: ${d.cliente_nombre}`,
        d.que_le_intereso  && `Le interesó: ${d.que_le_intereso}`,
        d.motivo_no_compra && `Motivo: ${d.motivo_no_compra}`,
      ].filter(Boolean).join('\n')
      const estadoCrm = d.motivo_no_compra === 'Se concretó en VENTA' ? 'CONVERTIDO'
        : d.motivo_no_compra === 'Regresará después' ? 'INTERESADO'
        : 'NUEVO'
      crmApi.leads.create({
        agente_id: d.vendedor_id,
        tienda_id: tiendaSeleccionada,
        estado: estadoCrm,
        fuente: 'PRESENCIAL',
        notas,
      }).then(() => {
        setCrmMsg(`Lead CRM guardado · DNI ${d.cliente_dni}`)
        setTimeout(() => setCrmMsg(''), 3500)
      }).catch(() => {
        setCrmMsg('Error al guardar consulta en CRM')
        setTimeout(() => setCrmMsg(''), 3500)
      })
    })

    // Cliente Activo: registrar el cliente de cada venta en el CRM ligero
    // (crm_clientes + interacción) — fire-and-forget, no bloquea el cuadre.
    ventaItems.forEach(d => {
      if (!/^\d{8}$/.test(d.cliente_dni) || !d.cliente_nombre?.trim()) return
      const partes    = d.cliente_nombre.trim().split(/\s+/)
      const nombres   = partes.slice(0, Math.max(1, partes.length - 2)).join(' ')
      const apellidos = partes.slice(Math.max(1, partes.length - 2)).join(' ') || '—'
      const vendedorObj = vendedores.find(vv => vv.id === d.vendedor_id)
      api.post('/v1/clientes-crm', {
        dni: d.cliente_dni,
        nombres,
        apellidos,
        operacion: d.seccion === 'POSTPAGO' && d.tipo_alta === 'MNP' ? 'Portabilidad' : (d.seccion || 'VENTA'),
        producto_interes: d.plan_nombre || d.producto_nombre || undefined,
        agente: vendedorObj?.nombres,
        fuente: 'RENIEC_API',
      }).catch(() => { /* silencioso: el CRM no debe bloquear la venta */ })
    })

    // Agregar ventas reales → auto-guardar en BD inmediatamente
    if (ventaItems.length === 0) {
      setVentaModalOpen(false)
      setEditIndex(null)
      setEditData(undefined)
      return
    }

    setVentaSaving(true)
    try {
      // IDs conocidos antes del loop para detectar ventas nuevas
      const idsConocidos = new Set(ventas.map(v => v.venta_id).filter(Boolean) as number[])
      const nuevosIds: number[] = []
      let lastReporte: ReporteConVentas | null = null

      for (const data of ventaItems) {
        const payload = buildVenta(data) as unknown as Record<string, unknown>
        const currentReporteId = esEdicion ? reporteId : savedReporteId
        let reporte: ReporteConVentas

        if (!currentReporteId) {
          // Primera venta → crear el reporte + venta en un solo request
          const fv = getValues()
          const crearPayload = {
            agente_id: fv.agente_id,
            tienda_id: fv.tienda_id,
            fecha: fv.fecha,
            caja_inicial: fv.caja_inicial || 0,
            yape: fv.yape || 0,
            bipay: fv.bipay || 0,
            transferencia: fv.transferencia || 0,
            retiro_bipay: fv.retiro_bipay || 0,
            recarga_bipay: fv.recarga_bipay || 0,
            pago_servicio: fv.pago_servicio || 0,
            pago_krece: fv.pago_krece || 0,
            pago_payjoy: fv.pago_payjoy || 0,
            tickets_tusamy: fv.tickets_tusamy || 0,
            efectivo_entregado: fv.efectivo_entregado || 0,
            destino_efectivo: fv.destino_efectivo,
            nombre_cubre: fv.nombre_cubre || '',
            observaciones: fv.observaciones || '',
            obs_dia: fv.obs_dia || '',
            usuario_id: usuario?.id ?? 0,
            ventas: [payload],
            salidas: [],
          }
          reporte = await api.post<ReporteConVentas>('/v1/reportes', crearPayload).then(r => r.data)
          setSavedReporteId(reporte.id)
          if (ACTIVO_LS_KEY) localStorage.setItem(ACTIVO_LS_KEY, String(reporte.id))
          inicializadoRef.current = true
        } else if (editIndex !== null && ventaItems.length === 1 && ventas[editIndex]?.venta_id) {
          // Editar venta con ID en BD → borrar + re-agregar
          await reportesApi.eliminarVenta(currentReporteId, ventas[editIndex].venta_id!)
          reporte = await reportesApi.agregarVenta(currentReporteId, payload)
        } else {
          // Agregar nueva venta a reporte existente
          reporte = await reportesApi.agregarVenta(currentReporteId, payload)
        }

        // Detectar el ID de la venta recién creada
        const nuevoId = reporte.ventas.find(v => !idsConocidos.has(v.id))?.id
        if (nuevoId) { nuevosIds.push(nuevoId); idsConocidos.add(nuevoId) }

        lastReporte = reporte
        syncVentasDesdeReporte(reporte)
      }

      // Crear tickets vinculados a sus ventas
      if ((esTienda || !!tiendaSeleccionada) && lastReporte) {
        crearTicketsVentas(ventaItems.map(d => buildVenta(d)), nuevosIds)
      }

      setBorradorMsg('✓ Venta guardada')
      setTimeout(() => setBorradorMsg(''), 3000)
    } catch (err) {
      console.error('[venta] Error al guardar:', err)
      setBorradorMsg('Error al guardar la venta')
      setTimeout(() => setBorradorMsg(''), 3000)
    } finally {
      setVentaSaving(false)
    }

    setVentaModalOpen(false)
    setEditIndex(null)
    setEditData(undefined)
  }

  // ── Eliminar venta con confirmación en BD ──────────────────────────────────
  const handleRemoveVenta = async (idx: number) => {
    const venta = ventas[idx]
    const currentReporteId = esEdicion ? reporteId : savedReporteId
    if (!currentReporteId || !venta.venta_id) {
      // No persistida todavía → solo quitar del form
      remove(idx)
      return
    }
    try {
      const reporte = await reportesApi.eliminarVenta(currentReporteId, venta.venta_id)
      syncVentasDesdeReporte(reporte)
    } catch (err) {
      console.error('[venta] Error al eliminar:', err)
      setBorradorMsg('Error al eliminar la venta')
      setTimeout(() => setBorradorMsg(''), 3000)
    }
  }

  // ── Cerrar caja (modo crear solamente) ─────────────────────────────────────
  const handleCerrarCaja = async () => {
    if (!savedReporteId) {
      setBorradorMsg('Agrega al menos una venta antes de cerrar la caja')
      setTimeout(() => setBorradorMsg(''), 3000)
      return
    }
    const ok = await confirmDialog({
      title: '¿Guardar y cerrar la caja?',
      description: 'Se cerrará el cuadre actual para empezar una nueva caja.',
      intent: 'gold',
      icon: Save,
      confirmLabel: 'Cerrar caja',
    })
    if (!ok) return
    setCerrandoCaja(true)
    const fv = getValues()
    try {
      await reportesApi.actualizarCabecera(savedReporteId, {
        caja_inicial: fv.caja_inicial || 0,
        yape: fv.yape || 0,
        bipay: fv.bipay || 0,
        transferencia: fv.transferencia || 0,
        retiro_bipay: fv.retiro_bipay || 0,
        recarga_bipay: fv.recarga_bipay || 0,
        pago_servicio: fv.pago_servicio || 0,
        pago_krece: fv.pago_krece || 0,
        pago_payjoy: fv.pago_payjoy || 0,
        tickets_tusamy: fv.tickets_tusamy || 0,
        efectivo_entregado: fv.efectivo_entregado || 0,
        nombre_cubre: fv.nombre_cubre || '',
        observaciones: fv.observaciones || '',
        obs_dia: fv.obs_dia || '',
        destino_efectivo: fv.destino_efectivo,
        salidas: salidaItems
          .filter(s => Number(s.monto) > 0)
          .map(s => ({
            tipo: s.tipo.toLowerCase() as 'adelanto' | 'gasto' | 'pasaje' | 'otro',
            monto: Number(s.monto),
            observacion: s.motivo,
          })),
        cerrar: true,
      })
      if (ACTIVO_LS_KEY) localStorage.removeItem(ACTIVO_LS_KEY)
      window.location.reload()
    } catch (err) {
      console.error('[caja] Error al cerrar:', err)
      setBorradorMsg('Error al cerrar la caja')
      setTimeout(() => setBorradorMsg(''), 3000)
    } finally {
      setCerrandoCaja(false)
    }
  }

  // ── Salidas de efectivo (estado local) ─────────────────────────────────────
  const [salidaItems, setSalidaItems] = useState<SalidaItem[]>([])

  useEffect(() => {
    // Aplica tanto a edit mode como a restore del reporte activo (localStorage)
    if (!activeReport || inicializadoRef.current) return

    const ventasIniciales: VentaFormData[] = activeReport.ventas.map((venta) => {
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

    const destino = ['TIENDA', 'ENTREGADO', 'EN_CAJA'].includes(activeReport.destino_efectivo)
      ? activeReport.destino_efectivo as FormData['destino_efectivo']
      : 'EN_CAJA'

    reset({
      agente_id: activeReport.agente_id,
      tienda_id: activeReport.tienda_id,
      fecha: activeReport.fecha,
      nombre_cubre: activeReport.nombre_cubre ?? '',
      caja_inicial: Number(activeReport.caja_inicial),
      yape: Number(activeReport.yape),
      bipay: Number(activeReport.bipay),
      transferencia: Number(activeReport.transferencia),
      retiro_bipay: Number(activeReport.retiro_bipay),
      recarga_bipay: Number(activeReport.recarga_bipay),
      pago_servicio: Number(activeReport.pago_servicio),
      pago_krece: Number(activeReport.pago_krece),
      pago_payjoy: Number(activeReport.pago_payjoy ?? 0),
      tickets_tusamy: Number(activeReport.tickets_tusamy),
      efectivo_entregado: Number(activeReport.efectivo_entregado),
      total_salidas: Number(activeReport.total_salidas),
      destino_efectivo: destino,
      observaciones: activeReport.observaciones ?? '',
      obs_dia: activeReport.obs_dia ?? '',
      ventas: ventasIniciales,
    })

    const totalSalidas = Number(activeReport.total_salidas)
    setSalidaItems(activeReport.salidas?.length
      ? activeReport.salidas.map(salida => ({
          id: crypto.randomUUID(),
          tipo: salida.tipo.charAt(0).toUpperCase() + salida.tipo.slice(1),
          monto: Number(salida.monto),
          motivo: salida.observacion ?? '',
        }))
      : totalSalidas > 0
        ? [{ id: crypto.randomUUID(), tipo: 'Otro', monto: totalSalidas, motivo: 'Total previo sin desglose' }]
        : [])
    inicializadoRef.current = true
  }, [activeReport, reset])

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
      return activeReporteId
        ? reportesApi.reprocesar(activeReporteId, payload)
        : reportesApi.crear(payload)
    },
    onSuccess: (reporte) => {
      if (cerrarCajaRef.current) {
        // Cerrar caja → limpiar localStorage y empezar nuevo
        cerrarCajaRef.current = false
        if (ACTIVO_LS_KEY) localStorage.removeItem(ACTIVO_LS_KEY)
        navigate('/reportes/nuevo')
      } else {
        // Guardar normal → quedarse en la misma página, persistir ID
        setSavedReporteId(reporte.id)
        if (ACTIVO_LS_KEY) localStorage.setItem(ACTIVO_LS_KEY, String(reporte.id))
        inicializadoRef.current = true // no re-inicializar el form con los datos del servidor
        setBorradorMsg('✓ Reporte guardado')
        setTimeout(() => setBorradorMsg(''), 3000)
      }
    },
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

  // Auto-persistir salidas en BD (debounced 1.5 s) — solo modo crear con reporte ya existente
  useEffect(() => {
    if (esEdicion || !savedReporteId) return
    const timer = setTimeout(async () => {
      const salidas = salidaItems
        .filter(s => Number(s.monto) > 0)
        .map(s => ({
          tipo: s.tipo.toLowerCase() as 'adelanto' | 'gasto' | 'pasaje' | 'otro',
          monto: Number(s.monto),
          observacion: s.motivo,
        }))
      try {
        await reportesApi.actualizarCabecera(savedReporteId, { salidas })
      } catch {
        // silencioso — no interrumpir al usuario por un error de salidas
      }
    }, 1500)
    return () => clearTimeout(timer)
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [salidaItems, savedReporteId, esEdicion])

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
      try { localStorage.setItem(LS_KEY, JSON.stringify(payload)) } catch { /* quota */ }
      if (!silencioso) { setBorradorMsg('Guardado en la nube ☁️'); setTimeout(() => setBorradorMsg(''), 2000) }
    } catch {
      try { localStorage.setItem(LS_KEY, JSON.stringify(payload)) } catch { /* quota */ }
      if (!silencioso) { setBorradorMsg('Sin conexión — guardado local'); setTimeout(() => setBorradorMsg(''), 2500) }
    }
    // Tickets solo para las ventas agregadas desde el último borrador guardado
    const todasVentas = getValues('ventas') ?? []
    const nuevas = todasVentas.slice(lastTicketedCount.current)
    lastTicketedCount.current = todasVentas.length
    if (nuevas.length > 0) crearTicketsVentas(nuevas)
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
    const todasVentas = data.ventas ?? []
    if (todasVentas.length > 0) crearTicketsVentas(todasVentas)
    guardar.mutate(data)
  }

  if (esEdicion && cargandoReporte) {
    return <div className="flex h-64 items-center justify-center text-sm text-kyro-muted">Cargando reporte...</div>
  }

  if (esEdicion && usuario?.rol !== 'admin') {
    return (
      <div className="kyro-card mx-auto max-w-xl p-6 text-center text-sm text-kyro-warning">
        El reprocesado completo requiere una cuenta administradora.
      </div>
    )
  }

  if (esEdicion && reporteEditar && reporteEditar.estado !== 'borrador' && reporteEditar.estado_edicion !== 'APROBADO') {
    return (
      <div className="kyro-card mx-auto max-w-xl p-6 text-center text-sm text-kyro-warning">
        El reprocesado completo requiere que la edición haya sido aprobada.
      </div>
    )
  }

  return (
    <div className="max-w-[1100px] mx-auto space-y-4">
      <PageHeader
        Icon={ClipboardList}
        title={esEdicion ? `Editar Cuadre #${reporteId}` : 'Registrar Cuadre Diario'}
        description="Cierre de caja y ventas del día."
        actions={
          <div className="flex items-center gap-2">
            {esTienda && <ChipStockBadge />}
            {esEdicion && esTienda && borradorDisponible && (
              <Button variant="glassWarning" type="button" className="gap-2"
                onClick={() => restaurarBorrador(borradorDisponible)}>
                <FolderDown size={15} /> Cargar Borrador
              </Button>
            )}
            {esEdicion && esTienda && (
              <Button variant="gold" type="button" className="gap-2" onClick={() => guardarBorrador(false)}>
                <Save size={15} /> Guardar Borrador
              </Button>
            )}
            {borradorMsg && <span className="text-xs text-kyro-muted">{borradorMsg}</span>}
            {crmMsg && <span className="text-xs text-kyro-info">{crmMsg}</span>}
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

            {/* Botón agregar registro */}
            {!tiendaSeleccionada ? (
              <div className="w-full mb-4 flex items-center justify-center gap-2 h-11 rounded-lg border border-dashed border-kyro-warning/40 bg-kyro-warning/5 text-xs font-medium text-kyro-warning">
                Selecciona una tienda arriba para agregar registros
              </div>
            ) : (
              <button
                type="button"
                onClick={() => setVentaModalOpen(true)}
                disabled={ventaSaving}
                className="w-full mb-4 flex items-center justify-center gap-2 h-11 rounded-lg font-semibold text-sm transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                style={{ background: 'var(--color-kyro-gold)', color: 'var(--color-kyro-gold-ink)', boxShadow: '0 0 18px color-mix(in srgb, var(--color-kyro-gold) 35%, transparent)' }}
              >
                <Plus size={18} /> {ventaSaving ? 'Guardando venta...' : 'Agregar Registro'}
              </button>
            )}

            <SectionPanel
              title="Ventas Postpago" accent={ACCENT.postpago} icon={<FileText size={15} />} number={1}
              count={postpagoRows.length} subtotal={totalPostpago}
            >
              {postpagoRows.length === 0
                ? <p className="text-[11px] text-kyro-muted py-2 text-center italic">Sin registros.</p>
                : postpagoRows.map(v => (
                    <VentaFila key={v.id} venta={ventas[v.idx]} index={v.idx} vendedores={vendedores}
                      onEdit={() => openEdit(v.idx)} onRemove={() => handleRemoveVenta(v.idx)}
                      onPrint={esTienda ? () => setTicketDesc('Venta Postpago') : undefined} />
                  ))}
            </SectionPanel>

            <SectionPanel
              title="Ventas Prepago / Chips" accent={ACCENT.prepago} icon={<Cpu size={15} />} number={2}
              count={prepagoRows.length} subtotal={totalPrepago}
            >
              {prepagoRows.length === 0
                ? <p className="text-[11px] text-kyro-muted py-2 text-center italic">Sin registros.</p>
                : prepagoRows.map(v => (
                    <VentaFila key={v.id} venta={ventas[v.idx]} index={v.idx} vendedores={vendedores}
                      onEdit={() => openEdit(v.idx)} onRemove={() => handleRemoveVenta(v.idx)}
                      onPrint={esTienda ? () => setTicketDesc('Venta Prepago / Chip') : undefined} />
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
                    <VentaFila key={v.id} venta={ventas[v.idx]} index={v.idx} vendedores={vendedores}
                      onEdit={() => openEdit(v.idx)} onRemove={() => handleRemoveVenta(v.idx)}
                      onPrint={esTienda ? () => setTicketDesc('Venta Equipo') : undefined} />
                  ))}
            </SectionPanel>

            <SectionPanel
              title="Otros Ingresos (Flujo)" accent={ACCENT.otros} icon={<Coins size={15} />} number={4}
              count={otrosRows.length} subtotal={totalOtrosFlujo}
            >
              {otrosRows.length === 0
                ? <p className="text-[11px] text-kyro-muted py-2 text-center italic">Sin registros.</p>
                : otrosRows.map(v => (
                    <VentaFila key={v.id} venta={ventas[v.idx]} index={v.idx} vendedores={vendedores}
                      onEdit={() => openEdit(v.idx)} onRemove={() => handleRemoveVenta(v.idx)} />
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
                    <VentaFila key={v.id} venta={ventas[v.idx]} index={v.idx} vendedores={vendedores}
                      onEdit={() => openEdit(v.idx)} onRemove={() => handleRemoveVenta(v.idx)} />
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

        {stockInsuficiente && (
          <p className="text-kyro-danger text-sm border border-kyro-danger/30 bg-kyro-danger/10 rounded-kyro px-3 py-2 font-semibold">
            STOCK INSUFICIENTE — estás reportando {chipsConsumidos} chips pero solo hay {chipsDisponibles} en stock.
          </p>
        )}

        {/* Modo edición: reprocesar completo */}
        {esEdicion && (
          <>
            {guardar.isError && (
              <p className="text-kyro-danger text-sm border border-kyro-danger/30 bg-kyro-danger/10 rounded-kyro px-3 py-2">
                {(guardar.error as { response?: { data?: { error?: string } } })?.response?.data?.error
                  ?? 'Error al guardar el reporte. Revisa los datos e intenta de nuevo.'}
              </p>
            )}
            <div className="flex gap-3">
              <Button type="submit" variant="gold" disabled={guardar.isPending || stockInsuficiente}
                className="flex-1 h-11 gap-2 text-base font-semibold">
                <UploadCloud size={18} />
                {stockInsuficiente ? 'STOCK INSUFICIENTE' : guardar.isPending ? 'Guardando reporte...' : 'Aplicar Reprocesado Completo'}
              </Button>
              <Button type="button" variant="outline" className="gap-2"
                onClick={() => navigate(`/reportes/${reporteId}`)} disabled={guardar.isPending}>
                <X size={16} /> Cancelar
              </Button>
            </div>
          </>
        )}

        {/* Modo crear: solo "Guardar y Cerrar Caja" */}
        {!esEdicion && (
          <div className="pb-8 space-y-2">
            <Button
              type="button"
              variant="outline"
              disabled={cerrandoCaja || stockInsuficiente || ventaSaving || !savedReporteId}
              onClick={handleCerrarCaja}
              className="w-full h-12 gap-2 text-base font-semibold border-2 border-kyro-indigo/50 text-kyro-indigo hover:bg-kyro-indigo/10"
            >
              <Receipt size={18} />
              {cerrandoCaja ? 'Cerrando caja...' : 'Guardar y Cerrar Caja · Empezar Nuevo'}
            </Button>
            {!savedReporteId && (
              <p className="text-[11px] text-kyro-muted text-center">
                Agrega al menos una venta para habilitar el cierre de caja.
              </p>
            )}
          </div>
        )}

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

      {/* ── Modal unificado: ticket + comprobante SUNAT ── */}
      {postVenta && (
        <PostVentaModal
          ticketId={postVenta.ticketId}
          ventaId={postVenta.ventaId}
          onClose={() => setPostVenta(null)}
        />
      )}

      {/* ── Panel flotante: tickets generados para imprimir ── */}
      {pendingPrintIds.length > 0 && (
        <div className="fixed bottom-4 right-4 z-50 kyro-card p-3 w-64 shadow-2xl border border-kyro-success/40">
          <div className="flex items-center justify-between mb-2">
            <p className="text-xs font-semibold text-kyro-success">
              <Printer size={12} className="inline mr-1" />
              {pendingPrintIds.length} ticket{pendingPrintIds.length > 1 ? 's' : ''} listo{pendingPrintIds.length > 1 ? 's' : ''}
            </p>
            <button onClick={() => setPendingPrintIds([])} className="text-kyro-muted hover:text-kyro-body text-xs">✕</button>
          </div>
          <div className="flex flex-col gap-1">
            {pendingPrintIds.map(id => (
              <button key={id}
                onClick={() => window.open(`/tickets/imprimir/${id}?print=1`, '_blank', 'width=420,height=680')}
                className="w-full text-left text-[11px] px-2 py-1.5 rounded border border-kyro-success/30 text-kyro-success hover:bg-kyro-success/10 transition-colors flex items-center gap-1.5">
                <Printer size={11} />
                Ticket #{String(id).padStart(6, '0')}
              </button>
            ))}
          </div>
        </div>
      )}

      <AgregarRegistroModal
        open={ventaModalOpen}
        onClose={() => { setVentaModalOpen(false); setEditIndex(null); setEditData(undefined) }}
        onConfirm={handleVentaConfirm}
        vendedores={vendedores}
        planes={planesData}
        inventarioItems={inventarioItems}
        initialData={editData}
        isEdit={editIndex !== null}
      />
    </div>
  )
}
