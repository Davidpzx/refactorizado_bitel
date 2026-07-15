import { Component, type ErrorInfo, type ReactNode } from 'react'

interface ErrorBoundaryProps {
  children: ReactNode
  fallback?: ReactNode
}

interface ErrorBoundaryState {
  hasError: boolean
}

export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  state: ErrorBoundaryState = { hasError: false }

  static getDerivedStateFromError(): ErrorBoundaryState {
    return { hasError: true }
  }

  componentDidCatch(error: unknown, errorInfo: ErrorInfo) {
    console.error('ErrorBoundary caught an error', error, errorInfo)
  }

  render() {
    if (this.state.hasError) {
      return this.props.fallback ?? (
        <div className="flex min-h-screen items-center justify-center bg-kyro-base px-4 text-center text-kyro-body">
          <div className="max-w-md rounded-[18px] border border-kyro-border bg-kyro-card p-6 shadow-kyro-card">
            <h1 className="text-base font-semibold text-kyro-text">No se pudo cargar esta pantalla.</h1>
            <p className="mt-2 text-sm text-kyro-muted">Actualiza la pagina o intenta nuevamente en unos minutos.</p>
          </div>
        </div>
      )
    }

    return this.props.children
  }
}
