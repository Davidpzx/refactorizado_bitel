export interface FilaPlanilla {
  agente_id: number
  dni: string
  nombres: string
  tienda_base: string
  cargo: string
  estado: string
  fecha_retorno: string | null
  sueldo_base: number
  dias_trabajados: number
  sueldo_dias_lab: number
  comision_jefe: number
  comision_equipo: number
  comision_planes: number
  comision_online: number
  total_remuneracion: number
  retencion_uniforme: number
  faltas_permisos: number
  tardanzas: number
  faltante_efectivo: number
  adelanto_incluido: number
  total_descuentos: number
  total_pagar: number
  override_comisiones: boolean
  tiene_ajuste: boolean
  auto_equipo: number
  auto_planes: number
  auto_online: number
  notas: string | null
}

export interface TotalesPlanilla {
  sueldo_base: number
  sueldo_dias_lab: number
  total_remuneracion: number
  total_descuentos: number
  total_pagar: number
  com_planes: number
  com_equipo: number
  com_online: number
  adelantos: number
}

export interface PlanillaResponse {
  mes: string
  agentes: FilaPlanilla[]
  totales: TotalesPlanilla
}

export interface AjustePlanillaPayload {
  agente_id: number
  mes: string
  campo: string
  valor: number | string
  set_override?: boolean
}
