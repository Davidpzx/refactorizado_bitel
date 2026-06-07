import { api } from './api'

interface HealthResponse {
  status: string
  app: string
}

export async function checkHealth(): Promise<HealthResponse> {
  const { data } = await api.get<HealthResponse>('/health')
  return data
}
