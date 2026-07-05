import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { crmApi } from '../services/crm.api'
import type { CrmDashboardFilters, CrmTemperaturaFiltros, InteraccionCrm, LeadFormData } from '../types/crm'

export function useLeads(params?: Record<string, string | number>) {
  return useQuery({
    queryKey: ['leads', params],
    queryFn: () => crmApi.leads.list(params),
  })
}

export function useLead(id: number) {
  return useQuery({
    queryKey: ['leads', id],
    queryFn: () => crmApi.leads.get(id),
    enabled: !!id,
  })
}

export function usePipeline(params?: Record<string, string | number>) {
  return useQuery({
    queryKey: ['crm-pipeline', params],
    queryFn: () => crmApi.pipeline(params),
    staleTime: 60_000,
  })
}

export function useCrearLead() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: LeadFormData) => crmApi.leads.create(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['leads'] })
      qc.invalidateQueries({ queryKey: ['crm-pipeline'] })
    },
  })
}

export function useActualizarLead() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: Partial<LeadFormData> }) =>
      crmApi.leads.update(id, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['leads'] })
      qc.invalidateQueries({ queryKey: ['crm-pipeline'] })
    },
  })
}

export function useEliminarLead() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => crmApi.leads.destroy(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['leads'] })
      qc.invalidateQueries({ queryKey: ['crm-pipeline'] })
    },
  })
}

export function useInteracciones(leadId: number) {
  return useQuery({
    queryKey: ['interacciones', leadId],
    queryFn: () => crmApi.interacciones.list(leadId),
    enabled: !!leadId,
  })
}

export function useAgregarInteraccion() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ leadId, ...data }: { leadId: number; agente_id: number; tipo: InteraccionCrm['tipo']; detalle?: string }) =>
      crmApi.interacciones.create(leadId, data),
    onSuccess: (_, vars) => qc.invalidateQueries({ queryKey: ['interacciones', vars.leadId] }),
  })
}

export function useCrmDashboard(params?: CrmDashboardFilters) {
  return useQuery({
    queryKey: ['crm-dashboard', params],
    queryFn: () => crmApi.dashboard(params),
    staleTime: 60_000,
  })
}

export function useCrmTemperatura(params?: CrmTemperaturaFiltros) {
  return useQuery({
    queryKey: ['crm-temperatura', params],
    queryFn: () => crmApi.temperatura.list(params),
    staleTime: 60_000,
  })
}
