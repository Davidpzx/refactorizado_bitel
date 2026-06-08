export interface Reporte {
  id: number
  agente_id: number
  tienda_id: string
  usuario_id: number
  fecha: string
  total_dia: string
  total_calculado: string
  yape: string
  bipay: string
  recarga_bipay: string
  pago_servicio: string
  pago_krece: string
  tickets_tusamy: string
  retiro_bipay: string
  transferencia: string
  caja_inicial: string
  efectivo_entregado: string
  total_salidas: string
  total_restantes: string
  efectivo_esperado: string
  diferencia: string
  estado: 'borrador' | 'enviado' | 'editado' | 'aprobado'
  requiere_aprobacion: boolean
  aprobado_por: number | null
  fecha_aprobacion: string | null
  nombre_cubre: string | null
  observaciones: string | null
  obs_dia: string | null
  created_at: string
  updated_at: string
  destino_efectivo: string
  estado_edicion: 'CERRADO' | 'SOLICITADO' | 'APROBADO'
  motivo_edicion: string | null
  ventas_count?: number
}

export interface ReporteFilters {
  tienda?: string
  estado?: string
  fecha_desde?: string
  fecha_hasta?: string
  page?: number
  per_page?: number
}

export interface VentaItem {
  tipo_venta: 'EQUIPO' | 'ACCESORIO' | 'POSTPAGO' | 'PREPAGO' | 'OTROS_FLUJO'
  subtipo?: string
  monto_total: number
  efectivo_inicial: number
  cross_selling: boolean
  tienda_destino?: string
  es_remate: boolean
  es_extranjero: boolean
  cliente_dni?: string
  // Equipo / Accesorio
  inventario_tienda_id?: number
  producto_nombre?: string
  imei_serial?: string
  tipo_pago?: 'CONTADO' | 'CUOTAS'
  financiera?: string
  precio_venta?: number
  costo_snap?: number
  por_cobrar_financiera?: number
  // Postpago / Prepago
  plan_nombre?: string
  tipo_alta?: string
  cantidad?: number
  cobrado_unitario?: number
  comision_unitaria?: number
}

export interface CreateReportePayload {
  agente_id: number
  tienda_id: string
  usuario_id: number
  fecha: string
  caja_inicial: number
  yape: number
  bipay: number
  transferencia: number
  recarga_bipay: number
  pago_servicio: number
  pago_krece: number
  tickets_tusamy: number
  retiro_bipay: number
  efectivo_entregado: number
  total_salidas: number
  nombre_cubre?: string
  observaciones?: string
  obs_dia?: string
  destino_efectivo?: string
  ventas: VentaItem[]
}

export interface ComisionPlan {
  id: number
  tipo_servicio: 'PREPAGO' | 'POSTPAGO'
  nombre_plan: string
  tipo_alta: string
  fee_monto: string
  comision_dni_n: string
}

// ─── Tipos para el endpoint show() — reporte con relaciones cargadas ─────────

export interface VentaEquipoDetalle {
  id: number
  venta_id: number
  inventario_tienda_id: number
  producto_nombre_snap: string
  imei_serial_snap: string | null
  tipo_item: 'EQUIPO' | 'ACCESORIO'
  tipo_pago: 'CONTADO' | 'CUOTAS'
  financiera: string | null
  precio_venta: string
  costo_snap: string
  ganancia_snap: string | null
  por_cobrar_financiera: string
}

export interface VentaLineaDetalle {
  id: number
  venta_id: number
  plan_nombre_snap: string
  tipo_alta: string
  cantidad: number
  cobrado_unitario: string
  comision_unitaria: string
  es_esim: boolean
}

export interface ClienteResumen {
  id: number
  dni_ruc: string
  nombre: string
  tipo_documento: string
}

export interface VentaConDetalle {
  id: number
  reporte_id: number
  vendedor_id: number
  cliente_id: number | null
  tipo_venta: 'EQUIPO' | 'ACCESORIO' | 'POSTPAGO' | 'PREPAGO' | 'OTROS_FLUJO'
  subtipo: string | null
  monto_total: string
  efectivo_inicial: string
  cross_selling: boolean
  tienda_destino: string | null
  es_remate: boolean
  es_extranjero: boolean
  comision_generada: string
  comision_estado: 'ACTIVA' | 'ANULADA'
  equipo: VentaEquipoDetalle | null
  linea: VentaLineaDetalle | null
  cliente: ClienteResumen | null
}

export interface ReporteConVentas extends Reporte {
  ventas: VentaConDetalle[]
}
