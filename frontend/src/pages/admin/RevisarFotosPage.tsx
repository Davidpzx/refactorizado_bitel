import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { adminPaginasApi, type FotoPendienteItem } from '../../services/adminPaginas.api'
import { PageHeader } from '../../components/PageHeader'
import { Card } from '../../components/ui/card'
import { Button } from '../../components/ui/button'

function srcFoto(foto: string): string {
  return foto.startsWith('data:') ? foto : `data:image/jpeg;base64,${foto}`
}

export function RevisarFotosPage() {
  const qc = useQueryClient()
  const [zoom, setZoom] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['fotos-pendientes'],
    queryFn: () => adminPaginasApi.fotosPendientes(),
  })

  const accion = useMutation({
    mutationFn: ({ id, accion }: { id: number; accion: 'aprobar' | 'rechazar' }) =>
      adminPaginasApi.photoAction(id, accion),
    onSettled: () => {
      qc.invalidateQueries({ queryKey: ['fotos-pendientes'] })
      qc.invalidateQueries({ queryKey: ['control-center'] })
    },
  })

  const items = data?.data ?? []

  return (
    <div className="max-w-6xl mx-auto space-y-6">
      <PageHeader
        title="Revisar Fotos de Asistencia"
        description="Audita las marcaciones del Protocolo de Local Cerrado. Aprobar elimina la foto (zero-retention)."
      />

      {isLoading ? (
        <Card className="kyro-card p-6"><p className="text-sm text-kyro-muted">Cargando…</p></Card>
      ) : items.length === 0 ? (
        <Card className="kyro-card p-6"><p className="text-sm text-kyro-success">✓ No hay fotos pendientes de revisión.</p></Card>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {items.map((f: FotoPendienteItem) => {
            const tieneGps = f.lat_entrada != null && f.lng_entrada != null
            const busy = accion.isPending && accion.variables?.id === f.id
            return (
              <Card key={f.id} className="kyro-card flex flex-col overflow-hidden">
                <button type="button" onClick={() => setZoom(srcFoto(f.foto_marcacion))} className="relative aspect-square bg-kyro-base">
                  <img src={srcFoto(f.foto_marcacion)} alt={f.nombres} className="w-full h-full object-cover" />
                  <span className="absolute left-2 top-2 rounded-full bg-kyro-gold px-2 py-0.5 text-[10px] font-bold text-kyro-gold-ink">
                    {f.metodo_marcacion ?? 'FOTO'}
                  </span>
                </button>
                <div className="p-3 flex-1 flex flex-col gap-1">
                  <p className="text-sm font-bold text-kyro-text">{f.nombres}</p>
                  <p className="text-xs text-kyro-muted">{f.tienda_base ?? '—'} · {f.fecha} {f.hora_ingreso ?? ''}</p>
                  {tieneGps ? (
                    <a href={`https://www.google.com/maps?q=${f.lat_entrada},${f.lng_entrada}`} target="_blank" rel="noreferrer"
                      className="text-xs text-kyro-info hover:underline">
                      📍 Ver en mapa{f.accuracy_entrada != null ? ` (±${Math.round(f.accuracy_entrada)}m)` : ''}
                    </a>
                  ) : (
                    <span className="text-xs text-kyro-subtle">Sin coordenadas GPS</span>
                  )}
                  <div className="flex gap-2 mt-2">
                    <Button type="button" variant="gold" size="sm" disabled={busy} className="flex-1"
                      onClick={() => accion.mutate({ id: f.id, accion: 'aprobar' })}>
                      Aprobar
                    </Button>
                    <Button type="button" size="sm" variant="glassDanger" disabled={busy}
                      className="flex-1"
                      onClick={() => accion.mutate({ id: f.id, accion: 'rechazar' })}>
                      Anular
                    </Button>
                  </div>
                </div>
              </Card>
            )
          })}
        </div>
      )}

      {zoom && (
        <div className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4" onClick={() => setZoom(null)}>
          <img src={zoom} alt="Marcación" className="max-h-full max-w-full rounded-kyro-lg border border-kyro-border" />
        </div>
      )}
    </div>
  )
}
