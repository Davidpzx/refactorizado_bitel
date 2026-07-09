import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { DownloadSimple as Download, ArrowCounterClockwise as RotateCcw, ClipboardText as ClipboardList } from '@phosphor-icons/react'
import { api } from '../../services/api'
import { useAuth } from '../../hooks/useAuth'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { Button } from '../../components/ui/button'
import { Badge } from '../../components/ui/badge'
import { Select } from '../../components/ui/select'
import { Dialog } from '../../components/ui/dialog'
import { SegmentedToggle } from '../../components/ui/SegmentedToggle'

// ── Types ────────────────────────────────────────────────────────────────────

interface KardexRow {
  id: number
  nombre: string
  tipo: string
  imei: string | null
  tienda: string
  tienda_nombre: string
  fecha_ingreso: string
  estado: 'DISPONIBLE' | 'VENDIDO' | 'TRASLADO'
  precio_costo: number | null
  fecha_venta: string | null
  agente: string | null
  precio: number | null
  es_cuota: boolean | number
}

interface TiendaItem {
  id?: number
  codigo: string
  nombre: string
}

// ── Helpers ──────────────────────────────────────────────────────────────────

const fmtSol = (n: number | null | undefined) =>
  n != null ? `S/ ${parseFloat(String(n)).toFixed(2)}` : '—'

const fmtFecha = (s: string | null | undefined) =>
  s ? s.slice(0, 10) : '—'

type EstadoVariant = 'success' | 'destructive' | 'warning'
const estadoVariant: Record<KardexRow['estado'], EstadoVariant> = {
  DISPONIBLE: 'success',
  VENDIDO:    'destructive',
  TRASLADO:   'warning',
}

const ESTADOS = [
  { value: '', label: 'Todos', tone: 'indigo' as const },
  { value: 'DISPONIBLE', label: 'Disponible', tone: 'success' as const },
  { value: 'VENDIDO', label: 'Vendido', tone: 'danger' as const },
  { value: 'TRASLADO', label: 'Traslado', tone: 'warning' as const },
]

// ── Page ─────────────────────────────────────────────────────────────────────

export function KardexInventarioPage() {
  const { usuario } = useAuth()
  const isAdmin = usuario?.rol === 'admin'
  const qc = useQueryClient()

  const [tienda, setTienda] = useState('')
  const [estado, setEstado] = useState('')
  const [exportando, setExportando] = useState(false)
  const [confirmRow, setConfirmRow] = useState<KardexRow | null>(null)

  // Tiendas para el dropdown
  const { data: tiendasData } = useQuery<{ data: TiendaItem[] } | TiendaItem[]>({
    queryKey: ['tiendas-list'],
    queryFn: () => api.get('/v1/tiendas').then(r => r.data),
    staleTime: 300_000,
  })

  const tiendas: TiendaItem[] = Array.isArray(tiendasData)
    ? tiendasData
    : (tiendasData as { data: TiendaItem[] })?.data ?? []

  // Kardex rows
  const { data, isLoading } = useQuery<{ ok: boolean; rows: KardexRow[] }>({
    queryKey: ['inventario-kardex', tienda, estado],
    queryFn: () =>
      api
        .get('/v1/inventario/kardex', { params: { tienda: tienda || undefined, estado: estado || undefined } })
        .then(r => r.data),
  })

  const rows = data?.rows ?? []

  // Restaurar item vendido
  const restaurar = useMutation({
    mutationFn: (id: number) => api.post(`/v1/inventario/${id}/restaurar`).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventario-kardex'] })
      setConfirmRow(null)
    },
  })

  // Exportar XLSX via blob
  const handleExportar = async () => {
    setExportando(true)
    try {
      const params: Record<string, string> = {}
      if (tienda) params.tienda = tienda
      if (estado) params.estado = estado

      const resp = await api.get('/v1/inventario/exportar-kardex', {
        params,
        responseType: 'blob',
      })

      const url = window.URL.createObjectURL(new Blob([resp.data]))
      const a = document.createElement('a')
      a.href = url
      a.download = `kardex-${Date.now()}.xlsx`
      document.body.appendChild(a)
      a.click()
      a.remove()
      window.URL.revokeObjectURL(url)
    } catch {
      // silently fail — user sees nothing if error
    } finally {
      setExportando(false)
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        Icon={ClipboardList}
        title="Kardex de Inventario"
        description="Historial completo de movimientos de stock."
        actions={
          <Button variant="glassSuccess" size="sm" onClick={handleExportar} disabled={exportando}>
            <Download size={14} className="mr-1.5" />
            {exportando ? 'Exportando...' : 'Exportar Excel'}
          </Button>
        }
      />

      <ListToolbar description="Consulta el historial por tienda y estado de inventario.">
        <Select
          value={tienda}
          onChange={e => setTienda(e.target.value)}
          className="w-52"
        >
          <option value="">Todas las tiendas</option>
          {tiendas.map(t => (
            <option key={t.codigo} value={t.codigo}>
              {t.codigo} — {t.nombre ?? t.codigo}
            </option>
          ))}
        </Select>

        <SegmentedToggle
          ariaLabel="Filtrar kardex por estado"
          size="sm"
          options={ESTADOS}
          value={estado}
          onChange={setEstado}
        />

        {(tienda || estado) && (
          <Button variant="ghost" size="sm" onClick={() => { setTienda(''); setEstado('') }}>
            Limpiar filtros
          </Button>
        )}
      </ListToolbar>

      {/* Tabla */}
      {isLoading ? (
        <div className="kyro-card flex h-48 items-center justify-center text-sm text-kyro-muted">
          Cargando kardex...
        </div>
      ) : rows.length === 0 ? (
        <div className="kyro-card flex h-48 items-center justify-center text-sm text-kyro-muted">
          Sin registros para los filtros seleccionados.
        </div>
      ) : (
        <div className="kyro-card relative overflow-x-auto">
          <table className="w-full border-separate border-spacing-0 text-xs" style={{ minWidth: '1100px' }}>
            <thead className="[&_th]:kyro-table-head">
              <tr className="text-[0.65rem] uppercase tracking-wide">
                <th className="py-2 px-3 text-left">Tienda</th>
                <th className="py-2 px-3 text-left">Producto</th>
                <th className="py-2 px-3 text-left">IMEI / Serie</th>
                <th className="py-2 px-2 text-center">Tipo</th>
                <th className="py-2 px-2 text-center">Estado</th>
                <th className="py-2 px-2 text-center">Ingreso</th>
                <th className="py-2 px-2 text-right">P. Costo</th>
                <th className="py-2 px-2 text-right">P. Venta</th>
                <th className="py-2 px-3 text-left">Vendido a</th>
                <th className="py-2 px-2 text-center">F. Venta</th>
                <th className="py-2 px-2 text-center">Cuotas</th>
                {isAdmin && <th className="py-2 px-2 w-20"></th>}
              </tr>
            </thead>
            <tbody>
              {rows.map(row => (
                <tr key={row.id} className="text-xs text-kyro-body transition-colors hover:bg-kyro-elevated/50 [&>td]:border-b [&>td]:border-kyro-border">
                  <td className="py-1.5 px-3 whitespace-nowrap">
                    <span className="font-mono text-[10px] text-kyro-muted">{row.tienda}</span>
                    {row.tienda_nombre && <span className="ml-1 text-xs text-muted-foreground/80">{row.tienda_nombre}</span>}
                  </td>
                  <td className="py-1.5 px-3 font-medium whitespace-nowrap">{row.nombre}</td>
                  <td className="py-1.5 px-3 font-mono text-muted-foreground">{row.imei ?? '—'}</td>
                  <td className="py-1.5 px-2 text-center">
                    <Badge variant={row.tipo === 'EQUIPO' ? 'default' : row.tipo === 'ACCESORIO' ? 'outline' : 'warning'}>
                      {row.tipo}
                    </Badge>
                  </td>
                  <td className="py-1.5 px-2 text-center">
                    <Badge variant={estadoVariant[row.estado]}>{row.estado}</Badge>
                  </td>
                  <td className="py-1.5 px-2 text-center text-muted-foreground">{fmtFecha(row.fecha_ingreso)}</td>
                  <td className="py-1.5 px-2 text-right font-mono">{fmtSol(row.precio_costo)}</td>
                  <td className="py-1.5 px-2 text-right font-mono">{fmtSol(row.precio)}</td>
                  <td className="py-1.5 px-3 text-muted-foreground">{row.agente ?? '—'}</td>
                  <td className="py-1.5 px-2 text-center text-muted-foreground">{fmtFecha(row.fecha_venta)}</td>
                  <td className="py-1.5 px-2 text-center">
                    {row.es_cuota ? (
                      <Badge variant="warning">Sí</Badge>
                    ) : (
                      <span className="text-muted-foreground/40">—</span>
                    )}
                  </td>
                  {isAdmin && (
                    <td className="py-1.5 px-2 text-center">
                      {row.estado === 'VENDIDO' && (
                        <Button
                          size="sm"
                          variant="glassWarning"
                          onClick={() => setConfirmRow(row)}
                          title="Restaurar a disponible"
                        >
                          <RotateCcw size={12} className="mr-1" />
                          Restaurar
                        </Button>
                      )}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Confirm restaurar */}
      <Dialog
        open={!!confirmRow}
        onClose={() => setConfirmRow(null)}
        title="Restaurar item"
      >
        <p className="mb-4 text-sm text-kyro-body">
          ¿Restaurar <strong>{confirmRow?.nombre}</strong>{confirmRow?.imei ? ` (${confirmRow.imei})` : ''} a estado DISPONIBLE?
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setConfirmRow(null)}>
            Cancelar
          </Button>
          <Button
            variant="destructive"
            onClick={() => confirmRow && restaurar.mutate(confirmRow.id)}
            disabled={restaurar.isPending}
          >
            {restaurar.isPending ? 'Restaurando...' : 'Confirmar'}
          </Button>
        </div>
      </Dialog>
    </div>
  )
}
