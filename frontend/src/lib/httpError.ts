interface ApiErrorData {
  error?: string
  message?: string
  errors?: Record<string, string[]>
}

interface ApiErrorLike {
  response?: {
    status?: number
    data?: ApiErrorData
  }
}

function asApiError(error: unknown): ApiErrorLike {
  return typeof error === 'object' && error !== null ? error as ApiErrorLike : {}
}

export function apiErrorData(error: unknown): ApiErrorData {
  return asApiError(error).response?.data ?? {}
}

export function apiErrorStatus(error: unknown): number | undefined {
  return asApiError(error).response?.status
}
