import { useRef } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../../services/api'

interface Docs {
  foto_perfil: string | null
  foto_dni: string | null
}

/**
 * Foto de perfil y foto/PDF del DNI del agente, guardados como base64 en BD.
 * Puerto del bloque de documentos de ver_agente.php (ajax_subir_doc_agente) del legacy.
 */
export function DocumentosAgentePanel({ agenteId }: { agenteId: number }) {
  const qc = useQueryClient()
  const inputPerfil = useRef<HTMLInputElement>(null)
  const inputDni    = useRef<HTMLInputElement>(null)

  const { data } = useQuery({
    queryKey: ['agente-documentos', agenteId],
    queryFn:  () => api.get<Docs>(`/v1/agentes/${agenteId}/documentos`).then((r) => r.data),
  })

  const subir = useMutation({
    mutationFn: ({ campo, archivo }: { campo: 'foto_perfil' | 'foto_dni'; archivo: File }) => {
      const fd = new FormData()
      fd.append('campo', campo)
      fd.append('archivo', archivo)
      return api.post(`/v1/agentes/${agenteId}/documentos`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['agente-documentos', agenteId] }),
  })

  const eliminar = useMutation({
    mutationFn: (campo: string) => api.delete(`/v1/agentes/${agenteId}/documentos/${campo}`),
    onSuccess:  () => qc.invalidateQueries({ queryKey: ['agente-documentos', agenteId] }),
  })

  const onFile = (campo: 'foto_perfil' | 'foto_dni') => (e: React.ChangeEvent<HTMLInputElement>) => {
    const archivo = e.target.files?.[0]
    if (archivo) subir.mutate({ campo, archivo })
    e.target.value = ''
  }

  const bloque = (campo: 'foto_perfil' | 'foto_dni', titulo: string, ref: React.RefObject<HTMLInputElement | null>, accept: string) => {
    const valor = data?.[campo] ?? null
    const esPdf = valor?.startsWith('data:application/pdf') ?? false
    return (
      <div className="flex-1">
        <h4 className="mb-2 text-[0.65rem] font-bold uppercase tracking-wider text-kyro-muted">{titulo}</h4>
        {valor ? (
          esPdf ? (
            <a href={valor} download={`${campo}_${agenteId}.pdf`} className="text-xs font-semibold text-kyro-gold">
              Descargar PDF del DNI
            </a>
          ) : (
            <img src={valor} alt={titulo} className="max-h-36 rounded-lg border border-white/10 object-cover" />
          )
        ) : (
          <p className="text-xs text-kyro-muted">Sin documento.</p>
        )}
        <div className="mt-2 flex gap-2">
          <button onClick={() => ref.current?.click()} disabled={subir.isPending}
            className="rounded border border-kyro-gold/40 bg-kyro-gold/10 px-2.5 py-1 text-[0.68rem] font-semibold text-kyro-gold disabled:opacity-50">
            {subir.isPending ? 'Subiendo...' : valor ? 'Reemplazar' : 'Subir'}
          </button>
          {valor && (
            <button onClick={() => eliminar.mutate(campo)}
              className="rounded border border-kyro-danger/40 px-2.5 py-1 text-[0.68rem] font-semibold text-kyro-danger">
              Quitar
            </button>
          )}
        </div>
        <input ref={ref} type="file" accept={accept} className="hidden" onChange={onFile(campo)} />
      </div>
    )
  }

  return (
    <div className="kyro-card p-4">
      <h3 className="mb-3 text-xs font-bold uppercase tracking-wider text-kyro-body">Documentos del agente</h3>
      <div className="flex flex-col gap-4 sm:flex-row">
        {bloque('foto_perfil', 'Foto de perfil', inputPerfil, 'image/jpeg,image/png,image/webp')}
        {bloque('foto_dni', 'Foto / PDF del DNI', inputDni, 'image/jpeg,image/png,image/webp,application/pdf')}
      </div>
    </div>
  )
}
