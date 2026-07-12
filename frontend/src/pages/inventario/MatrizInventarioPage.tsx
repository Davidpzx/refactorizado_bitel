import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../../services/api'
import { PageHeader } from '../../components/PageHeader'
import { Button } from '../../components/ui/button'
import { Badge } from '../../components/ui/badge'
import { SegmentedToggle } from '../../components/ui/SegmentedToggle'
import { ArrowLeft, GridFour as LayoutGrid } from '@phosphor-icons/react'

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

const TABS = [
  { value: 'equipos', label: 'Equipos', tone: 'info' as const },
  { value: 'accesorios', label: 'Accesorios', tone: 'indigo' as const },
  { value: 'chips', label: 'Chips', tone: 'success' as const },
]

export function MatrizInventarioPage() {
  const [tab, setTab] = useState<TabKey>('equipos')

  const { data, isLoading } = useQuery<MatrizResponse>({
    queryKey: ['inventario-matriz'],
    queryFn: () => api.get<MatrizResponse>('/v1/inventario/matriz').then((r) => r.data),
  })

  const tiendas  = data?.tiendas ?? []
  const filas    = data?.[tab] ?? []

  const handleExportar = async (tipo: 'EQUIPO' | 'ACCESORIO') => {
    const res = await api.get(`/v1/inventario/exportar?tipo=${tipo}`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data as Blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `inventario_${tipo.toLowerCase()}_${new Date().toISOString().slice(0, 10)}.xlsx`
    a.click()
    URL.revokeObjectURL(url)
  }

  return (
    <div className="space-y-6">
      <PageHeader
        Icon={LayoutGrid}
        title="Matriz de Inventario"
        description="Vista cruzada de stock por tienda y producto."
        actions={
          <div className="flex flex-wrap gap-2">
            <Link
              to="/inventario"
              className="inline-flex h-9 items-center gap-1.5 rounded-[10px] border border-kyro-border bg-kyro-elevated px-3 text-xs font-semibold text-kyro-body shadow-sm transition-all hover:border-kyro-indigo/50 hover:text-kyro-indigo"
            >
              <ArrowLeft size={14} /> Volver
            </Link>
            <Button variant="outline" size="sm" className="text-kyro-indigo" onClick={() => handleExportar('EQUIPO')}>
              Exportar Equipos Excel
            </Button>
            <Button variant="outline" size="sm" className="text-kyro-indigo" onClick={() => handleExportar('ACCESORIO')}>
              Exportar Accesorios Excel
            </Button>
          </div>
        }
      />

      <SegmentedToggle
        ariaLabel="Cambiar vista de matriz de inventario"
        options={TABS}
        value={tab}
        onChange={(value) => setTab(value as TabKey)}
      />

      {isLoading ? (
        <div className="kyro-card flex h-48 items-center justify-center text-sm text-kyro-muted">
          <span className="inline-flex items-center gap-2">
            <span className="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
            Cargando...
          </span>
        </div>
      ) : (
        <div className="kyro-card relative overflow-hidden rounded-[18px]">
          <div className="overflow-x-auto">
          <table className="min-w-full border-separate border-spacing-0 text-sm">
            <thead>
              <tr>
                <th className="kyro-table-head sticky left-0 top-0 z-20 whitespace-nowrap px-3 py-2.5 text-left">
                  {tab === 'chips' ? 'Código Origen' : 'Producto'}
                </th>
                {tiendas.map((t) => (
                  <th key={t.codigo} className="kyro-table-head sticky top-0 z-10 whitespace-nowrap px-3 py-2.5 text-center">
                    {t.codigo}
                  </th>
                ))}
                <th className="kyro-table-head sticky top-0 z-10 bg-kyro-indigo/10 px-3 py-2.5 text-center font-bold text-kyro-indigo">Total</th>
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
                      <td className="sticky left-0 z-[1] whitespace-nowrap bg-kyro-panel px-3 py-2.5 font-medium text-kyro-text transition-colors group-hover:bg-inherit">
                        {fila.nombre}
                      </td>
                      {tiendas.map((t) => {
                        const val = fila[t.codigo] as number | undefined
                        return (
                          <td key={t.codigo} className="px-3 py-2.5 text-center tabular-nums text-kyro-body">
                            {val && val > 0 ? (
                              <span className="font-mono font-semibold text-kyro-success">{val}</span>
                            ) : (
                              <span className="text-gray-300">—</span>
                            )}
                          </td>
                        )
                      })}
                      <td className="bg-kyro-indigo/5 px-3 py-2.5 text-center font-bold tabular-nums text-kyro-indigo">
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
