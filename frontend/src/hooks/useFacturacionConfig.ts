import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { facturacionConfigApi, type FacturacionConfigPayload } from '../services/facturacionConfig.api'

const KEY = ['facturacion-config']

export function useFacturacionConfigs() {
  // Config fiscal (company_id/branch_id/series): catálogo administrativo que
  // solo cambia al guardar (mutation ya invalida la key) — no necesita 30 s (OPT-14).
  return useQuery({ queryKey: KEY, queryFn: facturacionConfigApi.index, staleTime: 10 * 60_000 })
}

/** id = null crea una config nueva (global o de tienda); id numérico actualiza la existente. */
export function useGuardarFacturacionConfig() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: number | null; payload: FacturacionConfigPayload }) =>
      id ? facturacionConfigApi.update(id, payload) : facturacionConfigApi.store(payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  })
}

export function useConfigurarSunat() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (formData: FormData) => facturacionConfigApi.configureSunat(formData),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  })
}

export function useSyncLogoFacturacion() {
  return useMutation({
    mutationFn: ({ claveSol, tiendaId }: { claveSol: string; tiendaId?: string | null }) =>
      facturacionConfigApi.syncLogoFacturacion(claveSol, tiendaId),
  })
}
