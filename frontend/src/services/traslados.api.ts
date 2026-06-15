import { api } from './api'
import type {
  Traslado,
  TrasladoFilters,
  CrearTrasladoPayload,
  ConfirmarTrasladoPayload,
  GestionarTrasladoPayload,
  TrasladoChip,
  TrasladoChipFilters,
  CrearTrasladoChipPayload,
  ChipStock,
} from '../types/traslados'
import type { PaginatedResponse } from '../types/pagination'

export const trasladosApi = {
  listar: (params: TrasladoFilters) =>
    api.get<PaginatedResponse<Traslado>>('/v1/traslados', { params }).then((r) => r.data),

  crear: (data: CrearTrasladoPayload) =>
    api.post<Traslado>('/v1/traslados', data).then((r) => r.data),

  confirmar: (id: number, data: ConfirmarTrasladoPayload) =>
    api.post<Traslado>(`/v1/traslados/${id}/confirmar`, data).then((r) => r.data),

  confirmarLote: (codigoLote: string, data: ConfirmarTrasladoPayload) =>
    api.post(`/v1/traslados/lote/${encodeURIComponent(codigoLote)}/confirmar`, data).then((r) => r.data),

  gestionar: (id: number, data: GestionarTrasladoPayload) =>
    api.post<Traslado>(`/v1/traslados/${id}/gestionar`, data).then((r) => r.data),

  pendientesAprobacion: () =>
    api.get<Traslado[]>('/v1/traslados/pendientes-aprobacion').then((r) => r.data),
}

export const trasladosChipsApi = {
  listar: (params: TrasladoChipFilters) =>
    api.get<PaginatedResponse<TrasladoChip>>('/v1/traslados-chips', { params }).then((r) => r.data),

  crear: (data: CrearTrasladoChipPayload) =>
    api.post<TrasladoChip>('/v1/traslados-chips', data).then((r) => r.data),

  confirmar: (id: number, data: ConfirmarTrasladoPayload) =>
    api.post<TrasladoChip>(`/v1/traslados-chips/${id}/confirmar`, data).then((r) => r.data),

  gestionar: (id: number, data: GestionarTrasladoPayload) =>
    api.post<TrasladoChip>(`/v1/traslados-chips/${id}/gestionar`, data).then((r) => r.data),

  stockChips: () =>
    // El endpoint envuelve el arreglo en { data: [...] }; normalizar a arreglo.
    api.get<{ data: ChipStock[] } | ChipStock[]>('/v1/inventario-chips')
      .then((r) => (Array.isArray(r.data) ? r.data : r.data?.data ?? [])),
}
