import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../../services/api'
import { Button } from '../../components/ui/button'
import { Dialog } from '../../components/ui/dialog'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { AlertTriangle, MapPin, Pencil, Phone, Plus, Search, Store, Trash2 } from 'lucide-react'

interface Tienda {
  id: number
  codigo: string
  nombre: string
  direccion: string | null
  telefono: string | null
  activo: boolean
}

interface ApiError {
  response?: {
    data?: {
      message?: string
      errors?: Record<string, string[]>
    }
  }
}

interface TiendasResponse {
  data: Tienda[]
  current_page: number
  last_page: number
  warning?: string
}

function TiendaForm({ tienda, onSuccess, onCancel }: { tienda?: Tienda; onSuccess: () => void; onCancel: () => void }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({
    codigo:    tienda?.codigo    ?? '',
    nombre:    tienda?.nombre    ?? '',
    direccion: tienda?.direccion ?? '',
    telefono:  tienda?.telefono  ?? '',
    activo:    tienda?.activo    ?? true,
  })
  const [err, setErr] = useState('')

  const save = useMutation({
    mutationFn: (payload: typeof form) =>
      tienda
        ? api.put(`/v1/tiendas/${tienda.id}`, payload).then(r => r.data)
        : api.post('/v1/tiendas', payload).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['tiendas'] })
      onSuccess()
    },
    onError: (e: ApiError) => {
      const msg = e?.response?.data?.message ?? Object.values(e?.response?.data?.errors ?? {}).flat().join(' ') ?? 'Error'
      setErr(String(msg))
    },
  })

  return (
    <form className="space-y-4" onSubmit={e => { e.preventDefault(); save.mutate(form) }}>
      {err && <p className="rounded-kyro border border-kyro-danger/30 bg-kyro-danger/10 px-3 py-2 text-xs text-kyro-danger">{err}</p>}
      <section className="kyro-card p-4">
        <div className="mb-4 flex items-center gap-2.5 border-b border-kyro-border pb-3">
          <span className="flex h-8 w-8 items-center justify-center rounded-kyro bg-kyro-indigo/15 text-kyro-gold"><Store size={15} /></span>
          <div>
            <h3 className="text-sm font-semibold text-kyro-text">Identificación</h3>
            <p className="text-xs text-kyro-muted">Código y nombre visible de la sucursal.</p>
          </div>
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label htmlFor="tienda-codigo" className="mb-1 block text-xs text-kyro-muted">Código (ID único)</label>
            <Input id="tienda-codigo" value={form.codigo} onChange={e => setForm(f => ({ ...f, codigo: e.target.value.toUpperCase() }))} required placeholder="PUNDA95" className="font-mono uppercase" />
          </div>
          <div>
            <label htmlFor="tienda-nombre" className="mb-1 block text-xs text-kyro-muted">Nombre</label>
            <Input id="tienda-nombre" value={form.nombre} onChange={e => setForm(f => ({ ...f, nombre: e.target.value }))} required />
          </div>
        </div>
      </section>

      <section className="kyro-card p-4">
        <div className="mb-4 flex items-center gap-2.5 border-b border-kyro-border pb-3">
          <span className="flex h-8 w-8 items-center justify-center rounded-kyro bg-kyro-indigo/15 text-kyro-gold"><MapPin size={15} /></span>
          <div>
            <h3 className="text-sm font-semibold text-kyro-text">Ubicación y contacto</h3>
            <p className="text-xs text-kyro-muted">Datos operativos de la tienda.</p>
          </div>
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <label htmlFor="tienda-direccion" className="mb-1 block text-xs text-kyro-muted">Dirección</label>
            <div className="relative">
              <MapPin size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-kyro-muted" />
              <Input id="tienda-direccion" className="pl-9" value={form.direccion ?? ''} onChange={e => setForm(f => ({ ...f, direccion: e.target.value }))} />
            </div>
          </div>
          <div>
            <label htmlFor="tienda-telefono" className="mb-1 block text-xs text-kyro-muted">Teléfono</label>
            <div className="relative">
              <Phone size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-kyro-muted" />
              <Input id="tienda-telefono" className="pl-9" inputMode="tel" value={form.telefono ?? ''} onChange={e => setForm(f => ({ ...f, telefono: e.target.value }))} />
            </div>
          </div>
          <label className="flex cursor-pointer items-center gap-2 self-end rounded-kyro border border-kyro-border bg-kyro-elevated px-3 py-2.5 text-sm text-kyro-body">
            <input type="checkbox" checked={form.activo} onChange={e => setForm(f => ({ ...f, activo: e.target.checked }))} className="h-4 w-4 accent-kyro-gold" />
            Tienda activa
          </label>
        </div>
      </section>
      <div className="flex flex-col-reverse gap-2 border-t border-kyro-border pt-4 sm:flex-row sm:justify-end">
        <Button type="button" variant="outline" onClick={onCancel}>Cancelar</Button>
        <Button type="submit" disabled={save.isPending}>{save.isPending ? 'Guardando...' : 'Guardar tienda'}</Button>
      </div>
    </form>
  )
}

export function TiendasPage() {
  const qc = useQueryClient()
  const [search, setSearch]         = useState('')
  const [query, setQuery]           = useState('')
  const [page, setPage]             = useState(1)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editando, setEditando]     = useState<Tienda | undefined>()

  const { data, isLoading } = useQuery({
    queryKey: ['tiendas', query, page],
    queryFn: () => api.get<TiendasResponse>('/v1/tiendas', { params: { q: query || undefined, page, per_page: 30 } }).then(r => r.data),
  })

  const eliminar = useMutation({
    mutationFn: (id: number) => api.delete(`/v1/tiendas/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['tiendas'] }),
  })

  if (data?.warning) {
    return (
      <div className="flex items-center gap-3 rounded-kyro-lg border border-kyro-warning/30 bg-kyro-warning/10 p-4 text-sm text-kyro-warning">
        <AlertTriangle size={18} /> {data.warning}
      </div>
    )
  }

  const tiendas: Tienda[] = data?.data ?? []

  return (
    <div className="space-y-6">
      <PageHeader
        title="Tiendas"
        description="Catálogo de sucursales registradas en el sistema."
        actions={<Button onClick={() => { setEditando(undefined); setDialogOpen(true) }}><Plus size={15} /> Nueva tienda</Button>}
      />

      <ListToolbar description="Busca sucursales por código o nombre.">
        <div className="relative w-full sm:max-w-xs">
          <Search size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-kyro-muted" />
          <Input placeholder="Buscar por código o nombre..."
            value={search} onChange={e => setSearch(e.target.value)}
            onKeyDown={e => { if (e.key === 'Enter') { setQuery(search); setPage(1) } }}
            className="pl-9"
          />
        </div>
        <Button variant="outline" onClick={() => { setQuery(search); setPage(1) }}><Search size={14} /> Buscar</Button>
        {query && <Button variant="ghost" onClick={() => { setSearch(''); setQuery(''); setPage(1) }}>Limpiar</Button>}
      </ListToolbar>

      <div className="kyro-card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full border-separate border-spacing-0 text-sm">
            <thead>
              <tr>
                {['Código', 'Nombre', 'Dirección', 'Teléfono', 'Estado', 'Acciones'].map(h => (
                  <th key={h} className="kyro-table-head px-4 py-3 text-left">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-kyro-border">
              {isLoading && <tr><td colSpan={6} className="px-4 py-10 text-center text-kyro-muted">Cargando...</td></tr>}
              {!isLoading && tiendas.length === 0 && <tr><td colSpan={6} className="px-4 py-10 text-center text-kyro-muted">Sin tiendas registradas</td></tr>}
              {tiendas.map(t => (
                <tr key={t.id} className="transition-colors hover:bg-kyro-elevated">
                  <td className="px-4 py-3 font-mono font-bold text-kyro-text">{t.codigo}</td>
                  <td className="px-4 py-3 font-medium text-kyro-text">{t.nombre}</td>
                  <td className="px-4 py-3 text-xs text-kyro-muted">{t.direccion ?? '—'}</td>
                  <td className="px-4 py-3 text-kyro-muted">{t.telefono ?? '—'}</td>
                  <td className="px-4 py-3">
                    <Badge variant={t.activo ? 'success' : 'warning'}>{t.activo ? 'Activa' : 'Inactiva'}</Badge>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex gap-1">
                      <button onClick={() => { setEditando(t); setDialogOpen(true) }} className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-transparent text-kyro-muted transition-all hover:border-amber-500/40 hover:bg-amber-500/10 hover:text-amber-600 dark:hover:text-amber-400" title="Editar tienda"><Pencil size={13} /></button>
                      <button disabled={eliminar.isPending} onClick={() => { if (confirm(`¿Eliminar tienda ${t.nombre}?`)) eliminar.mutate(t.id) }} className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-transparent text-kyro-muted transition-all hover:border-red-500/40 hover:bg-red-500/10 hover:text-red-600 dark:hover:text-red-400 disabled:opacity-40 disabled:pointer-events-none" title="Eliminar tienda"><Trash2 size={13} /></button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {(data?.last_page ?? 0) > 1 && (
          <div className="flex items-center justify-between border-t border-kyro-border bg-kyro-elevated p-3.5">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Anterior</Button>
            <span className="text-xs text-kyro-muted">Página {data?.current_page ?? page} de {data?.last_page ?? 1}</span>
            <Button variant="outline" size="sm" disabled={page >= (data?.last_page ?? 1)} onClick={() => setPage(p => p + 1)}>Siguiente</Button>
          </div>
        )}
      </div>

      <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} title={editando ? 'Editar tienda' : 'Nueva tienda'} maxWidth="lg">
        <TiendaForm tienda={editando} onSuccess={() => setDialogOpen(false)} onCancel={() => setDialogOpen(false)} />
      </Dialog>
    </div>
  )
}
