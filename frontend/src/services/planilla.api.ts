import { api } from './api'
import type { AjustePlanillaPayload, PlanillaResponse } from '../types/planilla'

export const planillaApi = {
  calcular: (mes: string): Promise<PlanillaResponse> =>
    api.get(`/v1/planilla/${mes}`).then(r => r.data),

  guardarAjuste: (payload: AjustePlanillaPayload): Promise<{ ok: boolean }> =>
    api.post('/v1/planilla/ajuste', payload).then(r => r.data),

  resetarComisiones: (agente_id: number, mes: string): Promise<{ ok: boolean }> =>
    api.post('/v1/planilla/ajuste/reset-comisiones', { agente_id, mes }).then(r => r.data),
}
