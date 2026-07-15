import { useRef, useState } from 'react'
import { Trash } from '@phosphor-icons/react'
import {
  useBuscarProductosInventario,
  useEliminarFotoProducto,
  useFotosProducto,
  useGuardarFotoProducto,
  useGuardarPromocion,
  usePromocion,
} from '../../hooks/useWhatsApp'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'

function PromocionForm() {
  const { data: promocion } = usePromocion()
  const guardar = useGuardarPromocion()
  const [texto, setTexto] = useState(promocion?.texto ?? '')
  const [foto, setFoto] = useState<File | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)

  const preview = foto ? URL.createObjectURL(foto) : promocion?.foto_base64

  const handleGuardar = () => {
    if (!texto.trim()) return
    guardar.mutate(
      { texto: texto.trim(), foto: foto ?? undefined },
      { onSuccess: () => { setFoto(null); if (fileRef.current) fileRef.current.value = '' } }
    )
  }

  return (
    <div className="kyro-card space-y-3 p-4">
      <h3 className="text-sm font-semibold">Promoción vigente</h3>
      {preview && <img src={preview} alt="Promoción" className="max-h-56 w-full rounded-kyro object-cover" />}
      <textarea
        value={texto || promocion?.texto || ''}
        onChange={e => setTexto(e.target.value)}
        rows={4}
        placeholder="Texto de la promoción vigente..."
        className="w-full rounded-kyro border border-kyro-border bg-transparent p-2 text-sm"
      />
      <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp" onChange={e => setFoto(e.target.files?.[0] ?? null)} />
      <Button variant="gold" size="sm" disabled={guardar.isPending} onClick={handleGuardar}>
        {guardar.isPending ? 'Guardando...' : 'Guardar promoción'}
      </Button>
    </div>
  )
}

function FotosProductoPanel() {
  const { data: fotos = [] } = useFotosProducto()
  const guardar = useGuardarFotoProducto()
  const eliminar = useEliminarFotoProducto()
  const [nombre, setNombre] = useState('')
  const [foto, setFoto] = useState<File | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)
  const { data: sugerencias = [] } = useBuscarProductosInventario(nombre)

  const handleSubir = () => {
    if (!nombre.trim() || !foto) return
    guardar.mutate(
      { productoNombre: nombre.trim(), foto },
      { onSuccess: () => { setNombre(''); setFoto(null); if (fileRef.current) fileRef.current.value = '' } }
    )
  }

  return (
    <div className="kyro-card space-y-3 p-4">
      <h3 className="text-sm font-semibold">Fotos de equipos</h3>
      <div className="relative">
        <Input value={nombre} onChange={e => setNombre(e.target.value)} placeholder="Nombre del producto (ej. iPhone 13 128GB)" />
        {sugerencias.length > 0 && nombre.length >= 2 && (
          <div className="absolute z-10 mt-1 w-full rounded-kyro border border-kyro-border bg-kyro-elevated shadow-lg">
            {sugerencias.map(s => (
              <button key={s} type="button" onClick={() => setNombre(s)} className="block w-full px-3 py-1.5 text-left text-xs hover:bg-kyro-border/40">
                {s}
              </button>
            ))}
          </div>
        )}
      </div>
      <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp" onChange={e => setFoto(e.target.files?.[0] ?? null)} />
      <Button variant="gold" size="sm" disabled={guardar.isPending} onClick={handleSubir}>
        {guardar.isPending ? 'Subiendo...' : 'Subir / reemplazar foto'}
      </Button>

      <div className="max-h-72 space-y-1 overflow-y-auto">
        {fotos.map(f => (
          <div key={f.id} className="flex items-center justify-between rounded-kyro border border-kyro-border px-2 py-1.5">
            <div className="flex items-center gap-2 min-w-0">
              <img src={f.foto_base64} alt={f.producto_nombre} className="h-9 w-9 rounded object-cover" />
              <span className="truncate text-xs">{f.producto_nombre}</span>
            </div>
            <button
              type="button"
              onClick={() => { if (confirm(`Eliminar la foto de "${f.producto_nombre}"?`)) eliminar.mutate(f.id) }}
              className="text-kyro-muted hover:text-red-400"
            >
              <Trash size={14} />
            </button>
          </div>
        ))}
        {fotos.length === 0 && <p className="py-4 text-center text-xs text-kyro-muted">Sin fotos todavía.</p>}
      </div>
    </div>
  )
}

export function CrmContenidoBotTab() {
  return (
    <div className="grid gap-4 md:grid-cols-2">
      <PromocionForm />
      <FotosProductoPanel />
    </div>
  )
}
