import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../../services/api'
import { Button } from '../../components/ui/button'
import { Dialog } from '../../components/ui/dialog'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { PageHeader } from '../../components/PageHeader'

interface Usuario {
  id: number
  nombre: string
  email: string
  rol: 'admin' | 'tienda'
  tienda_id: string | null
  activo: boolean
  tiene_bcp: boolean
}

const ROLES = ['admin', 'tienda']

function UsuarioForm({ usuario, onSuccess, onCancel }: { usuario?: Usuario; onSuccess: () => void; onCancel: () => void }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({
    nombre:    usuario?.nombre    ?? '',
    email:     usuario?.email     ?? '',
    password:  '',
    rol:       usuario?.rol       ?? 'tienda',
    tienda_id: usuario?.tienda_id ?? '',
    activo:    usuario?.activo    ?? true,
    tiene_bcp: usuario?.tiene_bcp ?? false,
  })
  const [err, setErr] = useState('')

  const save = useMutation({
    mutationFn: (payload: typeof form) =>
      usuario
        ? api.put(`/v1/usuarios/${usuario.id}`, payload).then(r => r.data)
        : api.post('/v1/usuarios', payload).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['usuarios'] })
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
        <div className="col-span-2">
          <label className="block text-xs text-gray-500 mb-1">Nombre completo</label>
          <Input value={form.nombre} onChange={e => setForm(f => ({ ...f, nombre: e.target.value }))} required />
        </div>
        <div className="col-span-2">
          <label className="block text-xs text-gray-500 mb-1">Email (login)</label>
          <Input type="email" value={form.email} onChange={e => setForm(f => ({ ...f, email: e.target.value }))} required />
        </div>
        <div className="col-span-2">
          <label className="block text-xs text-gray-500 mb-1">{usuario ? 'Nueva contraseña (dejar vacío = no cambiar)' : 'Contraseña'}</label>
          <Input type="password" value={form.password} onChange={e => setForm(f => ({ ...f, password: e.target.value }))} required={!usuario} minLength={6} />
        </div>
        <div>
          <label className="block text-xs text-gray-500 mb-1">Rol</label>
          <select value={form.rol} onChange={e => setForm(f => ({ ...f, rol: e.target.value as 'admin' | 'tienda' }))}
            className="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
            {ROLES.map(r => <option key={r} value={r}>{r}</option>)}
          </select>
        </div>
        <div>
          <label className="block text-xs text-gray-500 mb-1">Tienda ID</label>
          <Input value={form.tienda_id ?? ''} onChange={e => setForm(f => ({ ...f, tienda_id: e.target.value }))} placeholder="P.ej. PUNDA95" />
        </div>
        <div className="col-span-2 flex items-center gap-4">
          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" checked={form.activo} onChange={e => setForm(f => ({ ...f, activo: e.target.checked }))} />
            Activo
          </label>
          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" checked={form.tiene_bcp} onChange={e => setForm(f => ({ ...f, tiene_bcp: e.target.checked }))} />
            Módulo BCP
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

export function UsuariosPage() {
  const qc = useQueryClient()
  const [search, setSearch] = useState('')
  const [query, setQuery]   = useState('')
  const [page, setPage]     = useState(1)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editando, setEditando]     = useState<Usuario | undefined>()

  const { data, isLoading } = useQuery({
    queryKey: ['usuarios', query, page],
    queryFn: () => api.get('/v1/usuarios', { params: { q: query || undefined, page, per_page: 20 } }).then(r => r.data),
  })

  const eliminar = useMutation({
    mutationFn: (id: number) => api.delete(`/v1/usuarios/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['usuarios'] }),
  })

  const usuarios: Usuario[] = data?.data ?? []

  return (
    <div>
      <PageHeader
        title="Usuarios del Sistema"
        description="Gestión de accesos y roles."
        actions={<Button onClick={() => { setEditando(undefined); setDialogOpen(true) }}>+ Nuevo usuario</Button>}
      />

      <div className="flex items-center gap-3 mb-4">
        <Input placeholder="Buscar por nombre o email..."
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
                {['ID', 'Nombre', 'Email', 'Rol', 'Tienda', 'Estado', 'BCP', 'Acciones'].map(h => (
                  <th key={h} className="px-4 py-3 text-xs font-semibold text-gray-500 text-left">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {isLoading && <tr><td colSpan={8} className="px-4 py-10 text-center text-gray-400">Cargando...</td></tr>}
              {!isLoading && usuarios.length === 0 && <tr><td colSpan={8} className="px-4 py-10 text-center text-gray-400">Sin resultados</td></tr>}
              {usuarios.map(u => (
                <tr key={u.id} className="border-b border-gray-100 hover:bg-gray-50/60">
                  <td className="px-4 py-3 text-xs text-gray-400">#{u.id}</td>
                  <td className="px-4 py-3 font-medium text-gray-800">{u.nombre}</td>
                  <td className="px-4 py-3 text-gray-600">{u.email}</td>
                  <td className="px-4 py-3">
                    <Badge variant={u.rol === 'admin' ? 'destructive' : 'success'}>{u.rol}</Badge>
                  </td>
                  <td className="px-4 py-3 font-mono text-xs">{u.tienda_id ?? '—'}</td>
                  <td className="px-4 py-3">
                    <Badge variant={u.activo ? 'success' : 'warning'}>{u.activo ? 'Activo' : 'Inactivo'}</Badge>
                  </td>
                  <td className="px-4 py-3 text-center">{u.tiene_bcp ? '✓' : '—'}</td>
                  <td className="px-4 py-3">
                    <div className="flex gap-2">
                      <Button size="sm" variant="outline" onClick={() => { setEditando(u); setDialogOpen(true) }}>Editar</Button>
                      <Button size="sm" variant="destructive"
                        disabled={eliminar.isPending}
                        onClick={() => { if (confirm(`¿Eliminar usuario ${u.nombre}?`)) eliminar.mutate(u.id) }}>
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

      <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} title={editando ? 'Editar usuario' : 'Nuevo usuario'} maxWidth="lg">
        <UsuarioForm usuario={editando} onSuccess={() => setDialogOpen(false)} onCancel={() => setDialogOpen(false)} />
      </Dialog>
    </div>
  )
}
