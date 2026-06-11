import { useQuery } from '@tanstack/react-query'
import { api } from '../../services/api'
import { Badge } from '../../components/ui/badge'

interface DiagnosticoSesion {
  user_id: number
  tienda_id: string
  rol: string
}

interface DiagnosticoTienda {
  id: number
  codigo: string
  nombre: string
  cuenta_bipay_id?: number | null
  activo: boolean
}

interface DiagnosticoUsuario {
  id: number
  nombre: string
  rol: string
  tienda_id: string
}

interface DiagnosticoChip {
  id: number
  tienda_codigo: string
  tienda_origen: string
  stock_actual: number
}

interface DiagnosticoResponse {
  sesion: DiagnosticoSesion
  tiendas: DiagnosticoTienda[]
  usuarios: DiagnosticoUsuario[]
  chips: DiagnosticoChip[]
  traslados_pendientes: number
  chips_traslados_pendientes: number
}

function TableSection({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="mb-6">
      <h2 className="text-sm font-semibold text-slate-300 uppercase tracking-wider mb-2 px-1">{title}</h2>
      <div className="rounded-lg overflow-hidden border border-slate-700">
        {children}
      </div>
    </div>
  )
}

export function DiagnosticoPage() {
  const { data, isLoading } = useQuery<DiagnosticoResponse>({
    queryKey: ['diagnostico'],
    queryFn:  () => api.get<DiagnosticoResponse>('/v1/diagnostico').then((r) => r.data),
  })

  if (isLoading) {
    return (
      <div className="min-h-screen bg-slate-900 flex items-center justify-center">
        <p className="text-slate-400 text-sm">Cargando diagnóstico...</p>
      </div>
    )
  }

  if (!data) return null

  return (
    <div className="min-h-screen bg-slate-900 text-slate-200 p-6">
      <div className="max-w-5xl mx-auto">
        <div className="flex items-center justify-between mb-6">
          <div>
            <h1 className="text-xl font-semibold text-white">Diagnóstico del Sistema</h1>
            <p className="text-sm text-slate-400 mt-0.5">Estado técnico en tiempo real</p>
          </div>
          <div className="flex items-center gap-3">
            {data.traslados_pendientes > 0 && (
              <span className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-yellow-500/20 text-yellow-300 border border-yellow-500/30">
                {data.traslados_pendientes} traslados pendientes
              </span>
            )}
            {data.chips_traslados_pendientes > 0 && (
              <span className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-orange-500/20 text-orange-300 border border-orange-500/30">
                {data.chips_traslados_pendientes} chips en tránsito
              </span>
            )}
            {data.traslados_pendientes === 0 && data.chips_traslados_pendientes === 0 && (
              <span className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-green-500/20 text-green-300 border border-green-500/30">
                Sin pendientes
              </span>
            )}
          </div>
        </div>

        <TableSection title="Sesión actual">
          <table className="w-full text-sm">
            <thead className="bg-slate-800">
              <tr>
                <th className="px-4 py-2.5 text-left font-medium text-slate-400">user_id</th>
                <th className="px-4 py-2.5 text-left font-medium text-slate-400">tienda_id</th>
                <th className="px-4 py-2.5 text-left font-medium text-slate-400">rol</th>
              </tr>
            </thead>
            <tbody className="bg-slate-800/40">
              <tr>
                <td className="px-4 py-2.5 text-slate-200">{data.sesion.user_id}</td>
                <td className="px-4 py-2.5 text-slate-200">{data.sesion.tienda_id}</td>
                <td className="px-4 py-2.5">
                  <Badge variant={data.sesion.rol === 'admin' ? 'default' : 'outline'}>
                    {data.sesion.rol}
                  </Badge>
                </td>
              </tr>
            </tbody>
          </table>
        </TableSection>

        <TableSection title="Tiendas">
          <table className="w-full text-sm">
            <thead className="bg-slate-800">
              <tr>
                <th className="px-4 py-2.5 text-left font-medium text-slate-400">id</th>
                <th className="px-4 py-2.5 text-left font-medium text-slate-400">codigo</th>
                <th className="px-4 py-2.5 text-left font-medium text-slate-400">nombre</th>
                <th className="px-4 py-2.5 text-left font-medium text-slate-400">cuenta_bipay_id</th>
                <th className="px-4 py-2.5 text-left font-medium text-slate-400">activo</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-700/50 bg-slate-800/40">
              {data.tiendas.map((t) => (
                <tr key={t.id} className="hover:bg-slate-700/30">
                  <td className="px-4 py-2.5 text-slate-400">{t.id}</td>
                  <td className="px-4 py-2.5 text-slate-200 font-mono text-xs">{t.codigo}</td>
                  <td className="px-4 py-2.5 text-slate-200">{t.nombre}</td>
                  <td className="px-4 py-2.5 text-slate-400">{t.cuenta_bipay_id ?? '—'}</td>
                  <td className="px-4 py-2.5">
                    <Badge variant={t.activo ? 'success' : 'destructive'}>
                      {t.activo ? 'Sí' : 'No'}
                    </Badge>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </TableSection>

        <TableSection title="Usuarios">
          <table className="w-full text-sm">
            <thead className="bg-slate-800">
              <tr>
                <th className="px-4 py-2.5 text-left font-medium text-slate-400">id</th>
                <th className="px-4 py-2.5 text-left font-medium text-slate-400">nombre</th>
                <th className="px-4 py-2.5 text-left font-medium text-slate-400">rol</th>
                <th className="px-4 py-2.5 text-left font-medium text-slate-400">tienda_id</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-700/50 bg-slate-800/40">
              {data.usuarios.map((u) => (
                <tr key={u.id} className="hover:bg-slate-700/30">
                  <td className="px-4 py-2.5 text-slate-400">{u.id}</td>
                  <td className="px-4 py-2.5 text-slate-200">{u.nombre}</td>
                  <td className="px-4 py-2.5">
                    <Badge variant={u.rol === 'admin' ? 'default' : 'outline'}>{u.rol}</Badge>
                  </td>
                  <td className="px-4 py-2.5 text-slate-400 font-mono text-xs">{u.tienda_id}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </TableSection>

        <TableSection title="Chips">
          <table className="w-full text-sm">
            <thead className="bg-slate-800">
              <tr>
                <th className="px-4 py-2.5 text-left font-medium text-slate-400">id</th>
                <th className="px-4 py-2.5 text-left font-medium text-slate-400">tienda_codigo</th>
                <th className="px-4 py-2.5 text-left font-medium text-slate-400">tienda_origen</th>
                <th className="px-4 py-2.5 text-right font-medium text-slate-400">stock_actual</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-700/50 bg-slate-800/40">
              {data.chips.map((c) => (
                <tr key={c.id} className="hover:bg-slate-700/30">
                  <td className="px-4 py-2.5 text-slate-400">{c.id}</td>
                  <td className="px-4 py-2.5 text-slate-200 font-mono text-xs">{c.tienda_codigo}</td>
                  <td className="px-4 py-2.5 text-slate-200 font-mono text-xs">{c.tienda_origen}</td>
                  <td className="px-4 py-2.5 text-right">
                    <span className={c.stock_actual > 0 ? 'text-green-400 font-semibold' : 'text-slate-500'}>
                      {c.stock_actual}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </TableSection>
      </div>
    </div>
  )
}
