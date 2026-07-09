export interface InventarioItem {
  id: number
  tienda_id: string
  producto_nombre: string
  tipo: 'EQUIPO' | 'ACCESORIO' | 'CHIP'
  imei_serial: string | null
  precio_costo: string
  precio_minimo: string
  precio_normal: string
  cantidad: number
  estado: 'DISPONIBLE' | 'VENDIDO' | 'TRASLADO'
  registrado_por: number | null
  fecha_registro: string
  fecha_venta: string | null
  vendido_por_id: number | null
  reporte_venta_id: number | null
  comision_especial: string | null
}

export interface InventarioPayload {
  tienda_id: string
  producto_nombre: string
  tipo: 'EQUIPO' | 'ACCESORIO' | 'CHIP'
  imei_serial?: string | null
  imei_seriales?: string[]
  // Precios opcionales: el ingreso de tienda va sin precio y gerencia lo fija
  // después en Precios pendientes (flujo de 2 pasos del legacy).
  precio_costo?: number
  precio_minimo?: number
  precio_normal?: number
  cantidad: number
  estado: 'DISPONIBLE' | 'VENDIDO' | 'TRASLADO'
  comision_especial?: number
  dni_autoriza?: string
}

export interface InventarioFilters {
  q?: string
  tienda?: string
  tipo?: string
  estado?: string
  page?: number
  per_page?: number
}
