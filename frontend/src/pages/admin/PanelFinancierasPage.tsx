import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../../services/api'
import { PageHeader } from '../../components/PageHeader'
import { Button } from '../../components/ui/button'
import { Badge } from '../../components/ui/badge'
import { Select } from '../../components/ui/select'
import { Card, CardContent } from '../../components/ui/card'

interface TiendaFiltro {
  codigo: string
  nombre: string
}

interface FiltrosDisponibles {
  financieras: string[]
  tiendas: TiendaFiltro[]
}

interface FinancieraItem {
  id: number
  financiera: string
  comision_estado: string
  fecha: string
  tienda_id: number
  tienda_nombre: string
  vendedor_nombre: string
  por_cobrar: number
  detalle: {
    producto_nombre?: string
    [key: string]: unknown
  }
}

interface Totales {
  pendiente: number
  confirmado: number
  total: number
  count_pendiente: number
}

interface FinancierasResponse {
  data: FinancieraItem[]
  totales: Totales
  filtros_disponibles: FiltrosDisponibles
}

type EstadoVariant = 'warning' | 'success' | 'default'
const estadoBadge: Record<string, EstadoVariant> = {
  PENDIENTE: 'warning',
  APROBADA:  'success',
}

function formatMes() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}

export function PanelFinancierasPage() {
  const qc = useQueryClient()

  const [mes, setMes]           = useState(formatMes())
  const [financiera, setFinanciera] = useState('')
  const [tienda, setTienda]     = useState('')
  const [estado, setEstado]     = useState('TODAS')

  const { data, isLoading } = useQuery<FinancierasResponse>({
    queryKey: ['financieras', { mes, financiera, tienda, estado }],
    queryFn: () =>
      api
        .get<FinancierasResponse>('/v1/financieras', {
          params: { mes, financiera: financiera || undefined, tienda: tienda || undefined, estado },
        })
        .then((r) => r.data),
  })

  const confirmar = useMutation({
    mutationFn: (id: number) =>
      api.post(`/v1/financieras/${id}/confirmar-desembolso`).then((r) => r.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['financieras'] }),
  })

  const revertir = useMutation({
    mutationFn: (id: number) =>
      api.post(`/v1/financieras/${id}/revertir-desembolso`).then((r) => r.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['financieras'] }),
  })

  const items    = data?.data ?? []
  const totales  = data?.totales
  const filtros  = data?.filtros_disponibles

  return (
    <div>
      <PageHeader
        title="Panel de Financieras"
        description="Seguimiento de desembolsos y comisiones por financiera."
      />

      <div className="grid grid-cols-3 gap-4 mb-6">
        <Card>
          <CardContent className="pt-4">
            <p className="text-xs text-gray-500 uppercase tracking-wider">Pendiente de cobro</p>
            <p className="text-2xl font-bold text-yellow-600 mt-1">
              S/ {totales ? Number(totales.pendiente).toFixed(2) : '—'}
            </p>
            <p className="text-xs text-gray-400 mt-1">{totales?.count_pendiente ?? 0} registros</p>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-4">
            <p className="text-xs text-gray-500 uppercase tracking-wider">Confirmado este mes</p>
            <p className="text-2xl font-bold text-green-600 mt-1">
              S/ {totales ? Number(totales.confirmado).toFixed(2) : '—'}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-4">
            <p className="text-xs text-gray-500 uppercase tracking-wider">Total facturado</p>
            <p className="text-2xl font-bold text-gray-900 mt-1">
              S/ {totales ? Number(totales.total).toFixed(2) : '—'}
            </p>
          </CardContent>
        </Card>
      </div>

      <div className="flex flex-wrap items-center gap-3 mb-4">
        <input
          type="month"
          value={mes}
          onChange={(e) => setMes(e.target.value)}
          className="border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        />

        <Select
          value={financiera}
          onChange={(e) => setFinanciera(e.target.value)}
          className="w-44"
        >
          <option value="">Todas las financieras</option>
          {filtros?.financieras.map((f) => (
            <option key={f} value={f}>{f}</option>
          ))}
        </Select>

        <Select
          value={tienda}
          onChange={(e) => setTienda(e.target.value)}
          className="w-44"
        >
          <option value="">Todas las tiendas</option>
          {filtros?.tiendas.map((t) => (
            <option key={t.codigo} value={t.codigo}>{t.codigo} — {t.nombre}</option>
          ))}
        </Select>

        <Select
          value={estado}
          onChange={(e) => setEstado(e.target.value)}
          className="w-36"
        >
          <option value="TODAS">Todos los estados</option>
          <option value="PENDIENTE">Pendiente</option>
          <option value="APROBADA">Aprobada</option>
        </Select>
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
                <th className="px-4 py-3 text-left font-semibold text-gray-700">Fecha</th>
                <th className="px-4 py-3 text-left font-semibold text-gray-700">Tienda</th>
                <th className="px-4 py-3 text-left font-semibold text-gray-700">Financiera</th>
                <th className="px-4 py-3 text-left font-semibold text-gray-700">Vendedor</th>
                <th className="px-4 py-3 text-left font-semibold text-gray-700">Equipo</th>
                <th className="px-4 py-3 text-center font-semibold text-gray-700">Estado</th>
                <th className="px-4 py-3 text-right font-semibold text-gray-700">Por cobrar</th>
                <th className="px-4 py-3 text-right font-semibold text-gray-700">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {items.length === 0 ? (
                <tr>
                  <td colSpan={8} className="px-4 py-8 text-center text-gray-400">
                    Sin registros para los filtros seleccionados
                  </td>
                </tr>
              ) : (
                items.map((item) => (
                  <tr key={item.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 text-gray-600 whitespace-nowrap">
                      {item.fecha?.slice(0, 10) ?? '—'}
                    </td>
                    <td className="px-4 py-3 text-gray-900">{item.tienda_nombre}</td>
                    <td className="px-4 py-3 text-gray-700">{item.financiera}</td>
                    <td className="px-4 py-3 text-gray-700">{item.vendedor_nombre}</td>
                    <td className="px-4 py-3 text-gray-700">
                      {item.detalle?.producto_nombre ?? '—'}
                    </td>
                    <td className="px-4 py-3 text-center">
                      <Badge variant={estadoBadge[item.comision_estado] ?? 'default'}>
                        {item.comision_estado}
                      </Badge>
                    </td>
                    <td className="px-4 py-3 text-right font-semibold text-gray-900">
                      S/ {Number(item.por_cobrar).toFixed(2)}
                    </td>
                    <td className="px-4 py-3 text-right">
                      {item.comision_estado === 'PENDIENTE' && (
                        <Button
                          size="sm"
                          onClick={() => confirmar.mutate(item.id)}
                          disabled={confirmar.isPending}
                        >
                          Confirmar Desembolso
                        </Button>
                      )}
                      {item.comision_estado === 'APROBADA' && (
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => revertir.mutate(item.id)}
                          disabled={revertir.isPending}
                        >
                          Revertir
                        </Button>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
