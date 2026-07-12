import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { DeviceMobile, DownloadSimple, Link as LinkIcon, Check, UploadSimple } from '@phosphor-icons/react'
import { adminPaginasApi } from '../../services/adminPaginas.api'
import { useAuth } from '../../hooks/useAuth'
import { Card } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'

function formatearTamano(bytes: number | null | undefined): string {
  if (!bytes) return ''
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

/**
 * APP-09a — canal de distribución del APK de la app de asistencia.
 * Vive en Presencia (misma pestaña que la app nativa alimenta con sus pings)
 * para que el admin encuentre el enlace de WhatsApp junto al monitoreo en vivo.
 */
export function AppTerminalDescarga() {
  const { usuario } = useAuth()
  const esAdmin = usuario?.rol === 'admin'
  const qc = useQueryClient()
  const fileRef = useRef<HTMLInputElement>(null)
  const [copiado, setCopiado] = useState(false)
  const [mostrarUploader, setMostrarUploader] = useState(false)
  const [versionInput, setVersionInput] = useState('')
  const [archivo, setArchivo] = useState<File | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['app-terminal-version'],
    queryFn: () => adminPaginasApi.appTerminalVersion(),
    staleTime: 30_000,
  })

  const subirMutation = useMutation({
    mutationFn: () => adminPaginasApi.appTerminalSubir(archivo as File, versionInput.trim()),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['app-terminal-version'] })
      setMostrarUploader(false)
      setArchivo(null)
      setVersionInput('')
      if (fileRef.current) fileRef.current.value = ''
    },
  })

  const copiarEnlace = async () => {
    if (!data?.url_descarga) return
    await navigator.clipboard.writeText(data.url_descarga)
    setCopiado(true)
    setTimeout(() => setCopiado(false), 2500)
  }

  if (isLoading) return null

  const disponible = data?.disponible ?? false

  return (
    <Card className="kyro-card p-5">
      <div className="flex items-center gap-2.5 mb-3">
        <span className="flex h-8 w-8 items-center justify-center rounded-kyro bg-kyro-indigo/15 text-kyro-indigo">
          <DeviceMobile size={18} weight="fill" />
        </span>
        <div>
          <h2 className="text-[0.78rem] font-bold uppercase tracking-[0.12em] text-kyro-text">
            Descargar app de asistencia
          </h2>
          {disponible && (
            <p className="text-xs text-kyro-muted">
              Versión {data?.version}{data?.tamano_bytes ? ` · ${formatearTamano(data.tamano_bytes)}` : ''}
            </p>
          )}
        </div>
      </div>

      {disponible ? (
        <div className="flex flex-wrap items-center gap-2">
          {/* Sin target=_blank: el endpoint responde Content-Disposition attachment —
              la descarga arranca en la misma pestaña sin dejar una pestaña vacía. */}
          <a href={data?.url_descarga} download>
            <Button type="button" variant="default" size="sm">
              <DownloadSimple size={14} className="mr-1.5" /> Descargar APK
            </Button>
          </a>
          <Button type="button" variant="outline" size="sm" onClick={copiarEnlace}>
            {copiado ? <Check size={14} className="mr-1.5 text-kyro-success" /> : <LinkIcon size={14} className="mr-1.5" />}
            {copiado ? 'Enlace copiado' : 'Copiar enlace'}
          </Button>
          {esAdmin && (
            <Button type="button" variant="ghost" size="sm" onClick={() => setMostrarUploader((v) => !v)}>
              <UploadSimple size={14} className="mr-1.5" /> Subir nueva versión
            </Button>
          )}
        </div>
      ) : (
        <p className="text-sm text-kyro-muted">Aún no hay una versión publicada.</p>
      )}

      {esAdmin && (!disponible || mostrarUploader) && (
        <div className="mt-4 flex flex-wrap items-end gap-3 border-t border-kyro-border pt-4">
          <div>
            <label className="mb-1 block text-xs font-semibold text-kyro-muted">Versión</label>
            <Input
              value={versionInput}
              onChange={(e) => setVersionInput(e.target.value)}
              placeholder="1.0.0"
              className="w-28"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-semibold text-kyro-muted">Archivo .apk</label>
            <input
              ref={fileRef}
              type="file"
              accept=".apk"
              onChange={(e) => setArchivo(e.target.files?.[0] ?? null)}
              className="block text-sm text-kyro-muted file:mr-3 file:rounded-kyro file:border-0 file:bg-kyro-indigo/10 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-kyro-indigo"
            />
          </div>
          <Button
            type="button"
            size="sm"
            disabled={!archivo || !versionInput.trim() || subirMutation.isPending}
            onClick={() => subirMutation.mutate()}
          >
            {subirMutation.isPending ? 'Subiendo…' : 'Publicar'}
          </Button>
          {subirMutation.isError && (
            <p className="w-full text-xs text-kyro-danger">No se pudo subir el APK. Verifica el archivo y la versión.</p>
          )}
        </div>
      )}
    </Card>
  )
}
