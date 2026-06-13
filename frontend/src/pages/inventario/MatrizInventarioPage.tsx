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

      <div className="flex w-fit max-w-full gap-1 overflow-x-auto rounded-xl border border-gray-200/80 bg-white/70 p-1.5 shadow-sm backdrop-blur-xl dark:border-white/[0.08] dark:bg-zinc-900/65">
        {TABS.map(({ key, label }) => (
          <button
            key={key}
            onClick={() => setTab(key)}
            className={[
              'rounded-lg px-4 py-2 text-xs font-semibold transition-all',
              tab === key
                ? 'bg-indigo-600 text-white shadow-[0_6px_16px_-8px_rgba(79,70,229,0.9)] dark:bg-indigo-500'
                : 'text-gray-500 hover:bg-white/80 hover:text-gray-800 dark:text-zinc-400 dark:hover:bg-white/[0.06] dark:hover:text-zinc-100',
            ].join(' ')}
          >
            {label}
          </button>
        ))}
      </div>

      {isLoading ? (
        <div className="flex h-48 items-center justify-center rounded-2xl border border-gray-200/80 bg-white/70 text-sm text-gray-400 shadow-sm backdrop-blur-xl dark:border-white/[0.08] dark:bg-zinc-900/60 dark:text-zinc-500">
          <span className="inline-flex items-center gap-2">
            <span className="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
            Cargando...
          </span>
        </div>
      ) : (
        <div className="relative overflow-hidden rounded-xl border border-gray-200/80 bg-white/80 shadow-[0_18px_45px_-30px_rgba(15,23,42,0.55)] backdrop-blur-xl dark:border-white/[0.08] dark:bg-zinc-900/65 dark:shadow-[0_22px_50px_-30px_rgba(0,0,0,0.95)]">
          <div aria-hidden className="pointer-events-none absolute inset-x-0 top-0 z-20 h-px" style={{ background: 'linear-gradient(90deg, rgba(99,102,241,0.8), rgba(255,194,0,0.55) 45%, transparent 82%)' }} />
          <div className="overflow-x-auto">
          <table className="min-w-full border-separate border-spacing-0 text-sm">
            <thead>
              <tr>
                <th className="sticky left-0 top-0 z-10 whitespace-nowrap border-b border-gray-200 bg-gray-50/95 px-4 py-3 text-left text-[0.68rem] font-semibold uppercase tracking-[0.08em] text-gray-500 backdrop-blur dark:border-white/[0.07] dark:bg-zinc-800/95 dark:text-zinc-400">
                  {tab === 'chips' ? 'Código Origen' : 'Producto'}
                </th>
                {tiendas.map((t) => (
                  <th key={t.codigo} className="whitespace-nowrap border-b border-gray-200 bg-gray-50/95 px-3 py-3 text-center text-[0.68rem] font-semibold uppercase tracking-[0.08em] text-gray-500 backdrop-blur dark:border-white/[0.07] dark:bg-white/[0.035] dark:text-zinc-400">
                    {t.codigo}
                  </th>
                ))}
                <th className="border-b border-gray-200 bg-indigo-50/90 px-3 py-3 text-center text-[0.68rem] font-bold uppercase tracking-[0.08em] text-indigo-600 backdrop-blur dark:border-white/[0.07] dark:bg-indigo-400/[0.07] dark:text-indigo-300">Total</th>
              </tr>
            </thead>
            <tbody>
              {filas.length === 0 ? (
                <tr>
                  <td colSpan={tiendas.length + 2} className="px-4 py-14 text-center text-gray-400 dark:text-zinc-500">
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
                        ? 'group bg-emerald-50/25 transition-colors hover:bg-emerald-50/55 dark:bg-emerald-400/[0.015] dark:hover:bg-emerald-400/[0.045]'
                        : 'group transition-colors hover:bg-gray-50/80 dark:hover:bg-white/[0.025]'}
                    >
                      <td className="sticky left-0 z-[1] whitespace-nowrap border-b border-gray-100 bg-white/95 px-4 py-2.5 font-medium text-gray-900 backdrop-blur transition-colors group-hover:bg-inherit dark:border-white/[0.05] dark:bg-zinc-900/95 dark:text-zinc-200">
                        {fila.nombre}
                      </td>
                      {tiendas.map((t) => {
                        const val = fila[t.codigo] as number | undefined
                        return (
                          <td key={t.codigo} className="border-b border-gray-100 px-3 py-2.5 text-center text-gray-700 dark:border-white/[0.05] dark:text-zinc-300">
                            {val && val > 0 ? (
                              <span className="font-mono font-semibold text-emerald-600 dark:text-emerald-400">{val}</span>
                            ) : (
                              <span className="text-gray-300">—</span>
                            )}
                          </td>
                        )
                      })}
                      <td className="border-b border-gray-100 bg-indigo-50/30 px-3 py-2.5 text-center font-bold text-gray-900 dark:border-white/[0.05] dark:bg-indigo-400/[0.025] dark:text-zinc-100">
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
