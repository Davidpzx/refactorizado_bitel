import { useState, useEffect, useRef } from 'react'
import type { FormEvent } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../../services/api'
import { ActionIconButton, TableActions } from '../../components/ui/ActionIconButton'
import { Button } from '../../components/ui/button'
import { Dialog } from '../../components/ui/dialog'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { AlertTriangle, LocateFixed, MapPin, Pencil, Plus, Search, Store, Trash2 } from 'lucide-react'
import {
  sanitizarCodigo,
  sanitizarNombre,
  validarTienda,
  LIMITES_TIENDA,
  type ErroresTienda,
} from '../../lib/validacionesTienda'

interface Tienda {
  id: number
  codigo: string
  nombre: string
  direccion: string | null
  telefono: string | null
  activo: boolean
  latitud: number | null
  longitud: number | null
  radio_permitido: number | null
}

interface ApiError {
  response?: {
    data?: {
      message?: string
      error?: string
      errors?: Record<string, string[]>
    }
  }
}

/** Saca el mensaje de error del backend sin importar bajo qué clave venga (message, error, errors). */
function mensajeErrorTienda(e: ApiError, camposBackend: Record<string, string[]>): string {
  const data = e?.response?.data
  if (data?.message) return data.message
  if (data?.error) return data.error
  const primerCampo = Object.values(camposBackend).flat()[0]
  if (primerCampo) return primerCampo
  return 'No se pudo guardar la tienda.'
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
    codigo:         tienda?.codigo    ?? '',
    nombre:         tienda?.nombre    ?? '',
    direccion:      tienda?.direccion ?? '',
    telefono:       tienda?.telefono  ?? '',
    activo:         tienda?.activo    ?? true,
    latitud:        tienda?.latitud  != null ? String(tienda.latitud)  : '',
    longitud:       tienda?.longitud != null ? String(tienda.longitud) : '',
    radioPermitido: tienda?.radio_permitido != null ? String(tienda.radio_permitido) : '',
  })
  const [err, setErr]         = useState('')
  const [errores, setErrores] = useState<ErroresTienda>({})
  const errorRef              = useRef<HTMLParagraphElement>(null)

  useEffect(() => {
    if (err) errorRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }, [err])

  const save = useMutation({
    mutationFn: (payload: typeof form) => {
      const cuerpo = {
        ...payload,
        latitud:  payload.latitud  ? Number(payload.latitud)  : null,
        longitud: payload.longitud ? Number(payload.longitud) : null,
        ...(payload.radioPermitido ? { radio_permitido: Number(payload.radioPermitido) } : {}),
      }
      return tienda
        ? api.put(`/v1/tiendas/${tienda.id}`, cuerpo).then(r => r.data)
        : api.post('/v1/tiendas', cuerpo).then(r => r.data)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['tiendas'] })
      onSuccess()
    },
    onError: (e: ApiError) => {
      const camposBackend = e?.response?.data?.errors ?? {}
      if (Object.keys(camposBackend).length > 0) {
        setErrores({
          codigo:         camposBackend.codigo?.[0],
          nombre:         camposBackend.nombre?.[0],
          direccion:      camposBackend.direccion?.[0],
          telefono:       camposBackend.telefono?.[0],
          latitud:        camposBackend.latitud?.[0],
          longitud:       camposBackend.longitud?.[0],
          radioPermitido: camposBackend.radio_permitido?.[0],
        })
      }
      setErr(mensajeErrorTienda(e, camposBackend))
    },
  })

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault()
    const erroresValidacion = validarTienda(form)
    setErrores(erroresValidacion)
    if (Object.keys(erroresValidacion).length > 0) {
      setErr('Revisa los campos marcados antes de continuar.')
      return
    }
    setErr('')
    save.mutate(form)
  }

  return (
    <form className="space-y-4" onSubmit={handleSubmit} noValidate>
      {err && <p ref={errorRef} className="rounded-kyro border border-kyro-danger/30 bg-kyro-danger/10 px-3 py-2 text-xs font-medium text-kyro-danger">{err}</p>}
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
            <Input
              id="tienda-codigo"
              value={form.codigo}
              onChange={e => { setForm(f => ({ ...f, codigo: sanitizarCodigo(e.target.value) })); setErrores(er => ({ ...er, codigo: undefined })) }}
              required
              placeholder="PUNDA95"
              maxLength={LIMITES_TIENDA.codigo}
              className="font-mono uppercase"
            />
            {errores.codigo && <p className="mt-1 text-[11px] text-kyro-danger">{errores.codigo}</p>}
          </div>
          <div>
            <label htmlFor="tienda-nombre" className="mb-1 block text-xs text-kyro-muted">Nombre</label>
            <Input
              id="tienda-nombre"
              value={form.nombre}
              onChange={e => { setForm(f => ({ ...f, nombre: sanitizarNombre(e.target.value) })); setErrores(er => ({ ...er, nombre: undefined })) }}
              required
              maxLength={LIMITES_TIENDA.nombre}
            />
            {errores.nombre && <p className="mt-1 text-[11px] text-kyro-danger">{errores.nombre}</p>}
          </div>
        </div>
      </section>

      <section className="kyro-card p-4">
        <div className="mb-4 flex items-center gap-2.5 border-b border-kyro-border pb-3">
          <span className="flex h-8 w-8 items-center justify-center rounded-kyro bg-kyro-indigo/15 text-kyro-gold"><MapPin size={15} /></span>
          <div>
            <h3 className="text-sm font-semibold text-kyro-text">Ubicación y estado</h3>
            <p className="text-xs text-kyro-muted">
              Dirección y teléfono próximamente — aún no están disponibles en la base de datos.
              Latitud/longitud son opcionales: también se pueden capturar luego con el botón GPS del listado.
            </p>
          </div>
        </div>
        {/*
          TEMPORAL: dirección y teléfono ocultos hasta correr la migración
          2026_06_20_000001_add_direccion_telefono_to_tiendas (la tabla real todavía no tiene
          esas columnas). Backend ya las ignora en TiendaController; esto evita la confusión
          de que el usuario llene un campo que no se va a guardar. Reactivar junto con el backend.
        */}
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label htmlFor="tienda-latitud" className="mb-1 block text-xs text-kyro-muted">Latitud</label>
            <Input
              id="tienda-latitud"
              type="number"
              step="any"
              value={form.latitud}
              onChange={e => { setForm(f => ({ ...f, latitud: e.target.value })); setErrores(er => ({ ...er, latitud: undefined })) }}
              placeholder="-12.0464"
            />
            {errores.latitud && <p className="mt-1 text-[11px] text-kyro-danger">{errores.latitud}</p>}
          </div>
          <div>
            <label htmlFor="tienda-longitud" className="mb-1 block text-xs text-kyro-muted">Longitud</label>
            <Input
              id="tienda-longitud"
              type="number"
              step="any"
              value={form.longitud}
              onChange={e => { setForm(f => ({ ...f, longitud: e.target.value })); setErrores(er => ({ ...er, longitud: undefined })) }}
              placeholder="-77.0428"
            />
            {errores.longitud && <p className="mt-1 text-[11px] text-kyro-danger">{errores.longitud}</p>}
          </div>
          <div>
            <label htmlFor="tienda-radio" className="mb-1 block text-xs text-kyro-muted">Radio de geocerca (metros)</label>
            <Input
              id="tienda-radio"
              type="number"
              min={1}
              step="1"
              value={form.radioPermitido}
              onChange={e => { setForm(f => ({ ...f, radioPermitido: e.target.value })); setErrores(er => ({ ...er, radioPermitido: undefined })) }}
              placeholder="60"
            />
            {errores.radioPermitido && <p className="mt-1 text-[11px] text-kyro-danger">{errores.radioPermitido}</p>}
          </div>
          <label className="flex cursor-pointer items-center gap-2 rounded-kyro border border-kyro-border bg-kyro-elevated px-3 py-2.5 text-sm text-kyro-body sm:col-span-2">
            <input type="checkbox" checked={form.activo} onChange={e => setForm(f => ({ ...f, activo: e.target.checked }))} className="h-4 w-4 accent-kyro-gold" />
            Tienda activa
          </label>
        </div>
      </section>
      <div className="flex flex-col-reverse gap-2 border-t border-kyro-border pt-4 sm:flex-row sm:justify-end">
        <Button type="button" variant="outline" onClick={onCancel}>Cancelar</Button>
        <Button type="submit" variant="gold" disabled={save.isPending}>{save.isPending ? 'Guardando...' : 'Guardar tienda'}</Button>
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
  const [capturando, setCapturando] = useState<number | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['tiendas', query, page],
    queryFn: () => api.get<TiendasResponse>('/v1/tiendas', { params: { q: query || undefined, page, per_page: 30 } }).then(r => r.data),
  })

  const eliminar = useMutation({
    mutationFn: (id: number) => api.delete(`/v1/tiendas/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['tiendas'] }),
  })

  const ubicacion = useMutation({
    mutationFn: ({ id, latitud, longitud }: { id: number; latitud: number; longitud: number }) =>
      api.put(`/v1/tiendas/${id}`, { latitud, longitud }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['tiendas'] }),
    onError: () => alert('No se pudo guardar la ubicación. Intenta de nuevo.'),
    onSettled: () => setCapturando(null),
  })

  const capturarUbicacion = (t: Tienda) => {
    if (!('geolocation' in navigator)) {
      alert('Este navegador no soporta geolocalización.')
      return
    }
    setCapturando(t.id)
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        ubicacion.mutate({ id: t.id, latitud: pos.coords.latitude, longitud: pos.coords.longitude })
      },
      () => {
        alert('No se pudo obtener tu ubicación. Verifica los permisos del navegador.')
        setCapturando(null)
      },
      { enableHighAccuracy: true, timeout: 10000 },
    )
  }

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
        actions={<Button variant="gold" onClick={() => { setEditando(undefined); setDialogOpen(true) }}><Plus size={15} /> Nueva tienda</Button>}
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
        <Button variant="gold" onClick={() => { setQuery(search); setPage(1) }}><Search size={14} /> Buscar</Button>
        {query && <Button variant="ghost" onClick={() => { setSearch(''); setQuery(''); setPage(1) }}>Limpiar</Button>}
      </ListToolbar>

      <div className="kyro-card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full border-separate border-spacing-0 text-sm">
            <thead>
              <tr>
                {['Código', 'Nombre', 'Dirección', 'Teléfono', 'Estado', 'Ubicación', 'Acciones'].map(h => (
                  <th key={h} className="kyro-table-head px-4 py-3 text-left">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-kyro-border">
              {isLoading && <tr><td colSpan={7} className="px-4 py-10 text-center text-kyro-muted">Cargando...</td></tr>}
              {!isLoading && tiendas.length === 0 && <tr><td colSpan={7} className="px-4 py-10 text-center text-kyro-muted">Sin tiendas registradas</td></tr>}
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
                    {(() => {
                      const tieneGps = t.latitud != null && t.longitud != null
                      return (
                        <button
                          onClick={() => capturarUbicacion(t)}
                          disabled={capturando === t.id}
                          className={
                            'inline-flex h-7 items-center gap-1.5 rounded-lg border px-2.5 text-xs font-medium transition-all disabled:opacity-40 disabled:pointer-events-none ' +
                            (tieneGps
                              ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 hover:bg-emerald-500/20 dark:text-emerald-400'
                              : 'border-red-500/30 bg-red-500/10 text-red-600 hover:bg-red-500/20 dark:text-red-400')
                          }
                          title={tieneGps ? `Ubicación: ${t.latitud}, ${t.longitud} — actualizar` : 'Añadir ubicación'}
                        >
                          <LocateFixed size={13} className={capturando === t.id ? 'animate-pulse' : ''} />
                          {tieneGps ? 'GPS activo' : 'Sin GPS'}
                        </button>
                      )
                    })()}
                  </td>
                  <td className="px-4 py-3">
                    <TableActions>
                      <ActionIconButton tone="edit" label="Editar tienda" icon={<Pencil size={15} />} onClick={() => { setEditando(t); setDialogOpen(true) }} />
                      <ActionIconButton tone="delete" label="Eliminar tienda" icon={<Trash2 size={15} />} disabled={eliminar.isPending} onClick={() => { if (confirm(`¿Eliminar tienda ${t.nombre}?`)) eliminar.mutate(t.id) }} />
                    </TableActions>
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
