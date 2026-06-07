import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { planillaApi } from '../services/planilla.api'
import type { AjustePlanillaPayload } from '../types/planilla'

export function usePlanilla(mes: string) {
  return useQuery({
    queryKey: ['planilla', mes],
    queryFn: () => planillaApi.calcular(mes),
    enabled: /^\d{4}-\d{2}$/.test(mes),
    staleTime: 30_000,
  })
}

export function useGuardarAjustePlanilla() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (payload: AjustePlanillaPayload) => planillaApi.guardarAjuste(payload),
    onSuccess: (_, vars) => qc.invalidateQueries({ queryKey: ['planilla', vars.mes] }),
  })
}

export function useResetarComisionesPlanilla() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ agente_id, mes }: { agente_id: number; mes: string }) =>
      planillaApi.resetarComisiones(agente_id, mes),
    onSuccess: (_, vars) => qc.invalidateQueries({ queryKey: ['planilla', vars.mes] }),
  })
}
