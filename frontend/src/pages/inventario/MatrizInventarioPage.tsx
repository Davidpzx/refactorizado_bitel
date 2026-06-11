import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../services/api'
import { PageHeader } from '../../components/PageHeader'
import { Button } from '../../components/ui/button'
import { Badge } from '../../components/ui/badge'

interface TiendaInfo {
  codigo: string
  nombre: string
}

interface FilaMatriz {
  nombre: string
  total: number
  [tiendaCodigo: string]: string | number
}

interface MatrizResponse {
  tiendas: TiendaInfo[]
  equipos: FilaMatriz[]
  accesorios: FilaMatriz[]
  chips: FilaMatriz[]
}

type TabKey = 'equipos' | 'accesorios' | 'chips'

const TABS: { key: TabKey; label: string }[] = [
  { key: 'equipos',    label: 'Equipos' },
  { key: 'accesorios', label: 'Accesorios' },
  { key: 'chips',      label: 'Chips' },
]

export function MatrizInventarioPage() {
  const [tab, setTab] = useState<TabKey>('equipos')

  const { data, isLoading } = useQuery<MatrizResponse>({
    queryKey: ['inventario-matriz'],
    queryFn: () => api.get<MatrizResponse>('/v1/inventario/matriz').then((r) => r.data),
  })

  const tiendas  = data?.tiendas ?? []
  const filas    = data?.[tab] ?? []

  const handleExportar = (tipo: 'EQUIPO' | 'ACCESORIO') => {
    const token = localStorage.getItem('auth_token')
    const base  = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api'
    const url   = `${base}/v1/inventario/exportar?tipo=${tipo}${token ? `&token=${token}` : ''}`
    window.open(url, '_blank')
  }

  return (
    <div>
      <PageHeader
        title="Matriz de Inventario"
        description="Vista cruzada de stock por tienda y producto."
        actions={
          <div className="flex gap-2">
            <Button variant="outline" size="sm" onClick={() => handleExportar('EQUIPO')}>
              Exportar Equipos CSV
            </Button>
            <Button variant="outline" size="sm" onClick={() => handleExportar('ACCESORIO')}>
              Exportar Accesorios CSV
            </Button>
          </div>
        }
      />

      <div className="flex gap-1 mb-4 border-b border-gray-200">
        {TABS.map(({ key, label }) => (
          <button
            key={key}
            onClick={() => setTab(key)}
            className={[
              'px-4 py-2 text-sm font-medium transition-colors',
              tab === key
                ? 'border-b-2 border-blue-600 text-blue-700'
                : 'text-gray-500 hover:text-gray-700',
            ].join(' ')}
          >
            {label}
          </button>
        ))}
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center h-48 text-sm text-gray-400">
          Cargando...
        </div>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="min-w-full text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left font-semibold text-gray-700 whitespace-nowrap">
                  {tab === 'chips' ? 'Código Origen' : 'Producto'}
                </th>
                {tiendas.map((t) => (
                  <th key={t.codigo} className="px-3 py-3 text-center font-semibold text-gray-700 whitespace-nowrap">
                    {t.codigo}
                  </th>
                ))}
                <th className="px-3 py-3 text-center font-semibold text-gray-700">Total</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {filas.length === 0 ? (
                <tr>
                  <td colSpan={tiendas.length + 2} className="px-4 py-8 text-center text-gray-400">
                    Sin datos
                  </td>
                </tr>
              ) : (
                filas.map((fila, idx) => {
                  const tieneStock = (fila.total as number) > 0
                  return (
                    <tr
                      key={idx}
                      className={tieneStock ? 'bg-green-50/40 hover:bg-green-50' : 'hover:bg-gray-50'}
                    >
                      <td className="px-4 py-2.5 font-medium text-gray-900 whitespace-nowrap">
                        {fila.nombre}
                      </td>
                      {tiendas.map((t) => {
                        const val = fila[t.codigo] as number | undefined
                        return (
                          <td key={t.codigo} className="px-3 py-2.5 text-center text-gray-700">
                            {val && val > 0 ? (
                              <span className="font-semibold text-green-700">{val}</span>
                            ) : (
                              <span className="text-gray-300">—</span>
                            )}
                          </td>
                        )
                      })}
                      <td className="px-3 py-2.5 text-center font-bold text-gray-900">
                        {(fila.total as number) > 0 ? (
                          <Badge variant="default">{fila.total}</Badge>
                        ) : (
                          <span className="text-gray-300">—</span>
                        )}
                      </td>
                    </tr>
                  )
                })
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
