import { api } from './api'

export interface BipayTiendaEstado {
  codigo: string
  nombre: string
  cooldown_segs: number
  segs_desde_actualizacion: number | null
  saldo_bipay_actual: number | null
  saldo_anypay_actual: number | null
}

export interface BipayEstado {
  ok: boolean
  msg?: string
  bipay_live: number
  anypay_live: number
  bipay_actual: number | null
  anypay_actual: number | null
  bipay_cierre: number | null
  anypay_cierre: number | null
  alerta: boolean
  umbral: number
  cooldown_segs: number
  cerrado: boolean
  tiendas_estado: BipayTiendaEstado[]
}

export const bipayCajeroApi = {
  estado: () => api.get<BipayEstado>('/v1/bipay/cajero/estado').then((r) => r.data),
  tramo: (saldo_bipay?: number, saldo_anypay?: number) =>
    api.post('/v1/bipay/cajero/actualizar', { saldo_bipay, saldo_anypay }).then((r) => r.data),
  cierre: (saldo_bipay_cierre?: number, saldo_anypay_cierre?: number) =>
    api.post('/v1/bipay/cajero/cierre', { saldo_bipay_cierre, saldo_anypay_cierre }).then((r) => r.data),
}
