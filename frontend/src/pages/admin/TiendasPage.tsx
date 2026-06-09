import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../../services/api'
import { Button } from '../../components/ui/button'
import { Dialog } from '../../components/ui/dialog'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { PageHeader } from '../../components/PageHeader'
import { AlertTriangle, Store } from 'lucide-react'

interface Tienda {
  id: number
  codigo: string
  nombre: string
  direccion: string | null
  telefono: string | null
  activo: boolean
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
    onError: (e: any) => {
      const msg = e?.response?.data?.message ?? Object.values(e?.response?.data?.errors ?? {}).flat().join(' ') ?? 'Error'
      setErr(String(msg))
    },
  })

  return (
    <form className="space-y-4" onSubmit={e => { e.preventDefault(); save.mutate(form) }}>
      {err && <p className="text-xs text-red-600 bg-red-50 px-3 py-2 rounded">{err}</p>}
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="block text-xs text-gray-500 mb-1">Código (ID único)</label>
          <Input value={form.codigo} onChange={e => setForm(f => ({ ...f, codigo: e.target.value.toUpperCase() }))} required placeholder="PUNDA95" />
        </div>
        <div>
          <label className="block text-xs text-gray-500 mb-1">Nombre</label>
          <Input value={form.nombre} onChange={e => setForm(f => ({ ...f, nombre: e.target.value }))} required />
        </div>
        <div className="col-span-2">
          <label className="block text-xs text-gray-500 mb-1">Dirección</label>
          <Input value={form.direccion ?? ''} onChange={e => setForm(f => ({ ...f, direccion: e.target.value }))} />
        </div>
        <div>
          <label className="block text-xs text-gray-500 mb-1">Teléfono</label>
          <Input value={form.telefono ?? ''} onChange={e => setForm(f => ({ ...f, telefono: e.target.value }))} />
        </div>
        <div className="flex items-end pb-1.5">
          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" checked={form.activo} onChange={e => setForm(f => ({ ...f, activo: e.target.checked }))} />
            Activa
          </label>
        </div>
      </div>
      <div className="flex gap-2 pt-2">
        <Button type="submit" disabled={save.isPending}>{save.isPending ? 'Guardando...' : 'Guardar'}</Button>
        <Button type="button" variant="outline" onClick={onCancel}>Cancelar</Button>
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
    queryFn: () => api.get('/v1/tiendas', { params: { q: query || undefined, page, per_page: 30 } }).then(r => r.data),
  })

  const eliminar = useMutation({
    mutationFn: (id: number) => api.delete(`/v1/tiendas/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['tiendas'] }),
  })

  if (data?.warning) {
    return (
      <div className="flex items-center gap-3 p-4 bg-yellow-50 border border-yellow-200 rounded-xl text-yellow-800 text-sm">
        <AlertTriangle size={18} /> {data.warning}
      </div>
    )
  }

  const tiendas: Tienda[] = data?.data ?? []

  return (
    <div>
      <PageHeader
        title="Tiendas"
        description="Catálogo de sucursales registradas en el sistema."
        actions={<Button onClick={() => { setEditando(undefined); setDialogOpen(true) }}><Store size={14} /> Nueva tienda</Button>}
      />

      <div className="flex items-center gap-3 mb-4">
        <Input placeholder="Buscar por código o nombre..."
          value={search} onChange={e => setSearch(e.target.value)}
          onKeyDown={e => { if (e.key === 'Enter') { setQuery(search); setPage(1) } }}
          className="max-w-xs"
        />
        <Button variant="outline" onClick={() => { setQuery(search); setPage(1) }}>Buscar</Button>
        {query && <Button variant="ghost" onClick={() => { setSearch(''); setQuery(''); setPage(1) }}>Limpiar</Button>}
      </div>

      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-gray-50 border-b border-gray-200">
                {['Código', 'Nombre', 'Dirección', 'Teléfono', 'Estado', 'Acciones'].map(h => (
                  <th key={h} className="px-4 py-3 text-xs font-semibold text-gray-500 text-left">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {isLoading && <tr><td colSpan={6} className="px-4 py-10 text-center text-gray-400">Cargando...</td></tr>}
              {!isLoading && tiendas.length === 0 && <tr><td colSpan={6} className="px-4 py-10 text-center text-gray-400">Sin tiendas registradas</td></tr>}
              {tiendas.map(t => (
                <tr key={t.id} className="border-b border-gray-100 hover:bg-gray-50/60">
                  <td className="px-4 py-3 font-mono font-bold text-slate-700">{t.codigo}</td>
                  <td className="px-4 py-3 font-medium text-gray-800">{t.nombre}</td>
                  <td className="px-4 py-3 text-gray-500 text-xs">{t.direccion ?? '—'}</td>
                  <td className="px-4 py-3 text-gray-500">{t.telefono ?? '—'}</td>
                  <td className="px-4 py-3">
                    <Badge variant={t.activo ? 'success' : 'warning'}>{t.activo ? 'Activa' : 'Inactiva'}</Badge>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex gap-2">
                      <Button size="sm" variant="outline" onClick={() => { setEditando(t); setDialogOpen(true) }}>Editar</Button>
                      <Button size="sm" variant="destructive"
                        disabled={eliminar.isPending}
                        onClick={() => { if (confirm(`¿Eliminar tienda ${t.nombre}?`)) eliminar.mutate(t.id) }}>
                        Eliminar
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {data?.last_page > 1 && (
          <div className="p-4 border-t border-gray-200 flex items-center justify-between">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Anterior</Button>
            <span className="text-xs text-gray-500">Página {data.current_page} de {data.last_page}</span>
            <Button variant="outline" size="sm" disabled={page >= data.last_page} onClick={() => setPage(p => p + 1)}>Siguiente</Button>
          </div>
        )}
      </div>

      <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} title={editando ? 'Editar tienda' : 'Nueva tienda'} maxWidth="lg">
        <TiendaForm tienda={editando} onSuccess={() => setDialogOpen(false)} onCancel={() => setDialogOpen(false)} />
      </Dialog>
    </div>
  )
}
