import { useQuery } from '@tanstack/react-query'
import { api } from '../../services/api'
import { Badge } from '../../components/ui/badge'
import { PageHeader } from '../../components/PageHeader'

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
      <h2 className="mb-2 px-1 text-[0.7rem] font-bold uppercase tracking-[0.12em] text-kyro-body">{title}</h2>
      <div className="kyro-card overflow-hidden">
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
      <div className="flex h-64 items-center justify-center">
        <p className="text-sm text-kyro-muted">Cargando diagnóstico...</p>
      </div>
    )
  }

  if (!data) return null

  return (
    <div className="mx-auto max-w-5xl space-y-6">
        <PageHeader title="Diagnóstico del Sistema" subtitle="Estado técnico y consistencia operativa en tiempo real">
          <div className="flex flex-wrap items-center gap-2">
            {data.traslados_pendientes > 0 && (
              <span className="inline-flex items-center gap-1.5 rounded-full border border-kyro-warning/30 bg-kyro-warning/10 px-3 py-1.5 text-xs font-semibold text-kyro-warning">
                {data.traslados_pendientes} traslados pendientes
              </span>
            )}
            {data.chips_traslados_pendientes > 0 && (
              <span className="inline-flex items-center gap-1.5 rounded-full border border-kyro-warning/30 bg-kyro-warning/10 px-3 py-1.5 text-xs font-semibold text-kyro-warning">
                {data.chips_traslados_pendientes} chips en tránsito
              </span>
            )}
            {data.traslados_pendientes === 0 && data.chips_traslados_pendientes === 0 && (
              <span className="inline-flex items-center gap-1.5 rounded-full border border-kyro-success/30 bg-kyro-success/10 px-3 py-1.5 text-xs font-semibold text-kyro-success">
                Sin pendientes
              </span>
            )}
          </div>
        </PageHeader>

        <TableSection title="Sesión actual">
          <table className="w-full text-sm text-kyro-body">
            <thead className="kyro-table-head">
              <tr>
                <th className="px-4 py-2.5 text-left">user_id</th>
                <th className="px-4 py-2.5 text-left">tienda_id</th>
                <th className="px-4 py-2.5 text-left">rol</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td className="px-4 py-2.5 text-kyro-body">{data.sesion.user_id}</td>
                <td className="px-4 py-2.5 text-kyro-body">{data.sesion.tienda_id}</td>
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
          <table className="w-full text-sm text-kyro-body">
            <thead className="kyro-table-head">
              <tr>
                <th className="px-4 py-2.5 text-left">id</th>
                <th className="px-4 py-2.5 text-left">codigo</th>
                <th className="px-4 py-2.5 text-left">nombre</th>
                <th className="px-4 py-2.5 text-left">cuenta_bipay_id</th>
                <th className="px-4 py-2.5 text-left">activo</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-kyro-border">
              {data.tiendas.map((t) => (
                <tr key={t.id}>
                  <td className="px-4 py-2.5 text-kyro-muted">{t.id}</td>
                  <td className="px-4 py-2.5 font-mono text-xs text-kyro-body">{t.codigo}</td>
                  <td className="px-4 py-2.5 text-kyro-body">{t.nombre}</td>
                  <td className="px-4 py-2.5 text-kyro-muted">{t.cuenta_bipay_id ?? '—'}</td>
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
          <table className="w-full text-sm text-kyro-body">
            <thead className="kyro-table-head">
              <tr>
                <th className="px-4 py-2.5 text-left">id</th>
                <th className="px-4 py-2.5 text-left">nombre</th>
                <th className="px-4 py-2.5 text-left">rol</th>
                <th className="px-4 py-2.5 text-left">tienda_id</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-kyro-border">
              {data.usuarios.map((u) => (
                <tr key={u.id}>
                  <td className="px-4 py-2.5 text-kyro-muted">{u.id}</td>
                  <td className="px-4 py-2.5 text-kyro-body">{u.nombre}</td>
                  <td className="px-4 py-2.5">
                    <Badge variant={u.rol === 'admin' ? 'default' : 'outline'}>{u.rol}</Badge>
                  </td>
                  <td className="px-4 py-2.5 font-mono text-xs text-kyro-muted">{u.tienda_id}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </TableSection>

        <TableSection title="Chips">
          <table className="w-full text-sm text-kyro-body">
            <thead className="kyro-table-head">
              <tr>
                <th className="px-4 py-2.5 text-left">id</th>
                <th className="px-4 py-2.5 text-left">tienda_codigo</th>
                <th className="px-4 py-2.5 text-left">tienda_origen</th>
                <th className="px-4 py-2.5 text-right">stock_actual</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-kyro-border">
              {data.chips.map((c) => (
                <tr key={c.id}>
                  <td className="px-4 py-2.5 text-kyro-muted">{c.id}</td>
                  <td className="px-4 py-2.5 font-mono text-xs text-kyro-body">{c.tienda_codigo}</td>
                  <td className="px-4 py-2.5 font-mono text-xs text-kyro-body">{c.tienda_origen}</td>
                  <td className="px-4 py-2.5 text-right">
                    <span className={c.stock_actual > 0 ? 'font-semibold text-kyro-success' : 'text-kyro-muted'}>
                      {c.stock_actual}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </TableSection>
    </div>
  )
}
