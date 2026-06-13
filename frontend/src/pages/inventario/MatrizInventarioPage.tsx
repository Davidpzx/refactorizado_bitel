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
    <div className="space-y-6">
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

      <div className="kyro-card flex w-fit max-w-full gap-1 overflow-x-auto p-1.5">
        {TABS.map(({ key, label }) => (
          <Button
            key={key}
            variant="ghost"
            size="sm"
            onClick={() => setTab(key)}
            className={[
              'px-4 text-xs font-semibold',
              tab === key
                ? 'bg-kyro-gold text-kyro-gold-ink hover:bg-kyro-gold'
                : 'text-kyro-muted hover:bg-kyro-elevated hover:text-kyro-text',
            ].join(' ')}
          >
            {label}
          </Button>
        ))}
      </div>

      {isLoading ? (
        <div className="kyro-card flex h-48 items-center justify-center text-sm text-kyro-muted">
          <span className="inline-flex items-center gap-2">
            <span className="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
            Cargando...
          </span>
        </div>
      ) : (
        <div className="kyro-card relative overflow-hidden">
          <div className="overflow-x-auto">
          <table className="min-w-full border-separate border-spacing-0 text-sm">
            <thead>
              <tr>
                <th className="kyro-table-head sticky left-0 top-0 z-10 whitespace-nowrap px-4 py-3 text-left">
                  {tab === 'chips' ? 'Código Origen' : 'Producto'}
                </th>
                {tiendas.map((t) => (
                  <th key={t.codigo} className="kyro-table-head whitespace-nowrap px-3 py-3 text-center">
                    {t.codigo}
                  </th>
                ))}
                <th className="kyro-table-head bg-kyro-indigo/10 px-3 py-3 text-center font-bold text-kyro-gold">Total</th>
              </tr>
            </thead>
            <tbody>
              {filas.length === 0 ? (
                <tr>
                  <td colSpan={tiendas.length + 2} className="px-4 py-14 text-center text-kyro-muted">
                    Sin datos
                  </td>
                </tr>
              ) : (
                filas.map((fila, idx) => {
                  const tieneStock = (fila.total as number) > 0
                  return (
                    <tr
                      key={idx}
                      className={tieneStock
                        ? 'group bg-kyro-success/5 transition-colors hover:bg-kyro-success/10'
                        : 'group transition-colors hover:bg-kyro-elevated/50'}
                    >
                      <td className="sticky left-0 z-[1] whitespace-nowrap border-b border-kyro-border bg-kyro-panel px-4 py-2.5 font-medium text-kyro-text transition-colors group-hover:bg-inherit">
                        {fila.nombre}
                      </td>
                      {tiendas.map((t) => {
                        const val = fila[t.codigo] as number | undefined
                        return (
                          <td key={t.codigo} className="border-b border-kyro-border px-3 py-2.5 text-center text-kyro-body">
                            {val && val > 0 ? (
                              <span className="font-mono font-semibold text-kyro-success">{val}</span>
                            ) : (
                              <span className="text-gray-300">—</span>
                            )}
                          </td>
                        )
                      })}
                      <td className="border-b border-kyro-border bg-kyro-indigo/5 px-3 py-2.5 text-center font-bold text-kyro-text">
                        {(fila.total as number) > 0 ? (
                          <Badge variant="default" className="min-w-9 justify-center font-mono">{fila.total}</Badge>
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
        </div>
      )}
    </div>
  )
}
