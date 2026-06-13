import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../../services/api'
import { Button } from '../../components/ui/button'
import { Dialog } from '../../components/ui/dialog'
import { Input } from '../../components/ui/input'
import { Select } from '../../components/ui/select'
import { Badge } from '../../components/ui/badge'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { KeyRound, Pencil, Plus, Search, ShieldCheck, Trash2, UserRound } from 'lucide-react'

interface Usuario {
  id: number
  nombre: string
  email: string
  rol: 'admin' | 'tienda'
  tienda_id: string | null
  activo: boolean
  tiene_bcp: boolean
}

interface ApiError {
  response?: {
    data?: {
      message?: string
      errors?: Record<string, string[]>
    }
  }
}

interface UsuariosResponse {
  data: Usuario[]
  current_page: number
  last_page: number
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
          <span className="flex h-8 w-8 items-center justify-center rounded-kyro bg-kyro-indigo/15 text-kyro-gold"><UserRound size={15} /></span>
          <div>
            <h3 className="text-sm font-semibold text-kyro-text">Datos de la cuenta</h3>
            <p className="text-xs text-kyro-muted">Identidad y credenciales de acceso.</p>
          </div>
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <label htmlFor="usuario-nombre" className="mb-1 block text-xs text-kyro-muted">Nombre completo</label>
            <Input id="usuario-nombre" value={form.nombre} onChange={e => setForm(f => ({ ...f, nombre: e.target.value }))} required />
          </div>
          <div className="sm:col-span-2">
            <label htmlFor="usuario-email" className="mb-1 block text-xs text-kyro-muted">Email (login)</label>
            <Input id="usuario-email" type="email" value={form.email} onChange={e => setForm(f => ({ ...f, email: e.target.value }))} required />
          </div>
          <div className="sm:col-span-2">
            <label htmlFor="usuario-password" className="mb-1 block text-xs text-kyro-muted">{usuario ? 'Nueva contraseña (vacío = no cambiar)' : 'Contraseña'}</label>
            <div className="relative">
              <KeyRound size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-kyro-muted" />
              <Input id="usuario-password" className="pl-9" type="password" value={form.password} onChange={e => setForm(f => ({ ...f, password: e.target.value }))} required={!usuario} minLength={6} />
            </div>
          </div>
        </div>
      </section>

      <section className="kyro-card p-4">
        <div className="mb-4 flex items-center gap-2.5 border-b border-kyro-border pb-3">
          <span className="flex h-8 w-8 items-center justify-center rounded-kyro bg-kyro-indigo/15 text-kyro-gold"><ShieldCheck size={15} /></span>
          <div>
            <h3 className="text-sm font-semibold text-kyro-text">Acceso y permisos</h3>
            <p className="text-xs text-kyro-muted">Rol, tienda asignada y módulos habilitados.</p>
          </div>
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label htmlFor="usuario-rol" className="mb-1 block text-xs text-kyro-muted">Rol</label>
            <Select id="usuario-rol" value={form.rol} onChange={e => setForm(f => ({ ...f, rol: e.target.value as 'admin' | 'tienda' }))}>
              {ROLES.map(r => <option key={r} value={r}>{r}</option>)}
            </Select>
          </div>
          <div>
            <label htmlFor="usuario-tienda" className="mb-1 block text-xs text-kyro-muted">Tienda ID</label>
            <Input id="usuario-tienda" value={form.tienda_id ?? ''} onChange={e => setForm(f => ({ ...f, tienda_id: e.target.value.toUpperCase() }))} placeholder="P. ej. PUNDA95" />
          </div>
          <label className="flex cursor-pointer items-center gap-2 rounded-kyro border border-kyro-border bg-kyro-elevated px-3 py-2.5 text-sm text-kyro-body">
            <input type="checkbox" checked={form.activo} onChange={e => setForm(f => ({ ...f, activo: e.target.checked }))} className="h-4 w-4 accent-kyro-gold" />
            Usuario activo
          </label>
          <label className="flex cursor-pointer items-center gap-2 rounded-kyro border border-kyro-border bg-kyro-elevated px-3 py-2.5 text-sm text-kyro-body">
            <input type="checkbox" checked={form.tiene_bcp} onChange={e => setForm(f => ({ ...f, tiene_bcp: e.target.checked }))} className="h-4 w-4 accent-kyro-gold" />
            Módulo BCP
          </label>
        </div>
      </section>
      <div className="flex flex-col-reverse gap-2 border-t border-kyro-border pt-4 sm:flex-row sm:justify-end">
        <Button type="button" variant="outline" onClick={onCancel}>Cancelar</Button>
        <Button type="submit" disabled={save.isPending}>{save.isPending ? 'Guardando...' : 'Guardar usuario'}</Button>
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
    queryFn: () => api.get<UsuariosResponse>('/v1/usuarios', { params: { q: query || undefined, page, per_page: 20 } }).then(r => r.data),
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
        actions={<Button onClick={() => { setEditando(undefined); setDialogOpen(true) }}><Plus size={15} /> Nuevo usuario</Button>}
      />

      <ListToolbar description="Busca cuentas por nombre o correo electrónico.">
        <div className="relative w-full sm:max-w-xs">
          <Search size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-kyro-muted" />
          <Input placeholder="Buscar por nombre o email..."
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
                {['ID', 'Nombre', 'Email', 'Rol', 'Tienda', 'Estado', 'BCP', 'Acciones'].map(h => (
                  <th key={h} className="kyro-table-head px-4 py-3 text-left">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-kyro-border">
              {isLoading && <tr><td colSpan={8} className="px-4 py-10 text-center text-kyro-muted">Cargando...</td></tr>}
              {!isLoading && usuarios.length === 0 && <tr><td colSpan={8} className="px-4 py-10 text-center text-kyro-muted">Sin resultados</td></tr>}
              {usuarios.map(u => (
                <tr key={u.id} className="transition-colors hover:bg-kyro-elevated">
                  <td className="px-4 py-3 text-xs text-kyro-muted">#{u.id}</td>
                  <td className="px-4 py-3 font-medium text-kyro-text">{u.nombre}</td>
                  <td className="px-4 py-3 text-kyro-body">{u.email}</td>
                  <td className="px-4 py-3">
                    <Badge variant={u.rol === 'admin' ? 'destructive' : 'success'}>{u.rol}</Badge>
                  </td>
                  <td className="px-4 py-3 font-mono text-xs text-kyro-body">{u.tienda_id ?? '—'}</td>
                  <td className="px-4 py-3">
                    <Badge variant={u.activo ? 'success' : 'warning'}>{u.activo ? 'Activo' : 'Inactivo'}</Badge>
                  </td>
                  <td className="px-4 py-3 text-center text-kyro-body">{u.tiene_bcp ? '✓' : '—'}</td>
                  <td className="px-4 py-3">
                    <div className="flex gap-2">
                      <Button size="sm" variant="outline" onClick={() => { setEditando(u); setDialogOpen(true) }}><Pencil size={13} /> Editar</Button>
                      <Button size="sm" variant="destructive"
                        disabled={eliminar.isPending}
                        onClick={() => { if (confirm(`¿Eliminar usuario ${u.nombre}?`)) eliminar.mutate(u.id) }}>
                        <Trash2 size={13} /> Eliminar
                      </Button>
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

      <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} title={editando ? 'Editar usuario' : 'Nuevo usuario'} maxWidth="lg">
        <UsuarioForm usuario={editando} onSuccess={() => setDialogOpen(false)} onCancel={() => setDialogOpen(false)} />
      </Dialog>
    </div>
  )
}
