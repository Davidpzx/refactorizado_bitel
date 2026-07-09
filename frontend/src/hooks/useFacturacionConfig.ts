import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { facturacionConfigApi, type FacturacionConfigPayload } from '../services/facturacionConfig.api'

const KEY = ['facturacion-config']

export function useFacturacionConfigs() {
  return useQuery({ queryKey: KEY, queryFn: facturacionConfigApi.index })
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
