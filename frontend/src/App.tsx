import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      staleTime: 30_000,
    },
  },
})

function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <div className="min-h-screen bg-gray-50">
        <header className="bg-white border-b border-gray-200 px-6 py-4">
          <h1 className="text-xl font-semibold text-gray-800">
            SIS-KYRO-REFACTOR
          </h1>
        </header>
        <main className="p-6">
          <p className="text-gray-600">
            Sistema ERP — Backend conectado a BD{' '}
            <code className="bg-gray-100 px-1 rounded text-sm">migracion</code>
          </p>
        </main>
      </div>
    </QueryClientProvider>
  )
}

export default App
