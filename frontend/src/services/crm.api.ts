import { api } from './api'
import type { CrmDashboardData, CrmDashboardFilters, InteraccionCrm, Lead, LeadFormData, PipelineResponse } from '../types/crm'

export const crmApi = {
  leads: {
    list: (params?: Record<string, string | number>): Promise<{ data: Lead[]; total: number }> =>
      api.get('/v1/leads', { params }).then(r => r.data),

    get: (id: number): Promise<Lead> =>
      api.get(`/v1/leads/${id}`).then(r => r.data),

    create: (data: LeadFormData): Promise<Lead> =>
      api.post('/v1/leads', data).then(r => r.data),

    update: (id: number, data: Partial<LeadFormData>): Promise<Lead> =>
      api.put(`/v1/leads/${id}`, data).then(r => r.data),

    destroy: (id: number): Promise<void> =>
      api.delete(`/v1/leads/${id}`).then(r => r.data),
  },

  interacciones: {
    list: (leadId: number): Promise<InteraccionCrm[]> =>
      api.get(`/v1/leads/${leadId}/interacciones`).then(r => r.data),

    create: (leadId: number, data: { agente_id: number; tipo: InteraccionCrm['tipo']; detalle?: string }): Promise<InteraccionCrm> =>
      api.post(`/v1/leads/${leadId}/interacciones`, data).then(r => r.data),
  },

  pipeline: (params?: Record<string, string | number>): Promise<PipelineResponse> =>
    api.get('/v1/crm/pipeline', { params }).then(r => r.data),

  dashboard: (params?: CrmDashboardFilters): Promise<CrmDashboardData> =>
    api.get('/v1/crm/dashboard', { params }).then(r => r.data),
}
