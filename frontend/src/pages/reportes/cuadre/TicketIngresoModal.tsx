import { useEffect, useMemo, useState } from 'react'
import { api } from '../../../services/api'
import { Input } from '../../../components/ui/input'
import { Label } from '../../../components/ui/label'
import { Button } from '../../../components/ui/button'
import { GlassPanel } from '../../../components/ui/GlassPanel'

interface ItemRow { descripcion: string; monto: number }

export function TicketIngresoModal({
  open, onClose, defaultDescripcion, tiendaId, agenteId, vendedor,
}: {
  open: boolean
  onClose: () => void
  defaultDescripcion: string
  tiendaId: string
  agenteId: number
  vendedor: string
}) {
  const [items, setItems]       = useState<ItemRow[]>([{ descripcion: defaultDescripcion, monto: 0 }])
  const [efectivo, setEfectivo] = useState(0)
  const [yape, setYape]         = useState(0)
  const [bipay, setBipay]       = useState(0)
  const [plin, setPlin]         = useState(0)
  const [nombre, setNombre]     = useState('')
  const [dni, setDni]           = useState('')
  const [telefono, setTelefono] = useState('')
  const [guardando, setGuardando] = useState(false)
  const [error, setError]       = useState('')

  const total    = useMemo(() => items.reduce((a, it) => a + (Number(it.monto) || 0), 0), [items])
  const recibido = efectivo + yape + bipay + plin
  const vuelto   = recibido - total

  useEffect(() => {
    if (open) {
      setItems([{ descripcion: defaultDescripcion, monto: 0 }])
      setEfectivo(0)
      setYape(0)
      setBipay(0)
      setPlin(0)
      setNombre('')
      setDni('')
      setTelefono('')
      setError('')
    }
  }, [open, defaultDescripcion])

  if (!open) return null

  const setItem = (i: number, campo: keyof ItemRow, val: string | number) =>
    setItems(prev => prev.map((it, idx) => idx === i ? { ...it, [campo]: val } : it))

  async function guardar() {
    if (total <= 0) { setError('Agrega al menos un ítem con monto.'); return }
    setGuardando(true); setError('')
    try {
      const payload = {
        tienda_id: tiendaId, agente_id: agenteId, vendedor,
        items: items.map(it => ({ descripcion: it.descripcion, monto: Number(it.monto) || 0 })),
        descripcion: items.map(it => it.descripcion).filter(Boolean).join(' + '),
        monto: total, cantidad: items.length,
        efectivo, yape, bipay, plin,
        nombre_cliente: nombre, dni_cliente: dni, telefono,
      }
      const { id } = await api.post<{ ok: boolean; id: number }>('/v1/tickets', payload).then(r => r.data)
      window.open(`/tickets/imprimir/${id}`, '_blank', 'width=380,height=640')
      onClose()
    } catch {
      setError('No se pudo generar el ticket.')
    } finally {
      setGuardando(false)
    }
  }

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/60" onClick={onClose} />
      <GlassPanel className="kyro-card !bg-kyro-panel !border-kyro-border !shadow-kyro-card relative z-10 w-full max-w-md p-4 max-h-[90vh] overflow-auto">
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-sm font-bold text-kyro-info">Ticket de Ingreso</h3>
          <button type="button" onClick={onClose} className="text-kyro-muted hover:text-kyro-text rounded-kyro text-lg leading-none">×</button>
        </div>

        <div className="space-y-2">
          {items.map((it, i) => (
            <div key={i} className="grid grid-cols-[1fr_100px_auto] gap-1.5 items-end">
              <Input value={it.descripcion} onChange={e => setItem(i, 'descripcion', e.target.value)} placeholder="Descripción" className="kyro-input h-8 text-xs" />
              <Input type="number" step="0.01" min="0" value={it.monto || ''} onChange={e => setItem(i, 'monto', parseFloat(e.target.value) || 0)} placeholder="S/" className="kyro-input h-8 text-xs text-right" />
              <button type="button" onClick={() => setItems(prev => prev.filter((_, idx) => idx !== i))} className="text-kyro-danger hover:bg-kyro-danger/10 rounded-kyro font-bold text-lg leading-none">×</button>
            </div>
          ))}
          <button type="button" onClick={() => setItems(prev => [...prev, { descripcion: '', monto: 0 }])} className="text-xs font-medium text-kyro-info hover:text-kyro-gold">+ Agregar ítem</button>
        </div>

        <div className="grid grid-cols-2 gap-2 mt-3 pt-3 border-t border-kyro-border">
          {([['Efectivo', efectivo, setEfectivo], ['Yape', yape, setYape], ['Bipay', bipay, setBipay], ['Plin', plin, setPlin]] as const).map(([label, val, set]) => (
            <div key={label}>
              <Label className="text-[10px] text-kyro-muted">{label}</Label>
              <Input type="number" step="0.01" min="0" value={val || ''} onChange={e => set(parseFloat(e.target.value) || 0)} className="kyro-input h-7 text-xs text-right" placeholder="0.00" />
            </div>
          ))}
        </div>

        <div className="grid grid-cols-3 gap-2 mt-3 text-center text-xs">
          <div><div className="text-kyro-muted text-[10px]">Total</div><div className="font-semibold text-kyro-body">S/ {total.toFixed(2)}</div></div>
          <div><div className="text-kyro-muted text-[10px]">Recibido</div><div className="font-semibold text-kyro-body">S/ {recibido.toFixed(2)}</div></div>
          <div><div className="text-kyro-muted text-[10px]">Vuelto</div><div className="font-semibold" style={{ color: vuelto < 0 ? 'var(--color-kyro-danger)' : 'var(--color-kyro-success)' }}>S/ {vuelto.toFixed(2)}</div></div>
        </div>

        <div className="grid grid-cols-3 gap-2 mt-3">
          <Input value={nombre} onChange={e => setNombre(e.target.value)} placeholder="Cliente" className="kyro-input h-7 text-xs" />
          <Input value={dni} onChange={e => setDni(e.target.value)} placeholder="DNI" className="kyro-input h-7 text-xs" />
          <Input value={telefono} onChange={e => setTelefono(e.target.value)} placeholder="Teléfono" className="kyro-input h-7 text-xs" />
        </div>

        {error && <p className="text-kyro-danger text-xs mt-2">{error}</p>}

        <div className="flex gap-2 mt-4">
          <Button type="button" onClick={guardar} disabled={guardando} class