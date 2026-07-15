import { useState } from 'react'
import { PencilSimple, Trash } from '@phosphor-icons/react'
import type { WhatsAppBotRegla } from '../../../types/whatsapp'
import { useBotReglas, useEliminarBotRegla, useGuardarBotRegla } from '../../../hooks/useWhatsApp'
import { Dialog } from '../../../components/ui/dialog'
import { Button } from '../../../components/ui/button'
import { Input } from '../../../components/ui/input'

export function BotReglasModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { data: reglas = [] } = useBotReglas(open)
  const guardar = useGuardarBotRegla()
  const eliminar = useEliminarBotRegla()

  const [editando, setEditando] = useState<Partial<WhatsAppBotRegla> | null>(null)

  const handleGuardar = () => {
    if (!editando?.nombre) return
    guardar.mutate(
      {
        id: editando.id,
        nombre: editando.nombre,
        tipo: (editando.tipo ?? 'texto') as 'texto' | 'menu',
        palabras_clave: editando.palabras_clave ?? [],
        respuesta: editando.respuesta ?? '',
        prioridad: editando.prioridad ?? 10,
        activa: editando.activa ?? true,
      },
      { onSuccess: () => setEditando(null) }
    )
  }

  return (
    <Dialog open={open} onClose={onClose} title="Reglas del bot" maxWidth="lg">
      <div className="space-y-3">
        <div className="flex justify-end">
          <Button variant="gold" size="sm" onClick={() => setEditando({ tipo: 'texto', activa: true })}>+ Nueva regla</Button>
        </div>

        <div className="max-h-72 space-y-1 overflow-y-auto">
          {reglas.map(r => (
            <div key={r.id} className="flex items-center justify-between rounded-kyro border border-kyro-border px-3 py-2 text-sm">
              <div className="min-w-0">
                <span className="font-medium">{r.nombre}</span>
                <span className="ml-2 rounded bg-kyro-indigo/15 px-1.5 text-[10px] text-kyro-indigo">{r.tipo}</span>
                {r.es_bienvenida && <span className="ml-1 rounded bg-amber-400/15 px-1.5 text-[10px] text-amber-400">bienvenida</span>}
                {!r.activa && <span className="ml-1 rounded bg-kyro-border px-1.5 text-[10px] text-kyro-muted">inactiva</span>}
                <p className="truncate text-xs text-kyro-muted">
                  {r.tipo === 'menu' ? r.menu_titulo : (r.palabras_clave ?? []).join(', ')}
                </p>
              </div>
              <div className="flex shrink-0 gap-2">
                <button type="button" onClick={() => setEditando(r)} className="text-kyro-muted hover:text-amber-400"><PencilSimple size={15} /></button>
                <button
                  type="button"
                  onClick={() => { if (confirm(`Eliminar la regla "${r.nombre}"?`)) eliminar.mutate(r.id) }}
                  className="text-kyro-muted hover:text-red-400"
                >
                  <Trash size={15} />
                </button>
              </div>
            </div>
          ))}
          {reglas.length === 0 && <p className="py-6 text-center text-xs text-kyro-muted">Sin reglas todavía.</p>}
        </div>

        {editando && (
          <div className="space-y-2 rounded-kyro border border-kyro-border p-3">
            <Input
              value={editando.nombre ?? ''}
              onChange={e => setEditando({ ...editando, nombre: e.target.value })}
              placeholder="Nombre de la regla"
            />
            <Input
              value={(editando.palabras_clave ?? []).join(', ')}
              onChange={e => setEditando({ ...editando, palabras_clave: e.target.value.split(',').map(s => s.trim()).filter(Boolean) })}
              placeholder="Palabras clave separadas por coma"
            />
            <textarea
              value={editando.respuesta ?? ''}
              onChange={e => setEditando({ ...editando, respuesta: e.target.value })}
              placeholder="Respuesta del bot"
              rows={3}
              className="w-full rounded-kyro border border-kyro-border bg-transparent p-2 text-sm"
            />
            <div className="flex justify-end gap-2">
              <Button variant="outline" size="sm" onClick={() => setEditando(null)}>Cancelar</Button>
              <Button variant="gold" size="sm" disabled={!editando.nombre || guardar.isPending} onClick={handleGuardar}>
                {guardar.isPending ? 'Guardando...' : 'Guardar'}
              </Button>
            </div>
          </div>
        )}
      </div>
    </Dialog>
  )
}
